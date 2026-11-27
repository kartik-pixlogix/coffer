<?php

declare(strict_types=1);

namespace App\Actions\Income;

use App\Ledger\IncomeTax;
use App\Ledger\LedgerPoster;
use App\Ledger\Postings;
use App\Models\Category;
use App\Models\Company;
use App\Models\DocumentTax;
use App\Models\Income;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\User;
use App\Services\Finance\Attachments;
use App\Services\Finance\PeriodGuard;
use App\Services\Tax\TaxDocuments;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Income (spec §9, §4.2): posts immediately — Dr the money account (or the receiving
 * partner's current account), Cr Income:category, with "brought in by" on the income
 * line. An edit that changes anything posted reverses the entry and posts a new one; a
 * delete reverses it and soft-deletes the record. Closed fiscal years are locked.
 */
final class RecordIncome
{
    private const array POSTED = ['source', 'amount_paise', 'received_on', 'category_id', 'brought_in_by', 'money_account_id', 'received_by', 'project_id'];

    public function __construct(
        private readonly Postings $postings,
        private readonly LedgerPoster $poster,
        private readonly PeriodGuard $periods,
        private readonly Attachments $attachments,
        private readonly TaxDocuments $taxes,
    ) {}

    public function create(Company $company, IncomeData $data, User $by): Income
    {
        $this->periods->assertOpen($company, [$data->receivedOn], 'received_on');
        $file = $data->attachment !== null ? $this->attachments->store($company, 'income', $data->attachment) : null;

        return $this->cleaningUp($file, fn () => $this->periods->run(fn () => DB::transaction(function () use ($company, $data, $by, $file): Income {
            /** @var Income $income */
            $income = Income::query()->create($data->attributes() + [
                'company_id' => $company->id,
                'created_by' => $by->id,
                ...$this->fileAttributes($file),
            ]);

            $this->taxes->save($income, $data->tax);
            $income->update(['journal_entry_id' => $this->post($company, $income, $by)->id]);

            return $income;
        }), 'received_on'));
    }

    public function update(Company $company, Income $income, IncomeData $data, User $by): Income
    {
        $this->periods->assertOpen($company, [$income->received_on, $data->receivedOn], 'received_on');
        $file = $data->attachment !== null ? $this->attachments->store($company, 'income', $data->attachment) : null;
        $oldPath = $income->attachment_path;

        $this->cleaningUp($file, fn () => $this->periods->run(fn () => DB::transaction(function () use ($company, $income, $data, $by, $file): void {
            $income->fill($data->attributes());

            if ($file !== null || $data->removeAttachment) {
                $income->fill($this->fileAttributes($file));
            }

            $repost = $income->isDirty(self::POSTED) || $income->journal_entry_id === null || $this->taxChanged($income, $data->tax);
            $income->save();
            $this->taxes->save($income, $data->tax);

            if ($repost) {
                $this->reverse($income, 'Income edited — '.$income->source, $by);
                $income->update(['journal_entry_id' => $this->post($company, $income, $by)->id]);
            }
        }), 'received_on'));

        if (($file !== null || $data->removeAttachment) && $oldPath !== null) {
            $this->attachments->delete($oldPath);
        }

        return $income;
    }

    public function delete(Company $company, Income $income, User $by): void
    {
        $this->periods->assertOpen($company, [$income->received_on], 'received_on');

        $this->periods->run(fn () => DB::transaction(function () use ($income, $by): void {
            $this->reverse($income, 'Income deleted — '.$income->source, $by);
            $income->delete();
        }), 'received_on');
    }

    /**
     * A stored upload is removed again when saving fails.
     *
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

    private function post(Company $company, Income $income, User $by): JournalEntry
    {
        /** @var Category $category */
        $category = Category::query()->findOrFail($income->category_id);
        $broughtBy = $income->brought_in_by !== null ? Partner::query()->findOrFail($income->brought_in_by) : null;

        if ($income->money_account_id !== null) {
            /** @var MoneyAccount $into */
            $into = MoneyAccount::query()->findOrFail($income->money_account_id);

            return $this->postings->incomeToAccount($company, $income->received_on, $income->amount_paise, $category, $into, $broughtBy, $income->project_id, $income->source, $income, $by, $this->taxOf($income));
        }

        /** @var Partner $receivedBy */
        $receivedBy = Partner::query()->findOrFail($income->received_by);

        return $this->postings->incomeToPartner($company, $income->received_on, $income->amount_paise, $category, $receivedBy, $broughtBy, $income->project_id, $income->source, $income, $by, $this->taxOf($income));
    }

    private function taxOf(Income $income): ?IncomeTax
    {
        return TaxDocuments::incomeTax(DocumentTax::query()->where('taxable_type', $income->getMorphClass())->where('taxable_id', $income->id)->first());
    }

    /**
     * Whether the tax differs from what posted, so the entry must be reposted.
     *
     * @param  array<string, mixed>|null  $tax
     */
    private function taxChanged(Income $income, ?array $tax): bool
    {
        $old = DocumentTax::query()->where('taxable_type', $income->getMorphClass())->where('taxable_id', $income->id)->first();

        if ($old === null || $tax === null) {
            return ($old === null) !== ($tax === null);
        }

        foreach (['taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'tds_paise'] as $key) {
            if ((int) $old->getAttribute($key) !== (int) ($tax[$key] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    private function reverse(Income $income, string $memo, User $by): void
    {
        $entry = $income->journal_entry_id !== null ? JournalEntry::query()->find($income->journal_entry_id) : null;

        if ($entry !== null && ! $entry->isReversed()) {
            $this->poster->reverse($entry, memo: $memo, by: $by);
        }
    }

    /**
     * @param  array{path: string, name: string, mime: string, size: int}|null  $file
     * @return array<string, string|int|null>
     */
    private function fileAttributes(?array $file): array
    {
        return [
            'attachment_path' => $file['path'] ?? null,
            'attachment_name' => $file['name'] ?? null,
            'attachment_mime' => $file['mime'] ?? null,
            'attachment_size' => $file['size'] ?? null,
        ];
    }
}
