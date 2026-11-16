<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Invites\IssueInvite;
use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\Member;
use App\Models\Partner;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The invite modal on Settings › Members (spec §2.3): email invites (several at once),
 * the share link, resend and revoke.
 */
final class InvitesController extends Controller
{
    public function store(Request $request, CurrentCompany $current, IssueInvite $issue): RedirectResponse
    {
        $company = $current->require();
        /** @var User $inviter */
        $inviter = $request->user();

        $validated = $request->validate([
            'emails' => ['required', 'string', 'max:2000'],
            'role' => ['required', Rule::enum(CompanyRole::class)],
            'link' => ['nullable', 'string', 'regex:/^(partner|member):\d+$/'],
            'days' => ['required', 'integer', Rule::in(Invite::EXPIRY_DAYS)],
        ]);

        $role = CompanyRole::from($validated['role']);
        $this->guardAdminRole($role, $current);

        $emails = collect(preg_split('/[\s,;]+/', $validated['emails']) ?: [])
            ->map(fn (string $e) => Str::lower(trim($e)))
            ->filter()
            ->unique()
            ->values();

        if ($emails->isEmpty() || $emails->contains(fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) === false)) {
            throw ValidationException::withMessages(['emails' => 'Check the email address.']);
        }

        if ($emails->count() > 20) {
            throw ValidationException::withMessages(['emails' => 'Invite up to 20 people at a time.']);
        }

        $already = $company->users()->whereIn('email', $emails)->pluck('email');

        if ($already->isNotEmpty()) {
            throw ValidationException::withMessages(['emails' => $already->first().' is already in '.$company->name.'.']);
        }

        [$partnerId, $memberId] = $this->resolveLink($validated['link'] ?? null, $emails->count());

        DB::transaction(function () use ($emails, $company, $inviter, $role, $validated, $partnerId, $memberId, $issue): void {
            foreach ($emails as $email) {
                // A fresh invite replaces any earlier pending one for the same address.
                Invite::query()->pending()->where('email', $email)->update(['revoked_at' => now()]);
                $issue->email($company, $inviter, $email, $role, (int) $validated['days'], $partnerId, $memberId);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $emails->count() === 1 ? "Invite sent to {$emails->first()}" : "Invites sent to {$emails->count()} people"]);

        return back();
    }

    /** "New link": a share link with the chosen role and expiry; the old one stops working. */
    public function link(Request $request, CurrentCompany $current, IssueInvite $issue): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::enum(CompanyRole::class)],
            'days' => ['required', 'integer', Rule::in(Invite::EXPIRY_DAYS)],
        ]);

        $role = CompanyRole::from($validated['role']);
        $this->guardAdminRole($role, $current);

        /** @var User $inviter */
        $inviter = $request->user();
        $issue->link($current->require(), $inviter, $role, (int) $validated['days']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'New link made. The old one stops working.']);

        return back();
    }

    public function resend(Request $request, Invite $invite, IssueInvite $issue, CurrentCompany $current): RedirectResponse
    {
        abort_if($invite->isLink() || ! $invite->isUsable(), 404);
        $this->guardAdminRole($invite->role, $current);

        /** @var User $inviter */
        $inviter = $request->user();
        $issue->resend($invite, $inviter);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Invite sent again to {$invite->email}"]);

        return back();
    }

    public function destroy(Request $request, Invite $invite, CurrentCompany $current): RedirectResponse
    {
        $this->guardAdminRole($invite->role, $current);

        $invite->forceFill(['revoked_at' => now()])->save();

        activity('members')->performedOn($invite)->causedBy($request->user())->log('revoked an invite');

        Inertia::flash('toast', ['type' => 'success', 'message' => $invite->isLink() ? 'Share link revoked' : "Invite for {$invite->email} revoked"]);

        return back();
    }

    /** Only Admins can invite Admins (Settings may be granted to other roles). */
    private function guardAdminRole(CompanyRole $role, CurrentCompany $current): void
    {
        abort_if($role === CompanyRole::Admin && ! $current->isAdmin(), 403);
    }

    /**
     * "Link to": a partner or team member of this company that has no user yet. Linking
     * only makes sense for one invitee.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveLink(?string $link, int $count): array
    {
        if ($link === null || $link === '') {
            return [null, null];
        }

        if ($count > 1) {
            throw ValidationException::withMessages(['link' => 'Link one person at a time.']);
        }

        [$kind, $id] = explode(':', $link, 2);
        $model = $kind === 'partner' ? Partner::query() : Member::query();
        $record = $model->whereKey((int) $id)->whereNull('user_id')->first();

        if ($record === null) {
            throw ValidationException::withMessages(['link' => 'Choose someone who isn’t linked yet.']);
        }

        return $kind === 'partner' ? [$record->id, null] : [null, $record->id];
    }
}
