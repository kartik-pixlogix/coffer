<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DPDPA deletion requests (spec §23, D98). Nothing is erased now: the account or workspace
 * is scheduled for `privacy.grace_days` days ahead and `coffer:privacy-purge` erases it then;
 * until that day it can be kept. A workspace's subscription is cancelled at once so nothing
 * more is charged.
 */
final readonly class ScheduleDeletion
{
    public function __construct(private PaymentGateway $gateway) {}

    public function account(User $user): CarbonImmutable
    {
        $owned = Workspace::query()->where('owner_id', $user->id)->whereNull('deletion_scheduled_for')->pluck('name');

        if ($owned->isNotEmpty()) {
            throw ValidationException::withMessages([
                'account' => 'You own '.$owned->implode(', ').'. Delete the workspace first, or ask support to move it to another owner.',
            ]);
        }

        $when = CarbonImmutable::now()->addDays((int) config('privacy.grace_days'))->startOfDay();

        $user->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => $when])->save();

        activity('privacy')->performedOn($user)->causedBy($user)
            ->withProperties(['scheduled_for' => $when->toDateString()])
            ->log('Account deletion requested');

        return $when;
    }

    public function keepAccount(User $user): void
    {
        $user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->save();

        activity('privacy')->performedOn($user)->causedBy($user)->log('Account deletion cancelled');
    }

    public function workspace(Workspace $workspace, User $by): CarbonImmutable
    {
        $when = CarbonImmutable::now()->addDays((int) config('privacy.grace_days'))->startOfDay();

        DB::transaction(function () use ($workspace, $by, $when): void {
            $workspace->forceFill([
                'deletion_requested_at' => now(),
                'deletion_scheduled_for' => $when,
                'deletion_requested_by' => $by->id,
            ])->save();

            activity('privacy')->performedOn($workspace)->causedBy($by)
                ->withProperties(['scheduled_for' => $when->toDateString()])
                ->log('Workspace deletion requested');
        });

        $this->gateway->cancelNow($workspace);

        return $when;
    }

    public function keepWorkspace(Workspace $workspace, User $by): void
    {
        $workspace->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null, 'deletion_requested_by' => null])->save();

        activity('privacy')->performedOn($workspace)->causedBy($by)->log('Workspace deletion cancelled');
    }
}
