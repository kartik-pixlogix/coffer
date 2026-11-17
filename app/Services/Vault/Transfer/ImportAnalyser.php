<?php

declare(strict_types=1);

namespace App\Services\Vault\Transfer;

use App\Models\VaultItem;
use App\Models\VaultSecret;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\Totp;
use App\Services\Vault\VaultCrypto;

/**
 * Sorts parsed import rows into new, duplicate and invalid (D77). A duplicate is a row
 * whose type, name and username match an item already in the company (or an earlier
 * row) and, for passwords, whose HMAC fingerprint matches too — passwords are compared
 * by fingerprint only, never decrypted. Invalid rows say why (too long, a bad URL).
 */
final readonly class ImportAnalyser
{
    public function __construct(private VaultCrypto $crypto) {}

    /**
     * @param  list<ImportRow>  $rows
     * @return list<array{row: ImportRow, status: 'new'|'duplicate'|'invalid', reason: string|null}>
     */
    public function analyse(int $companyId, array $rows): array
    {
        $existing = [];
        $items = VaultItem::query()->get(['id', 'type', 'name', 'username']);
        $prints = VaultSecret::query()->whereIn('vault_item_id', $items->pluck('id'))->where('field', 'password')
            ->whereNotNull('fingerprint')->pluck('fingerprint', 'vault_item_id');

        foreach ($items as $item) {
            $existing[self::key($item->type->value, $item->name, $item->username)][] = $prints[$item->id] ?? null;
        }

        $out = [];

        foreach ($rows as $row) {
            $reason = $this->problem($row);

            if ($reason !== null) {
                $out[] = ['row' => $row, 'status' => 'invalid', 'reason' => $reason];

                continue;
            }

            $key = self::key($row->type->value, $row->name, $row->subtitle());
            $print = $row->password() === null ? null : $this->crypto->fingerprint($companyId, $row->password());
            $seen = $existing[$key] ?? [];
            $duplicate = $seen !== [] && ($print === null || in_array($print, $seen, true));

            $out[] = ['row' => $row, 'status' => $duplicate ? 'duplicate' : 'new', 'reason' => $duplicate ? 'Already in the vault' : null];
            $existing[$key][] = $print;
        }

        return $out;
    }

    /** What would stop SaveVaultItem taking this row (the add form's own limits). */
    private function problem(ImportRow $row): ?string
    {
        $templates = ItemTemplates::byKey($row->type);

        foreach ($row->fields as $key => $value) {
            $field = $templates[$key] ?? null;

            if ($field === null || $field['secret']) {
                return 'A field that isn’t part of the template';
            }

            if (is_string($value) && mb_strlen($value) > 2048) {
                return "{$field['label']} is too long";
            }

            if ($field['kind'] === 'url' && is_string($value) && preg_match('#^(https?://)?[^\s]+$#i', $value) !== 1) {
                return 'The web address has spaces in it';
            }

            if ($field['kind'] === 'email' && is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                return 'The email address isn’t valid';
            }
        }

        foreach ($row->secrets as $key => $value) {
            if (mb_strlen($value) > 20000) {
                return 'A secret is longer than 20,000 characters';
            }

            if ($key === 'totp' && Totp::normalise($value) === null) {
                return 'The 2FA setup key isn’t valid';
            }
        }

        if (count($row->customFields) > 30) {
            return 'More than 30 custom fields';
        }

        return null;
    }

    private static function key(string $type, string $name, ?string $username): string
    {
        return $type.'|'.mb_strtolower(trim($name)).'|'.mb_strtolower(trim((string) $username));
    }
}
