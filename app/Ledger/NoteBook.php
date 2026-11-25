<?php

declare(strict_types=1);

namespace App\Ledger;

/**
 * The numbered notes of a Schedule III statement (D156): each note lists the accounts behind
 * one line, with this period's figure and the previous one's. An account that matches in
 * either period is listed, so a partner who moved from owing to being owed shows in both.
 *
 * @phpstan-type Account array{id: int, code: string, name: string, role: string, line: string|null, balance: int}
 * @phpstan-type Note array{number: int, title: string, subtitle: string|null, rows: list<array{code: string, name: string, amount: int, previous: int|null}>, total: int, previousTotal: int|null}
 */
final class NoteBook
{
    private int $next = 1;

    /** @var list<Note> */
    private array $notes = [];

    /**
     * @param  list<Account>  $current
     * @param  list<Account>|null  $previous  null when there is no previous year to compare
     */
    public function __construct(private readonly array $current, private readonly ?array $previous) {}

    /**
     * @param  callable(Account): bool  $matches
     * @param  bool  $credit  show credit balances as positive (liabilities, equity, income)
     * @return ($skipEmpty is true ? Note|null : Note)
     */
    public function add(string $title, callable $matches, bool $credit, ?string $subtitle = null, bool $skipEmpty = false): ?array
    {
        $sign = $credit ? -1 : 1;
        $rows = [];

        foreach ($this->current as $account) {
            if ($matches($account)) {
                $rows[$account['id']] = ['code' => $account['code'], 'name' => $account['name'], 'amount' => $sign * $account['balance'], 'previous' => $this->previous === null ? null : 0];
            }
        }

        foreach ($this->previous ?? [] as $account) {
            if ($matches($account)) {
                $rows[$account['id']] ??= ['code' => $account['code'], 'name' => $account['name'], 'amount' => 0, 'previous' => 0];
                $rows[$account['id']]['previous'] = $sign * $account['balance'];
            }
        }

        $rows = array_values(array_filter($rows, fn (array $r) => $r['amount'] !== 0 || ($r['previous'] ?? 0) !== 0));
        usort($rows, fn (array $a, array $b) => strnatcmp($a['code'], $b['code']));

        if ($skipEmpty && $rows === []) {
            return null;
        }

        return $this->push($title, $subtitle, $rows);
    }

    /** @return Note a note with a single figure that isn't one account (reserves and surplus) */
    public function addFigure(string $title, string $label, int $amount, ?int $previous): array
    {
        return $this->push($title, null, [['code' => '', 'name' => $label, 'amount' => $amount, 'previous' => $previous]]);
    }

    /** @return list<Note> */
    public function all(): array
    {
        return $this->notes;
    }

    /**
     * @param  list<array{code: string, name: string, amount: int, previous: int|null}>  $rows
     * @return Note
     */
    private function push(string $title, ?string $subtitle, array $rows): array
    {
        $note = [
            'number' => $this->next++,
            'title' => $title,
            'subtitle' => $subtitle,
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'amount')),
            'previousTotal' => $this->previous === null ? null : array_sum(array_map(fn (array $r) => (int) $r['previous'], $rows)),
        ];

        $this->notes[] = $note;

        return $note;
    }
}
