<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Models\Company;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * "Find the drift" on Statements (spec §15, D60). LedgerPoster only writes balanced
 * entries, so an out-of-balance trial balance or balance sheet means something wrote to
 * the books around it (a bug, a manual SQL fix, a bad import). This lists what to look
 * at, oldest first:
 *
 * - entries whose debits and credits differ;
 * - lines with a negative amount, or a debit and a credit at once;
 * - lines dated differently from their entry (an "as of" cut splits the entry);
 * - lines on another company's account or entry;
 * - accounts whose type doesn't match what they're for (a money account typed as income).
 */
final class DriftFinder
{
    /**
     * @return list<array{kind: string, entryId: int|null, accountId: int|null, date: string|null, type: string|null, memo: string|null, account: string|null, amount: int, message: string}>
     */
    public function find(Company $company, CarbonImmutable $asOf): array
    {
        $day = $asOf->toDateString();
        $issues = [];

        $unbalanced = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $company->id)
            ->where('e.date', '<=', $day)
            ->selectRaw('e.id, e.date, e.type, e.memo, SUM(l.debit_paise) as debit, SUM(l.credit_paise) as credit')
            ->groupBy('e.id', 'e.date', 'e.type', 'e.memo')
            ->havingRaw('SUM(l.debit_paise) <> SUM(l.credit_paise)')
            ->get();

        foreach ($unbalanced as $row) {
            $drift = (int) $row->debit - (int) $row->credit;
            $issues[] = $this->issue('unbalanced', $row, $drift, $drift > 0
                ? 'Debits are higher than credits by '.Money::format($drift).'.'
                : 'Credits are higher than debits by '.Money::format(-$drift).'.');
        }

        $badLines = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $company->id)
            ->where(fn ($q) => $q->where('l.date', '<=', $day)->orWhere('e.date', '<=', $day))
            ->where(fn ($q) => $q
                ->where('l.debit_paise', '<', 0)
                ->orWhere('l.credit_paise', '<', 0)
                ->orWhere(fn ($both) => $both->where('l.debit_paise', '>', 0)->where('l.credit_paise', '>', 0))
                ->orWhereColumn('l.date', '!=', 'e.date')
                ->orWhereColumn('a.company_id', '!=', 'l.company_id')
                ->orWhereColumn('e.company_id', '!=', 'l.company_id'))
            ->selectRaw('l.id as line_id, l.date as line_date, l.debit_paise, l.credit_paise, l.company_id, a.company_id as account_company, a.id as account_id, a.name as account, e.id, e.date, e.type, e.memo, e.company_id as entry_company')
            ->orderBy('e.date')
            ->get();

        foreach ($badLines as $row) {
            $amount = (int) $row->debit_paise - (int) $row->credit_paise;
            $message = match (true) {
                (int) $row->debit_paise < 0 || (int) $row->credit_paise < 0 => "A line on {$row->account} has a negative amount.",
                (int) $row->debit_paise > 0 && (int) $row->credit_paise > 0 => "A line on {$row->account} is both a debit and a credit.",
                (int) $row->account_company !== (int) $row->company_id => "A line uses {$row->account}, an account of another company.",
                (int) $row->entry_company !== (int) $row->company_id => 'A line belongs to an entry of another company.',
                default => "A line on {$row->account} is dated {$this->day((string) $row->line_date)}, not with its entry.",
            };
            $issues[] = $this->issue('line', $row, $amount, $message, (int) $row->account_id, (string) $row->account);
        }

        $accounts = DB::table('accounts')->where('company_id', $company->id)->get(['id', 'name', 'type', 'role']);

        foreach ($accounts as $account) {
            $role = AccountRole::tryFrom((string) $account->role);

            if ($role === null || $role->type()->value !== $account->type) {
                $issues[] = [
                    'kind' => 'account',
                    'entryId' => null,
                    'accountId' => (int) $account->id,
                    'date' => null,
                    'type' => null,
                    'memo' => null,
                    'account' => (string) $account->name,
                    'amount' => 0,
                    'message' => $role === null
                        ? "{$account->name} isn’t a known kind of account, so statements leave it out."
                        : "{$account->name} is typed “{$account->type}” but holds {$role->type()->value} figures.",
                ];
            }
        }

        usort($issues, fn (array $a, array $b) => [$a['date'] ?? '9999', $a['entryId'] ?? 0] <=> [$b['date'] ?? '9999', $b['entryId'] ?? 0]);

        return $issues;
    }

    /** @return array{kind: string, entryId: int|null, accountId: int|null, date: string|null, type: string|null, memo: string|null, account: string|null, amount: int, message: string} */
    private function issue(string $kind, stdClass $row, int $amount, string $message, ?int $accountId = null, ?string $account = null): array
    {
        return [
            'kind' => $kind,
            'entryId' => (int) $row->id,
            'accountId' => $accountId,
            'date' => substr((string) $row->date, 0, 10),
            'type' => (string) $row->type,
            'memo' => $row->memo !== null ? (string) $row->memo : null,
            'account' => $account,
            'amount' => $amount,
            'message' => $message,
        ];
    }

    private function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('j M Y');
    }
}
