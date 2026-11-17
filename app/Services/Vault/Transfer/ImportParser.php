<?php

declare(strict_types=1);

namespace App\Services\Vault\Transfer;

use App\Enums\VaultEnvironment;
use App\Enums\VaultImportFormat;
use App\Enums\VaultItemType;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\Provider;
use App\Services\Vault\ProviderCatalog;
use App\Services\Vault\Totp;
use JsonException;
use RuntimeException;
use SensitiveParameter;

/**
 * Reads vault import files (spec §14.4, D77): a Coffer export (already decrypted), a
 * Bitwarden JSON or CSV export, 1Password, LastPass or Chrome CSV, or any CSV with its
 * columns mapped. Each item becomes an ImportRow shaped for SaveVaultItem; rows that
 * can't come in (cards, identities, rows without anything to keep) are reported by line
 * with a reason, never with their contents.
 */
final class ImportParser
{
    /** Coffer fields a plain CSV's columns can map to. */
    public const array FIELDS = ['name', 'url', 'username', 'password', 'totp', 'notes', 'tags'];

    /** Header names that suggest each field (lower-case). */
    private const array ALIASES = [
        'name' => ['name', 'title', 'item', 'account', 'site', 'service'],
        'url' => ['url', 'uri', 'website', 'login_uri', 'web site', 'address', 'link'],
        'username' => ['username', 'user', 'login', 'email', 'user name', 'login_username', 'e-mail'],
        'password' => ['password', 'pass', 'login_password', 'secret'],
        'totp' => ['totp', 'otp', 'otpauth', '2fa', 'login_totp', 'one-time password', 'mfa'],
        'notes' => ['notes', 'note', 'extra', 'comments', 'comment', 'description'],
        'tags' => ['tags', 'tag', 'folder', 'grouping', 'group', 'category', 'labels'],
    ];

    /** @var list<array{line: int, reason: string}> */
    private array $skipped = [];

    public static function detect(string $bytes): VaultImportFormat
    {
        if (ExportCrypto::looksLikeExport($bytes)) {
            return VaultImportFormat::Coffer;
        }

        $head = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', substr($bytes, 0, 4096)));

        if (str_starts_with($head, '{')) {
            return VaultImportFormat::BitwardenJson;
        }

        $keys = array_map(fn (?string $k) => strtolower(trim((string) $k, " \t\"'")), str_getcsv(strtok($head, "\r\n") ?: '', str_contains(strtok($head, "\r\n") ?: '', ';') && ! str_contains(strtok($head, "\r\n") ?: '', ',') ? ';' : ',', '"', ''));
        $has = fn (string ...$names): bool => array_diff($names, $keys) === [];

        return match (true) {
            $has('login_uri', 'login_password') => VaultImportFormat::BitwardenCsv,
            $has('grouping', 'extra', 'password') => VaultImportFormat::LastPass,
            $has('title', 'password') && ($has('otpauth') || $has('url') || $has('website')) => VaultImportFormat::OnePassword,
            $has('name', 'url', 'username', 'password') => VaultImportFormat::Chrome,
            default => VaultImportFormat::Csv,
        };
    }

    /**
     * A guess at the column → field mapping for a plain CSV.
     *
     * @param  list<string>  $header
     * @return array<int, string|null>
     */
    public static function guessMapping(array $header): array
    {
        $mapping = [];
        $used = [];

        foreach ($header as $i => $name) {
            $key = strtolower(trim($name));
            $mapping[$i] = null;

            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($used[$field]) && in_array($key, $aliases, true)) {
                    $mapping[$i] = $field;
                    $used[$field] = true;

                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  array<int, string|null>  $mapping  plain CSV only: column → field
     * @return array{rows: list<ImportRow>, skipped: list<array{line: int, reason: string}>}
     *
     * @throws RuntimeException with a message for the person
     */
    public function parse(VaultImportFormat $format, #[SensitiveParameter] string $bytes, array $mapping = []): array
    {
        $this->skipped = [];

        $rows = match ($format) {
            VaultImportFormat::Coffer => $this->coffer($bytes),
            VaultImportFormat::BitwardenJson => $this->bitwardenJson($bytes),
            default => $this->csv($format, CsvTable::read($bytes), $mapping),
        };

        return ['rows' => $rows, 'skipped' => $this->skipped];
    }

    /**
     * @param  array<int, string|null>  $mapping
     * @return list<ImportRow>
     */
    private function csv(VaultImportFormat $format, CsvTable $table, array $mapping): array
    {
        $out = [];

        foreach ($table->rows as ['line' => $line, 'cells' => $cells]) {
            $r = $table->assoc($cells);

            $row = match ($format) {
                VaultImportFormat::BitwardenCsv => $this->bitwardenCsvRow($line, $r),
                VaultImportFormat::LastPass => $this->login($line, $r['name'] ?? '', $r['url'] ?? '', $r['username'] ?? '', $r['password'] ?? '', $r['totp'] ?? '', $r['extra'] ?? '', self::tags($r['grouping'] ?? ''), secureNote: ($r['url'] ?? '') === 'http://sn'),
                VaultImportFormat::OnePassword => $this->login($line, $r['title'] ?? '', $r['url'] ?? ($r['website'] ?? ''), $r['username'] ?? '', $r['password'] ?? '', $r['otpauth'] ?? '', $r['notes'] ?? '', self::tags($r['tags'] ?? '')),
                VaultImportFormat::Chrome => $this->login($line, $r['name'] ?? '', $r['url'] ?? '', $r['username'] ?? '', $r['password'] ?? '', '', $r['note'] ?? ($r['notes'] ?? ''), []),
                default => $this->mappedRow($line, $cells, $mapping),
            };

            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @param array<string, string> $r */
    private function bitwardenCsvRow(int $line, array $r): ?ImportRow
    {
        $type = strtolower(trim($r['type'] ?? 'login'));

        if (! in_array($type, ['login', 'note'], true)) {
            $this->skip($line, "Bitwarden {$type} items aren’t imported");

            return null;
        }

        $custom = [];

        foreach (preg_split('/\R/', $r['fields'] ?? '') ?: [] as $pair) {
            if (str_contains($pair, ':')) {
                [$label, $value] = array_map('trim', explode(':', $pair, 2));

                if ($label !== '') {
                    $custom[] = ['label' => mb_substr($label, 0, 60), 'secret' => false, 'value' => mb_substr($value, 0, 5000)];
                }
            }
        }

        return $this->login($line, $r['name'] ?? '', $r['login_uri'] ?? '', $r['login_username'] ?? '', $r['login_password'] ?? '', $r['login_totp'] ?? '', $r['notes'] ?? '', self::tags($r['folder'] ?? ''), $custom, secureNote: $type === 'note');
    }

    /**
     * @param  list<string>  $cells
     * @param  array<int, string|null>  $mapping
     */
    private function mappedRow(int $line, array $cells, array $mapping): ?ImportRow
    {
        $get = function (string $field) use ($cells, $mapping): string {
            $i = array_search($field, $mapping, true);

            return $i === false ? '' : ($cells[(int) $i] ?? '');
        };

        $password = $get('password');
        $notes = $get('notes');

        return $this->login($line, $get('name'), $get('url'), $get('username'), $password, $get('totp'), $notes, self::tags($get('tags')),
            secureNote: $password === '' && $get('username') === '' && $get('url') === '' && $notes !== '');
    }

    /** @return list<ImportRow> */
    private function bitwardenJson(string $bytes): array
    {
        try {
            /** @var mixed $data */
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('This JSON file couldn’t be read.');
        }

        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            throw new RuntimeException('This JSON file isn’t a Bitwarden export. Export from Bitwarden as JSON (not encrypted).');
        }

        if (($data['encrypted'] ?? false) === true) {
            throw new RuntimeException('This Bitwarden export is encrypted. Export it again as plain JSON, import it, then delete the file.');
        }

        $folders = [];

        foreach (is_array($data['folders'] ?? null) ? $data['folders'] : [] as $folder) {
            if (is_array($folder) && is_string($folder['id'] ?? null) && is_string($folder['name'] ?? null)) {
                $folders[$folder['id']] = $folder['name'];
            }
        }

        $out = [];

        foreach (array_values($data['items']) as $i => $item) {
            $line = $i + 1;

            if (! is_array($item)) {
                continue;
            }

            $type = (int) ($item['type'] ?? 1);
            $str = fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
            $tags = isset($item['folderId']) && is_string($item['folderId']) && isset($folders[$item['folderId']]) ? self::tags($folders[$item['folderId']]) : [];

            if ($type !== 1 && $type !== 2) {
                $this->skip($line, $type === 3 ? 'Cards aren’t imported' : 'Identities aren’t imported');

                continue;
            }

            $custom = [];

            foreach (is_array($item['fields'] ?? null) ? $item['fields'] : [] as $field) {
                if (is_array($field) && $str($field['name'] ?? '') !== '' && in_array((int) ($field['type'] ?? 0), [0, 1, 2], true)) {
                    $custom[] = ['label' => mb_substr($str($field['name']), 0, 60), 'secret' => (int) ($field['type'] ?? 0) === 1, 'value' => mb_substr($str($field['value'] ?? ''), 0, 5000)];
                }
            }

            $login = is_array($item['login'] ?? null) ? $item['login'] : [];
            $uri = is_array($login['uris'] ?? null) && is_array($login['uris'][0] ?? null) ? $str($login['uris'][0]['uri'] ?? '') : '';

            $row = $this->login($line, $str($item['name'] ?? ''), $uri, $str($login['username'] ?? ''), $str($login['password'] ?? ''), $str($login['totp'] ?? ''), $str($item['notes'] ?? ''), $tags, $custom, secureNote: $type === 2);

            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * A decrypted Coffer export: items in Coffer's own shape.
     *
     * @return list<ImportRow>
     */
    private function coffer(string $plaintext): array
    {
        try {
            /** @var mixed $data */
            $data = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('This export file is damaged.');
        }

        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            throw new RuntimeException('This export file is damaged.');
        }

        $out = [];

        foreach (array_values($data['items']) as $i => $item) {
            $line = $i + 1;
            $type = is_array($item) ? VaultItemType::tryFrom(is_string($item['type'] ?? null) ? $item['type'] : '') : null;

            if ($type === null) {
                $this->skip($line, 'Unknown item type');

                continue;
            }

            $templates = ItemTemplates::byKey($type);
            $fields = [];

            foreach (is_array($item['fields'] ?? null) ? $item['fields'] : [] as $key => $value) {
                if (isset($templates[$key]) && ! $templates[$key]['secret'] && (is_scalar($value))) {
                    $fields[(string) $key] = is_bool($value) ? $value : (string) $value;
                }
            }

            $allowed = [...ItemTemplates::secretKeys($type), ItemTemplates::NOTES];
            $secrets = [];

            foreach (is_array($item['secrets'] ?? null) ? $item['secrets'] : [] as $key => $value) {
                if (in_array($key, $allowed, true) && is_string($value) && $value !== '') {
                    $secrets[(string) $key] = $value;
                }
            }

            $custom = [];

            foreach (is_array($item['custom_fields'] ?? null) ? $item['custom_fields'] : [] as $c) {
                if (is_array($c) && is_string($c['label'] ?? null) && $c['label'] !== '') {
                    $custom[] = ['label' => mb_substr($c['label'], 0, 60), 'secret' => (bool) ($c['secret'] ?? false), 'value' => is_string($c['value'] ?? null) ? $c['value'] : ''];
                }
            }

            $expires = is_string($item['expires_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['expires_at']) === 1 ? $item['expires_at'] : null;
            $provider = is_string($item['provider'] ?? null) && ProviderCatalog::exists($item['provider']) ? $item['provider'] : null;
            $environment = is_string($item['environment'] ?? null) ? VaultEnvironment::tryFrom($item['environment'])?->value : null;

            $out[] = new ImportRow(
                line: $line,
                type: $type,
                name: self::name(is_string($item['name'] ?? null) ? $item['name'] : '', is_string($fields['url'] ?? null) ? $fields['url'] : null),
                provider: $provider,
                fields: $fields,
                secrets: $secrets,
                customFields: $custom,
                tags: self::tags(is_array($item['tags'] ?? null) ? implode(',', array_filter($item['tags'], 'is_string')) : ''),
                environment: $environment,
                expiresAt: $expires,
                restricted: (bool) ($item['restricted'] ?? false),
            );
        }

        return $out;
    }

    /**
     * A website login (or a secure note when there's nothing but notes).
     *
     * @param  list<string>  $tags
     * @param  list<array{label: string, secret: bool, value: string}>  $custom
     */
    private function login(int $line, string $name, string $url, string $username, #[SensitiveParameter] string $password, #[SensitiveParameter] string $totp, #[SensitiveParameter] string $notes, array $tags, array $custom = [], bool $secureNote = false): ?ImportRow
    {
        $url = trim($url);
        $username = trim($username);

        if ($secureNote) {
            if (trim($notes) === '' && $custom === []) {
                $this->skip($line, 'Empty note');

                return null;
            }

            return new ImportRow($line, VaultItemType::Note, self::name($name, null, 'Imported note'), null, [], ['note' => $notes === '' ? ' ' : $notes], $custom, $tags);
        }

        if ($password === '' && $username === '' && $url === '' && trim($notes) === '' && $custom === []) {
            $this->skip($line, 'Nothing to import');

            return null;
        }

        $remarks = [];
        $secrets = array_filter(['password' => $password, 'notes' => $notes], fn (string $v) => $v !== '');

        if (trim($totp) !== '') {
            $key = Totp::normalise($totp);

            if ($key === null) {
                $remarks[] = '2FA key skipped (not a valid setup key)';
            } else {
                $secrets['totp'] = $key;
            }
        }

        $fields = array_filter(['url' => $url, 'username' => $username], fn (string $v) => $v !== '');

        return new ImportRow($line, VaultItemType::Login, self::name($name, $url), self::providerFor($url), $fields, $secrets, $custom, $tags, notes: $remarks);
    }

    private function skip(int $line, string $reason): void
    {
        $this->skipped[] = ['line' => $line, 'reason' => $reason];
    }

    private static function name(string $name, ?string $url, string $fallback = 'Imported login'): string
    {
        $name = trim($name);

        if ($name === '' && $url !== null && $url !== '') {
            $name = (string) (parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST) ?: $url);
        }

        return mb_substr($name === '' ? $fallback : $name, 0, 160);
    }

    /** @return list<string> */
    private static function tags(string $value): array
    {
        $out = [];

        foreach (preg_split('/[,;\/\\\\]/', $value) ?: [] as $tag) {
            $tag = mb_strtolower(mb_substr(trim($tag), 0, 32));

            if ($tag !== '' && ! in_array($tag, $out, true) && count($out) < 12) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    /** A catalog login provider whose site matches the URL's domain (monogram tiles). */
    private static function providerFor(string $url): ?string
    {
        $host = $url === '' ? null : parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $base = implode('.', array_slice(explode('.', strtolower($host)), -2));

        foreach (ProviderCatalog::providers() as $provider) {
            /** @var Provider $provider */
            $theirs = parse_url($provider->url, PHP_URL_HOST);

            if ($provider->type === VaultItemType::Login && is_string($theirs) && implode('.', array_slice(explode('.', $theirs), -2)) === $base) {
                return $provider->key;
            }
        }

        return null;
    }
}
