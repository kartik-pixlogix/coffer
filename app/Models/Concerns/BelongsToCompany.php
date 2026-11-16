<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Business records carry `company_id` (spec §2). While a current company is set,
 * every query is limited to it and new records are stamped with it, so a request
 * can never read or write another company's rows (route-model binding included).
 *
 * @property int $company_id
 * @property-read Company $company
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $companyId = app(CurrentCompany::class)->id();

            if ($companyId !== null) {
                $query->where($query->qualifyColumn('company_id'), $companyId);
            }
        });

        static::creating(function (self $model): void {
            $model->company_id ??= app(CurrentCompany::class)->id();
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
