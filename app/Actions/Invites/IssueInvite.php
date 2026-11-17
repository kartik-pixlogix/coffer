<?php

declare(strict_types=1);

namespace App\Actions\Invites;

use App\Enums\CompanyRole;
use App\Enums\InviteKind;
use App\Enums\PlanLimit;
use App\Models\Company;
use App\Models\Invite;
use App\Models\User;
use App\Notifications\CompanyInvitation;
use App\Services\Billing\PlanUsage;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Creates an email invite (and sends it once the transaction commits) or a share link
 * (spec §2.3). A new share link replaces the company's current one.
 */
final class IssueInvite
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly PlanUsage $usage,
    ) {}

    public function email(
        Company $company,
        User $inviter,
        string $email,
        CompanyRole $role,
        int $days = Invite::DEFAULT_EXPIRY_DAYS,
        ?int $partnerId = null,
        ?int $memberId = null,
    ): Invite {
        // Spec §20 soft limit (D82): people across the workspace, waiting email invites included.
        $this->usage->ensureCanAdd($company->workspace, PlanLimit::Users);
        $token = Invite::newToken();

        /** @var Invite $invite */
        $invite = $this->current->run($company, fn () => Invite::query()->create([
            'company_id' => $company->id,
            'kind' => InviteKind::Email,
            'email' => Str::lower($email),
            'role' => $role,
            'partner_id' => $partnerId,
            'member_id' => $memberId,
            'invited_by' => $inviter->id,
            ...Invite::tokenAttributes($token),
            'expires_at' => now()->addDays($days),
            'last_sent_at' => now(),
        ]));

        $this->send($invite, $company, $inviter);
        $this->log($invite, $inviter, 'invited someone by email');

        return $invite;
    }

    public function link(Company $company, User $inviter, CompanyRole $role, int $days = Invite::DEFAULT_EXPIRY_DAYS): Invite
    {
        $this->usage->ensureCanAdd($company->workspace, PlanLimit::Users);

        return DB::transaction(function () use ($company, $inviter, $role, $days): Invite {
            // One live share link per company: "New link" stops the old one working.
            Invite::query()->withoutGlobalScope('company')
                ->where('company_id', $company->id)
                ->where('kind', InviteKind::Link->value)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            /** @var Invite $invite */
            $invite = Invite::query()->create([
                'company_id' => $company->id,
                'kind' => InviteKind::Link,
                'role' => $role,
                'invited_by' => $inviter->id,
                ...Invite::tokenAttributes(Invite::newToken()),
                'expires_at' => now()->addDays($days),
            ]);

            $this->log($invite, $inviter, 'made a share link');

            return $invite;
        });
    }

    /** "Resend": a fresh email with a fresh expiry (the link stays the same). */
    public function resend(Invite $invite, User $inviter): void
    {
        $days = max(1, (int) round(($invite->created_at ?? now())->diffInDays($invite->expires_at)));

        $invite->forceFill(['expires_at' => now()->addDays($days), 'last_sent_at' => now()])->save();

        $this->send($invite, $invite->company, $inviter);
    }

    private function send(Invite $invite, Company $company, User $inviter): void
    {
        if ($invite->email === null) {
            return;
        }

        DB::afterCommit(fn () => Notification::route('mail', $invite->email)
            ->notify(new CompanyInvitation($invite, $company, $inviter)));
    }

    private function log(Invite $invite, User $inviter, string $description): void
    {
        activity('members')
            ->performedOn($invite)
            ->causedBy($inviter)
            ->withProperties(['role' => $invite->role->value, 'kind' => $invite->kind->value])
            ->log($description);
    }
}
