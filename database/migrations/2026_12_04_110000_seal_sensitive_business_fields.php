<?php

use App\Services\Security\FieldCrypto;
use App\Services\Security\SealedFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Columns that were short strings; ciphertext needs room, so they become text. */
    private const array WIDEN = [
        'companies' => ['gstin'],
        'money_accounts' => ['bank_label'],
        'partners' => ['email'],
        'members' => ['email'],
        'time_entries' => ['notes'],
        'capital_movements' => ['note'],
        'loans' => ['purpose'],
        'loan_repayments' => ['note'],
        'reimbursements' => ['note'],
    ];

    /**
     * Field-level encryption (D149): widen the columns, then seal every existing value with its
     * company's data key. Needs VAULT_MASTER_KEY when there are rows to seal (as the vault does).
     */
    public function up(): void
    {
        foreach (self::WIDEN as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns): void {
                foreach ($columns as $column) {
                    $t->text($column)->nullable()->change();
                }
            });
        }

        $this->rewrite(fn (FieldCrypto $crypto, int $company, string $value, string $field): ?string => FieldCrypto::isSealed($value) ? null : $crypto->seal($company, $value, $field));
    }

    /** Opens the values again; the columns stay text. */
    public function down(): void
    {
        $this->rewrite(fn (FieldCrypto $crypto, int $company, string $value, string $field): ?string => FieldCrypto::isSealed($value) ? $crypto->open($company, $value, $field) : null);
    }

    /** @param callable(FieldCrypto, int, string, string): ?string $change */
    private function rewrite(callable $change): void
    {
        $crypto = app(FieldCrypto::class);

        foreach (SealedFields::tables() as $table => $spec) {
            $companyColumn = $spec['company'];
            // Only the columns that exist at this point: later migrations add sealed columns of their own (D159).
            $spec['columns'] = array_values(array_filter($spec['columns'], fn (string $column): bool => Schema::hasColumn($table, $column)));

            if ($spec['columns'] === []) {
                continue;
            }

            DB::table($table)->select(['id', ...($companyColumn === 'id' ? [] : [$companyColumn]), ...$spec['columns']])
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $spec, $companyColumn, $crypto, $change): void {
                    foreach ($rows as $row) {
                        $updates = [];

                        foreach ($spec['columns'] as $column) {
                            $value = $row->{$column};

                            if (is_string($value) && $value !== '') {
                                $new = $change($crypto, (int) $row->{$companyColumn}, $value, "{$table}.{$column}");

                                if ($new !== null) {
                                    $updates[$column] = $new;
                                }
                            }
                        }

                        if ($updates !== []) {
                            DB::table($table)->where('id', $row->id)->update($updates);
                        }
                    }
                });
        }
    }
};
