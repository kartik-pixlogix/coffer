<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Notifications\NotificationEvents;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The signed-in user's notifications in the current company (spec §19, D78): this
 * company's rows plus workspace-wide ones (billing), newest first, kept 30 days. Rows are
 * presented for the bell and the Notifications page, with inline actions worked out live —
 * an expense still waiting offers Approve / Reject to someone who may decide it; once
 * decided, the row says by whom.
 */
final class Inbox
{
    public function __construct(private readonly CurrentCompany $current) {}

    /** @return Builder<DatabaseNotification> */
    public function query(User $user, string $filter = 'all'): Builder
    {
        $company = $this->current->id();

        /** @var Builder<DatabaseNotification> $query */
        $query = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where(fn (Builder $q) => $q->where('company_id', $company)->orWhereNull('company_id'))
            ->where('created_at', '>=', now()->subDays((int) config('notifications.retention_days')));

        return match (true) {
            $filter === 'unread' => $query->whereNull('read_at'),
            array_key_exists($filter, NotificationEvents::CATEGORIES) => $query->where('category', $filter),
            default => $query,
        };
    }

    public function unread(User $user): int
    {
        return $this->query($user, 'unread')->count();
    }

    /** @return array<string, int> all, unread and each category */
    public function counts(User $user): array
    {
        $rows = $this->query($user)->toBase()
            ->selectRaw('category, count(*) as total, sum(case when read_at is null then 1 else 0 end) as unread')
            ->groupBy('category')
            ->get();

        $counts = ['all' => 0, 'unread' => 0] + array_fill_keys(array_keys(NotificationEvents::CATEGORIES), 0);

        foreach ($rows as $row) {
            $counts['all'] += (int) $row->total;
            $counts['unread'] += (int) $row->unread;

            if (is_string($row->category) && array_key_exists($row->category, $counts)) {
                $counts[$row->category] += (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $rows
     * @return list<array<string, mixed>>
     */
    public function present(Collection $rows, User $viewer): array
    {
        $expenseIds = $rows->map(fn (DatabaseNotification $n) => $this->subject($n))
            ->filter(fn (?array $s) => $s !== null && in_array($s['type'], ['expense', 'approved', 'rejected'], true))
            ->map(fn (array $s) => $s['id'])
            ->unique()
            ->values()
            ->all();
        $expenses = $expenseIds === [] ? new Collection : Expense::query()->with('decider:id,name')->whereKey($expenseIds)->get()->keyBy('id');
        $timezone = $viewer->timezone;

        return array_values($rows->map(function (DatabaseNotification $n) use ($expenses, $viewer, $timezone): array {
            /** @var array<string, mixed> $data */
            $data = $n->data;
            $subject = $this->subject($n);
            $expense = $subject !== null && $subject['type'] === 'expense' ? $expenses->get($subject['id']) : null;

            return [
                'id' => $n->id,
                'title' => (string) ($data['title'] ?? ''),
                'body' => (string) ($data['body'] ?? ''),
                'url' => (string) ($data['url'] ?? ''),
                'icon' => (string) ($data['icon'] ?? 'bell'),
                'tone' => (string) ($data['tone'] ?? 'plain'),
                'link' => (string) ($data['link'] ?? 'Open'),
                'kind' => (string) ($data['kind'] ?? ''),
                'category' => (string) ($n->getAttribute('category') ?? $data['category'] ?? 'workspace'),
                'unread' => $n->read_at === null,
                'when' => self::when(CarbonImmutable::instance($n->created_at ?? now())->setTimezone($timezone)),
                'createdAt' => $n->created_at?->toIso8601String(),
                ...$this->expenseState($expense instanceof Expense ? $expense : null, $viewer),
            ];
        })->all());
    }

    /**
     * Approve / Reject while the expense waits and the viewer may decide it; afterwards,
     * "Approved by you" (Notifications.dc.html).
     *
     * @return array{expenseId: int|null, canDecide: bool, done: array{label: string, tone: string}|null}
     */
    private function expenseState(?Expense $expense, User $viewer): array
    {
        if ($expense === null) {
            return ['expenseId' => null, 'canDecide' => false, 'done' => null];
        }

        if ($expense->isPending()) {
            return ['expenseId' => $expense->id, 'canDecide' => $viewer->can('decide', $expense), 'done' => null];
        }

        $by = $expense->decided_by === $viewer->id ? 'you' : ($expense->decider->name ?? 'someone');

        return ['expenseId' => $expense->id, 'canDecide' => false, 'done' => match ($expense->status) {
            ExpenseStatus::Approved => ['label' => "Approved by {$by}", 'tone' => 'pos'],
            ExpenseStatus::Rejected => ['label' => "Rejected by {$by}", 'tone' => 'neg'],
            default => null,
        }];
    }

    /** @return array{type: string, id: int}|null */
    private function subject(DatabaseNotification $n): ?array
    {
        $subject = $n->data['subject'] ?? null;

        return is_array($subject) && is_string($subject['type'] ?? null) && is_int($subject['id'] ?? null)
            ? ['type' => $subject['type'], 'id' => $subject['id']]
            : null;
    }

    /** "2 hours ago", "Yesterday, 13:20", "12 Nov" (Notifications.dc.html). */
    public static function when(CarbonImmutable $at): string
    {
        $now = CarbonImmutable::now($at->getTimezone());

        return match (true) {
            $at->greaterThan($now->subHours(12)) => $at->diffForHumans($now, ['parts' => 1, 'syntax' => CarbonImmutable::DIFF_RELATIVE_TO_NOW]),
            $at->isSameDay($now) => 'Today, '.$at->format('H:i'),
            $at->isSameDay($now->subDay()) => 'Yesterday, '.$at->format('H:i'),
            $at->isSameYear($now) => $at->format('j M'),
            default => $at->format('j M Y'),
        };
    }
}
