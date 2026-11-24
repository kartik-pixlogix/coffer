<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Data-key rotation for sealed business fields (D149, alongside the vault's D66): finds a
 * company's values sealed under an older key version and re-seals them under the active one.
 * Part of ReencryptVaultData, so `coffer:vault-rotate` covers both, and the old key is only
 * retired once nothing uses it.
 */
final readonly class FieldRotation
{
    public function __construct(private FieldCrypto $crypto) {}

    /** Sealed values of the company not on `$version` yet. */
    public function remaining(int $companyId, int $version): int
    {
        $count = 0;

        foreach (SealedFields::tables() as $table => $spec) {
            $count += $this->stale($table, $spec, $companyId, $version)->count();
        }

        return $count;
    }

    /** Re-seals up to `$limit` rows; returns how many rows changed. */
    public function reseal(int $companyId, int $version, int $limit): int
    {
        $done = 0;

        foreach (SealedFields::tables() as $table => $spec) {
            if ($done >= $limit) {
                break;
            }

            foreach ($this->stale($table, $spec, $companyId, $version)->orderBy('id')->limit($limit - $done)->get(['id', ...$spec['columns']]) as $row) {
                $updates = [];

                foreach ($spec['columns'] as $column) {
                    $value = $row->{$column};

                    if (is_string($value) && ($new = $this->crypto->reseal($companyId, $value, "{$table}.{$column}")) !== null) {
                        $updates[$column] = $new;
                    }
                }

                DB::table($table)->where('id', $row->id)->update($updates);
                $done++;
            }
        }

        return $done;
    }

    /** @param  array{columns: list<string>, company: string}  $spec */
    private function stale(string $table, array $spec, int $companyId, int $version): Builder
    {
        return DB::table($table)->where($spec['company'], $companyId)->where(function (Builder $q) use ($spec, $version): void {
            foreach ($spec['columns'] as $column) {
                $q->orWhere(fn (Builder $c) => $c->whereNotNull($column)->where($column, '<>', '')->where($column, 'not like', FieldCrypto::PREFIX.$version.':%'));
            }
        });
    }
}
