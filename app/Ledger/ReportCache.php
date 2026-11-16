<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Company;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * Cached report aggregates per company and period (spec §23). Every key carries the
 * company's cache version, and anything that changes the books (a posting, a reversal,
 * an equity or settings change) bumps the version, so old entries are simply never read
 * again and expire on their own. No cache tags needed: this works the same on the
 * `database` store used locally and on Redis (D62).
 */
final class ReportCache
{
    /** Old versions are never read again; they only need to expire eventually. */
    public const int TTL_SECONDS = 86_400;

    public function __construct(private readonly Repository $cache) {}

    /**
     * @template T
     *
     * @param  array<int|string, mixed>  $key  what makes this aggregate distinct (period, options)
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(Company|int $company, string $report, array $key, Closure $compute): mixed
    {
        $id = $company instanceof Company ? $company->id : $company;
        $name = 'coffer:reports:'.$id.':'.$this->version($id).':'.$report.':'.md5((string) json_encode($key));

        return $this->cache->remember($name, self::TTL_SECONDS, $compute);
    }

    /** Invalidate every cached aggregate of a company. */
    public function flush(Company|int $company): void
    {
        $id = $company instanceof Company ? $company->id : $company;
        $this->cache->forever($this->versionKey($id), Str::random(16));
    }

    public function version(int $companyId): string
    {
        $version = $this->cache->get($this->versionKey($companyId));

        if (! is_string($version) || $version === '') {
            $version = Str::random(16);
            $this->cache->forever($this->versionKey($companyId), $version);
        }

        return $version;
    }

    private function versionKey(int $companyId): string
    {
        return 'coffer:reports:'.$companyId.':version';
    }
}
