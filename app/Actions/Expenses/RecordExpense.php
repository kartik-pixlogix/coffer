<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Actions\Categories\SaveCategory;
use App\Enums\CategoryKind;
use App\Enums\ExpenseStatus;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Exceptions\PeriodLockedException;
use App\Ledger\LedgerPoster;
use App\Ledger\Postings;
use App\Models\Category;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpensePayer;
use App\Models\JournalEntry;
use App\Models\User;
use App\Notifications\Events\ExpenseDecided;
use App\Notifications\Events\ExpenseSubmitted;
use App\Services\Finance\Attachments;
use App\Services\Finance\PeriodGuard;
use App\Services\Notifications\Audience;
use App\Support\Permissions\Catalog;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Expenses (spec §10, §4.2, §4.3). Submitting saves a pending expense with its payer
 * lines; nothing posts until it's approved. Approval posts one entry through
 * Postings::expense (a pair of lines per payer: from a money account, a partner's card
 * into their current account, a member's card into reimbursements payable). Rejecting
 * keeps nothing posted and stores the reason. Editing an approved expense reverses its
 * entry and returns it to Pending; deleting one reverses it. Closed years are locked.
 */
final class RecordExpense
{
    public function __construct(
        private readonly Postings $postings,
        private readonly LedgerPoster $poster,
        private readonly PeriodGuard $periods,
        private readonly Attachments $attachments,
        private readonly SaveCategory $categories,
        private readonly Audience $audience,
    ) {}

    public function submit(Company $company, ExpenseData $data, User $by): Expense
    {
        $this->periods->assertOpen($company, [$data->spentOn], 'spent_on');
        $file = $data->receipt !== null ? $this->attachments->store($company, 'expenses', $data->receipt) : null;

        $expense = $this->cleaningUp($file, fn () => DB::transaction(function () use ($company, $data, $by, $file): Expense {
            /** @var Expense $expense */
            $expense = Expense::query()->create($this->attributes($data) + [
                'company_id' => $company->id,
                'status' => ExpenseStatus::Pending,
                'submitted_by' => $by->id,
                'auto' => false,
                ...$this->fileAttributes($file),
            ]);

            $this->savePayers($company, $expense, $data);

            return $expense;
        }));

        $this->askApprovers($company, $expense, $by);

        return $expense;
    }

    /** @return bool whether an approved expense went back to Pending */
    public function update(Company $company, Expense $expense, ExpenseData $data, User $by): bool
    {
        $this->periods->assertOpen($company, [$expense->spent_on, $data->spentOn], 'spent_on');
        $file = $data->receipt !== null ? $this->attachments->store($company, 'expenses', $data->receipt) : null;
        $oldPath = $expense->receipt_path;
        $wasApproved = $expense->isApproved();

        $this->cleaningUp($file, fn () => $this->periods->run(fn () => DB::transaction(function () use ($company, $expense, $data, $by, $file): void {
            if ($expense->isApproved()) {
                $this->reverse($expense, 'Expense edited — '.$expense->description, $by);
            }

            $expense->fill($this->attributes($data) + [
                'status' => ExpenseStatus::Pending,
                'reject_reason' => null,
                'decided_by' => null,
                'decided_at' => null,
                'journal_entry_id' => null,
            ]);

            if ($file !== null || $data->removeReceipt) {
                $expense->fill($this->fileAttributes($file));
            }

            $expense->save();
            $this->savePayers($company, $expense, $data);
        }), 'spent_on'));

        if (($file !== null || $data->removeReceipt) && $oldPath !== null) {
            $this->attachments->delete($oldPath);
        }

        $this->askApprovers($company, $expense, $by);

        return $wasApproved;
    }

    public function approve(Company $company, Expense $expense, User $by): void
    {
        $this->periods->assertOpen($company, [$expense->spent_on], 'expense');

        $this->periods->run(fn () => DB::transaction(function () use ($company, $expense, $by): void {
            /** @var Expense $locked */
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['expense' => "“{$locked->description}” isn’t waiting for approval any more."]);
            }

            /** @var Category $category */
            $category = Category::query()->findOrFail($locked->category_id);
            $lines = array_values($locked->payers()->get()->map(fn (ExpensePayer $p) => $p->toPayerLine())->all());

            try {
                $entry = $this->postings->expense($company, $locked->spent_on, $category, $lines, $locked->project_id, $locked->description, $locked, $by);
            } catch (PeriodLockedException $e) {
                throw $e;
            } catch (LedgerException $e) {
                throw ValidationException::withMessages(['expense' => $e->getMessage()]);
            }

            $locked->update([
                'status' => ExpenseStatus::Approved,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'reject_reason' => null,
                'journal_entry_id' => $entry->id,
            ]);

            $expense->setRawAttributes($locked->getAttributes(), true);
        }), 'expense');

        $this->tellSubmitter($expense, $by);
    }

    public function reject(Expense $expense, string $reason, User $by): void
    {
        DB::transaction(function () use ($expense, $reason, $by): void {
            /** @var Expense $locked */
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['expense' => "“{$locked->description}” isn’t waiting for approval any more."]);
            }

            $locked->update([
                'status' => ExpenseStatus::Rejected,
                'reject_reason' => $reason,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);

            $expense->setRawAttributes($locked->getAttributes(), true);
        });

        $this->tellSubmitter($expense, $by);
    }

    public function delete(Company $company, Expense $expense, User $by): void
    {
        $this->periods->assertOpen($company, [$expense->spent_on], 'expense');

        $this->periods->run(fn () => DB::transaction(function () use ($expense, $by): void {
            if ($expense->isApproved()) {
                $this->reverse($expense, 'Expense deleted — '.$expense->description, $by);
            }

            $expense->delete();
        }), 'expense');
    }

    /** @return array<string, mixed> */
    private function attributes(ExpenseData $data): array
    {
        $categoryId = $data->newCategory !== null
            ? $this->categories->create(CategoryKind::Expense, $data->newCategory, 'new_category')->id
            : $data->categoryId;

        return [
            'description' => $data->description,
            'amount_paise' => $data->amountPaise,
            'spent_on' => $data->spentOn->toDateString(),
            'category_id' => $categoryId,
            'project_id' => $data->projectId,
            'billable' => $data->billable,
            'notes' => $data->notes,
            'payer_mode' => $data->mode,
        ];
    }

    private function savePayers(Company $company, Expense $expense, ExpenseData $data): void
    {
        ExpensePayer::query()->where('expense_id', $expense->id)->get()->each->delete();

        foreach ($data->payers as $i => $payer) {
            ExpensePayer::query()->create([
                'company_id' => $company->id,
                'expense_id' => $expense->id,
                'position' => $i,
                'payer_type' => $payer->type,
                'partner_id' => $payer->partnerId,
                'member_id' => $payer->memberId,
                'money_account_id' => $payer->accountId,
                'amount_paise' => $payer->amountPaise,
            ]);
        }

        $expense->unsetRelation('payers');
    }

    private function reverse(Expense $expense, string $memo, User $by): void
    {
        $entry = $expense->journal_entry_id !== null ? JournalEntry::query()->find($expense->journal_entry_id) : null;

        if ($entry !== null && ! $entry->isReversed()) {
            $this->poster->reverse($entry, memo: $memo, by: $by);
        }
    }

    /**
     * @template T
     *
     * @param  array{path: string, name: string, mime: string, size: int}|null  $file
     * @param  Closure(): T  $callback
     * @return T
     */
    private function cleaningUp(?array $file, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            $this->attachments->delete($file['path'] ?? null);

            throw $e;
        }
    }

    /**
     * @param  array{path: string, name: string, mime: string, size: int}|null  $file
     * @return array<string, string|int|null>
     */
    private function fileAttributes(?array $file): array
    {
        return [
            'receipt_path' => $file['path'] ?? null,
            'receipt_name' => $file['name'] ?? null,
            'receipt_mime' => $file['mime'] ?? null,
            'receipt_size' => $file['size'] ?? null,
        ];
    }

    /** Spec §19: whoever can approve it hears about a pending expense (never the submitter). */
    private function askApprovers(Company $company, Expense $expense, User $by): void
    {
        $approvers = $this->audience->withPermission($company, Catalog::actionPermission('expenses.approve'), $by);

        if ($approvers->isNotEmpty()) {
            Notification::send($approvers, ExpenseSubmitted::for($expense->loadMissing('category'), $by->name));
        }
    }

    /** Spec §19: the submitter hears the decision, unless they made it. */
    private function tellSubmitter(Expense $expense, User $by): void
    {
        $submitter = $expense->submitter;

        if ($submitter !== null && $submitter->id !== $by->id) {
            $submitter->notify(ExpenseDecided::for($expense, $by->name));
        }
    }
}
