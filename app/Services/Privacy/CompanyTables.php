<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use Illuminate\Support\Facades\Schema;

/**
 * The tenant tables of the schema (D98), read from the database itself so a table added
 * later is exported and erased without anyone remembering to list it: every table with a
 * `company_id` column, plus the child tables that hang off one of them without their own
 * `company_id` (today `member_rates` and spatie's `role_has_permissions`).
 */
final class CompanyTables
{
    /** Ciphertext: useless outside Coffer and never part of an export (the vault has its own, D77). */
    public const array NOT_EXPORTED = ['vault_secrets', 'vault_item_versions', 'vault_keys', 'vault_attachments', 'vault_imports'];

    /** @var array<string, list<array{columns: list<string>, foreign_table: string}>>|null */
    private ?array $foreignKeys = null;

    /** @return list<string> tables with a company_id column */
    public function scoped(): array
    {
        return array_values(array_filter($this->tables(), fn (string $table): bool => Schema::hasColumn($table, 'company_id')));
    }

    /**
     * Tables without company_id that reference a company-scoped table: [table, column, parent table].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function children(): array
    {
        $scoped = $this->scoped();
        $out = [];

        foreach ($this->tables() as $table) {
            if (in_array($table, $scoped, true) || $table === 'companies') {
                continue;
            }

            foreach ($this->foreignKeys($table) as $fk) {
                if (count($fk['columns']) === 1 && in_array($fk['foreign_table'], $scoped, true)) {
                    $out[] = [$table, $fk['columns'][0], $fk['foreign_table']];
                }
            }
        }

        return $out;
    }

    /** @return list<array{columns: list<string>, foreign_table: string}> */
    public function foreignKeys(string $table): array
    {
        if ($this->foreignKeys === null) {
            $this->foreignKeys = [];

            foreach ($this->tables() as $name) {
                $this->foreignKeys[$name] = array_map(
                    fn (array $fk): array => ['columns' => $fk['columns'], 'foreign_table' => (string) $fk['foreign_table']],
                    Schema::getForeignKeys($name),
                );
            }
        }

        return $this->foreignKeys[$table] ?? [];
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map('strval', Schema::getTableListing(schemaQualified: false));
    }
}
