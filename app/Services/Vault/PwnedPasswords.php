<?php

declare(strict_types=1);

namespace App\Services\Vault;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Have I Been Pwned's range API with k-anonymity (spec §14.3, D74): only the first five
 * hex characters of the password's SHA-1 leave the server, with the Add-Padding header so
 * response sizes don't hint at the answer; the rest of the hash is compared here. The
 * full hash is never sent, logged or stored. Off unless VAULT_HIBP_ENABLED is true.
 */
final class PwnedPasswords
{
    public const string ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    /** @var array<string, array<string, int>> prefix → suffix → count, for one job run */
    private array $ranges = [];

    public static function enabled(): bool
    {
        return (bool) config('vault.hibp_enabled');
    }

    /** True when the password is in a breach list, false when not, null when the check failed. */
    public function isPwned(#[SensitiveParameter] string $password): ?bool
    {
        $hash = strtoupper(sha1($password));
        $range = $this->range(substr($hash, 0, 5));

        return $range === null ? null : ($range[substr($hash, 5)] ?? 0) > 0;
    }

    /** @return array<string, int>|null */
    private function range(string $prefix): ?array
    {
        if (isset($this->ranges[$prefix])) {
            return $this->ranges[$prefix];
        }

        try {
            $body = Http::withHeaders(['Add-Padding' => 'true', 'User-Agent' => 'Coffer-Vault-Breach-Check'])
                ->timeout(10)
                ->get(self::ENDPOINT.$prefix)
                ->throw()
                ->body();
        } catch (ConnectionException|RequestException) {
            // The prefix alone reveals nothing; still, log only that a check failed.
            Log::warning('Vault breach check: the Have I Been Pwned range API did not answer.');

            return null;
        }

        $out = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            [$tail, $count] = array_pad(explode(':', trim($line), 2), 2, '0');

            if ($tail !== '') {
                $out[strtoupper($tail)] = (int) $count;
            }
        }

        return $this->ranges[$prefix] = $out;
    }
}
