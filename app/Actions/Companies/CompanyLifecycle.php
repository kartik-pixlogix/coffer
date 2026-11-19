<?php

declare(strict_types=1);

namespace App\Actions\Companies;

use App\Enums\PlanLimit;
use App\Exceptions\PlanLimitReached;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\PlanUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Archive, restore and delete one company (V4, D139–D141), Admin or workspace owner only
 * (the `company.remove` gate).
 * - Archive: out of the switcher and the plan's company count, read-only, nothing lost.
 * - Restore: back in, if the plan has room for one more company (else the Upgrade modal).
 * - Delete: soft-deleted at once (gone from every list, its people lose access) and erased by
 *   `coffer:privacy-purge` after the D98 grace period; until then it can be kept. Kept without
 *   room on the plan, it comes back archived rather than over the limit.
 */
final readonly class CompanyLifecycle
{
    public function __construct(private PlanUsage $usage) {}

    public function archive(Company $company, User $by): void
    {
        if ($company->isArchived()) {
            throw ValidationException::withMessages(['company' => "{$company->name} is already archived."]);
        }

        $company->forceFill(['archived_at' => now(), 'archived_by' => $by->id])->save();

        activity('settings')->performedOn($company)->causedBy($by)->log('archived the company');
    }

    /** @throws PlanLimitReached when the plan has no room for another active company */
    public function restore(Company $company, User $by): void
    {
        if (! $company->isArchived()) {
            throw ValidationException::withMessages(['company' => "{$company->name} isn’t archived."]);
        }

        $this->usage->ensureCanAdd($company->workspace, PlanLimit::Companies);
        $company->forceFill(['archived_at' => null, 'archived_by' => null])->save();

        activity('settings')->performedOn($company)->causedBy($by)->log('restored the company');
    }

    /** Soft-deletes the company now and schedules its erasure; returns the day it goes. */
    public function scheduleDeletion(Company $company, User $by): CarbonImmutable
    {
        $when = CarbonImmutable::now()->addDays((int) config('privacy.grace_days'))->startOfDay();

        DB::transaction(function () use ($company, $by, $when): void {
            $company->forceFill([
                'deletion_requested_at' => now(),
                'deletion_scheduled_for' => $when,
                'deletion_requested_by' => $by->id,
            ])->save();
            $company->delete();

            activity('privacy')->performedOn($company)->causedBy($by)
                ->withProperties(['scheduled_for' => $when->toDateString(), 'workspace_id' => $company->workspace_id])
                ->log('Company deletion requested');
        });

        return $when;
    }

    /** Cancels a scheduled deletion. True when it came back archived for want of room on the plan. */
    public function keep(Company $company, User $by): bool
    {
        if (! $company->trashed() || $company->deletion_scheduled_for === null) {
            throw ValidationException::withMessages(['company' => "{$company->name} isn’t being deleted."]);
        }

        $archive = ! $company->isArchived() && ! $this->usage->hasRoomFor($company->workspace, PlanLimit::Companies);

        DB::transaction(function () use ($company, $by, $archive): void {
            $company->forceFill([
                'deletion_requested_at' => null,
                'deletion_scheduled_for' => null,
                'deletion_requested_by' => null,
                ...($archive ? ['archived_at' => now(), 'archived_by' => $by->id] : []),
            ]);
            $company->restore();

            activity('privacy')->performedOn($company)->causedBy($by)
                ->withProperties(['archived' => $archive])
                ->log('Company deletion cancelled');
        });

        return $archive;
    }
}
