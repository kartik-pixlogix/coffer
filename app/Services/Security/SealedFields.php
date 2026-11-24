<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\CapitalMovement;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Reimbursement;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * Which columns are sealed with FieldCrypto (D149). The models use EncryptsFields, which
 * reads this list; the backfill migration, key rotation and raw-query readers (exports,
 * bulk inserts) use it too, so a new sensitive column is added here once.
 */
final class SealedFields
{
    /** @var array<class-string<Model>, list<string>> */
    public const array MODELS = [
        Company::class => ['gstin', 'address'],
        MoneyAccount::class => ['bank_label'],
        Partner::class => ['email'],
        Member::class => ['email'],
        Income::class => ['notes'],
        Expense::class => ['notes'],
        TimeEntry::class => ['notes'],
        CapitalMovement::class => ['note'],
        Loan::class => ['purpose'],
        LoanRepayment::class => ['note'],
        Reimbursement::class => ['note'],
    ];

    /** @return list<string> */
    public static function of(Model|string $model): array
    {
        return self::MODELS[is_string($model) ? $model : $model::class] ?? [];
    }

    /**
     * Table → [columns, the column holding the company id] for row-level work.
     *
     * @return array<string, array{columns: list<string>, company: string}>
     */
    public static function tables(): array
    {
        $tables = [];

        foreach (self::MODELS as $class => $columns) {
            $tables[(new $class)->getTable()] = ['columns' => $columns, 'company' => $class === Company::class ? 'id' : 'company_id'];
        }

        return $tables;
    }

    /**
     * Seals the sensitive columns of rows headed for a bulk `insert()`, which skips model events.
     *
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $rows  each with `company_id`
     * @return list<array<string, mixed>>
     */
    public static function sealRows(string $model, array $rows): array
    {
        $crypto = app(FieldCrypto::class);
        $table = (new $model)->getTable();

        return array_map(function (array $row) use ($crypto, $model, $table): array {
            foreach (self::of($model) as $column) {
                if (is_string($row[$column] ?? null) && ! FieldCrypto::isSealed($row[$column])) {
                    $row[$column] = $crypto->seal((int) $row['company_id'], $row[$column], "{$table}.{$column}");
                }
            }

            return $row;
        }, $rows);
    }

    /**
     * Opens the sensitive columns of rows read with the query builder (exports).
     *
     * @param  object|array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function openRow(string $table, object|array $row): array
    {
        $row = (array) $row;
        $spec = self::tables()[$table] ?? null;

        if ($spec === null) {
            return $row;
        }

        $crypto = app(FieldCrypto::class);

        foreach ($spec['columns'] as $column) {
            if (FieldCrypto::isSealed($row[$column] ?? null)) {
                $row[$column] = $crypto->open((int) $row[$spec['company']], (string) $row[$column], "{$table}.{$column}");
            }
        }

        return $row;
    }
}
