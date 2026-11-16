<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Ledger\ReportCache;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * Records that change report figures without posting (equity, attribution, account and
 * category names) invalidate the company's cached aggregates when saved or deleted (D62).
 * Postings flush through LedgerPoster.
 */
trait FlushesReportCache
{
    public static function bootFlushesReportCache(): void
    {
        $flush = static function (Model $model): void {
            $companyId = $model instanceof Company ? $model->getKey() : $model->getAttribute('company_id');

            if (is_numeric($companyId)) {
                app(ReportCache::class)->flush((int) $companyId);
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }
}
