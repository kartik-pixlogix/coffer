<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * The hash chain behind the audit trail (D153). Each row's hash is an HMAC-SHA256, under a
 * key derived from APP_KEY, of the previous row's hash and the row's own fields in a fixed
 * order, so changing, removing or reordering any row breaks every hash after it; the key
 * means someone with only database access can't recompute the chain to hide a change.
 * Rows hashed under a previous APP_KEY (APP_PREVIOUS_KEYS) still verify.
 */
final class AuditChain
{
    /** The hash before a chain's first row. */
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** The fields a hash covers, in order. */
    public const array FIELDS = [
        'company_id', 'seq', 'occurred_at', 'user_id', 'actor', 'impersonator', 'ip', 'user_agent',
        'action', 'subject_type', 'subject_id', 'subject_label', 'voucher_no', 'changes', 'prev_hash',
    ];

    /** @param array<string, mixed>|stdClass $row */
    public function hash(array|stdClass $row): string
    {
        return hash_hmac('sha256', $this->canonical($row), $this->keys()[0]);
    }

    /** @param array<string, mixed>|stdClass $row */
    public function matches(array|stdClass $row, string $hash): bool
    {
        $canonical = $this->canonical($row);

        foreach ($this->keys() as $key) {
            if (hash_equals(hash_hmac('sha256', $canonical, $key), $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walks one company's chain in order. Null when it's intact; otherwise the first broken
     * position and what is wrong with it.
     *
     * @return array{seq: int, problem: string}|null
     */
    public function verify(int $companyId): ?array
    {
        $previous = self::GENESIS;
        $expected = 1;

        foreach (DB::table('audit_events')->where('company_id', $companyId)->lazyById(500, 'id') as $row) {
            $seq = (int) $row->seq;

            if ($seq !== $expected) {
                return ['seq' => $expected, 'problem' => $seq > $expected ? 'is missing' : 'appears twice'];
            }

            if ($row->prev_hash !== $previous) {
                return ['seq' => $seq, 'problem' => 'doesn’t follow the event before it'];
            }

            if (! $this->matches($row, (string) $row->hash)) {
                return ['seq' => $seq, 'problem' => 'was changed after it was written'];
            }

            $previous = (string) $row->hash;
            $expected++;
        }

        $head = DB::table('audit_heads')->where('company_id', $companyId)->first();

        if ($head !== null && ((int) $head->seq !== $expected - 1 || $head->hash !== $previous)) {
            return ['seq' => $expected, 'problem' => 'is missing (the trail ends early)'];
        }

        return null;
    }

    /** @param array<string, mixed>|stdClass $row */
    private function canonical(array|stdClass $row): string
    {
        $row = (array) $row;
        $values = [];

        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;
            // Strings throughout, so a value reads back from the database exactly as it was hashed.
            $values[] = $value === null ? null : (string) $value;
        }

        return (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return non-empty-list<string> the current key first, then the previous ones */
    private function keys(): array
    {
        $keys = [];

        foreach ([config('app.key'), ...(array) config('app.previous_keys', [])] as $appKey) {
            if (! is_string($appKey) || $appKey === '') {
                continue;
            }

            $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;

            if (is_string($raw) && $raw !== '') {
                $keys[] = hash_hkdf('sha256', $raw, 32, 'coffer-audit-chain-v1');
            }
        }

        return $keys !== [] ? $keys : throw new RuntimeException('APP_KEY is not set, so the audit trail can’t be signed.');
    }
}
