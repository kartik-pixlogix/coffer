<?php

declare(strict_types=1);

namespace App\Actions\Invites;

use App\Actions\Companies\SetCompanyRole;
use App\Models\Invite;
use App\Models\Member;
use App\Models\Partner;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Joins `$user` to the invite's company with its role, and links them to the partner
 * or team-member record the invite names (spec §2.3). Email invites work once; share
 * links count their uses until they expire or are revoked.
 */
final class AcceptInvite
{
    public function __construct(
        private readonly SetCompanyRole $setRole,
        private readonly CurrentCompany $current,
    ) {}

    public function handle(Invite $invite, User $user): void
    {
        DB::transaction(function () use ($invite, $user): void {
            /** @var Invite $locked */
            $locked = Invite::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($invite->id);
            $company = $locked->company;

            if (! $locked->isUsable()) {
                throw ValidationException::withMessages(['invite' => 'This invite has expired. Ask for a new link.']);
            }

            if ($user->belongsToCompany($company)) {
                throw ValidationException::withMessages(['invite' => "You’re already in {$company->name}."]);
            }

            $this->setRole->handle($company, $user, $locked->role);

            $this->current->run($company, function () use ($locked, $user): void {
                if ($locked->partner_id !== null) {
                    Partner::query()->whereKey($locked->partner_id)->whereNull('user_id')->update(['user_id' => $user->id]);
                }

                if ($locked->member_id !== null) {
                    Member::query()->whereKey($locked->member_id)->whereNull('user_id')->update(['user_id' => $user->id]);
                }
            });

            $locked->forceFill([
                'accepted_at' => $locked->accepted_at ?? now(),
                'uses' => $locked->uses + 1,
            ])->save();

            activity('members')
                ->performedOn($locked)
                ->causedBy($user)
                ->withProperties(['company_id' => $company->id, 'role' => $locked->role->value])
                ->log('accepted an invite');
        });
    }
}
