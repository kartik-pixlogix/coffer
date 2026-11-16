<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Companies\SetCompanyRole;
use App\Actions\Vault\OffboardVaultUser;
use App\Enums\CompanyRole;
use App\Enums\InviteKind;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invite;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Partner;
use App\Models\User;
use App\Services\Vault\OffboardingList;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Members & invites (SettingsMembers.dc.html, spec §16): the company's users
 * with their role and link, team members by partner, and invites.
 */
final class MembersController extends Controller
{
    public function index(Request $request, CurrentCompany $current, OffboardVaultUser $offboard, OffboardingList $offboardings): Response
    {
        $company = $current->require();
        /** @var User $viewer */
        $viewer = $request->user();

        $partners = Partner::query()->active()->with('side')->get();
        $members = Member::query()->with('partner:id,name,colour')->orderBy('name')->get();
        $partnerByUser = $partners->whereNotNull('user_id')->keyBy('user_id');
        $memberByUser = $members->whereNotNull('user_id')->keyBy('user_id');

        $users = $company->users()->orderBy('users.name')->get()->map(function (User $user) use ($viewer, $company, $partnerByUser, $memberByUser, $offboard): array {
            /** @var Membership $membership */
            $membership = $user->getRelation('membership');
            /** @var Partner|null $partner */
            $partner = $partnerByUser->get($user->id);
            /** @var Member|null $member */
            $member = $memberByUser->get($user->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatarUrl' => $user->avatar_url,
                'colour' => $partner?->colour,
                'role' => $membership->role->value,
                'you' => $user->is($viewer),
                'isOwner' => $company->workspace->isOwnedBy($user),
                'twoFactor' => $user->hasTwoFactorEnabled(),
                'linked' => match (true) {
                    $partner !== null => ['label' => $partner->name, 'kind' => 'Partner'.($partner->side ? ' · '.$partner->side->name : '')],
                    $member !== null => ['label' => $member->name, 'kind' => 'Team member'.($member->designation ? ' · '.$member->designation : '')],
                    default => null,
                },
                'joined' => $membership->created_at?->toDateString(),
                'lastActive' => $membership->last_active_at?->toIso8601String(),
                // The remove dialog: "They can use N vault items" (spec §16, D73).
                'vaultItems' => $user->is($viewer) ? 0 : $offboard->usable($user)->count(),
            ];
        })->values();

        return Inertia::render('settings/members', [
            'users' => $users,
            'team' => $partners->map(fn (Partner $partner) => [
                'partner' => ['id' => $partner->id, 'name' => $partner->name, 'colour' => $partner->colour],
                'members' => $members->where('partner_id', $partner->id)->map(fn (Member $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'designation' => $m->designation,
                    'costType' => $m->cost_type->value,
                    'ratePaise' => $m->rate_paise,
                    'status' => $m->status->value,
                    'hasUser' => $m->user_id !== null,
                ])->values(),
            ])->filter(fn (array $group) => $group['members']->isNotEmpty())->values(),
            'invites' => $this->pendingInvites(),
            'shareLink' => $this->shareLink(),
            'linkOptions' => $this->linkOptions($partners, $members),
            'expiryDays' => Invite::EXPIRY_DAYS,
            'defaultExpiryDays' => Invite::DEFAULT_EXPIRY_DAYS,
            'viewerIsAdmin' => $current->isAdmin(),
            'offboardings' => $offboardings->forViewer($viewer),
        ]);
    }

    /** Change someone's role (the inline select). */
    public function updateRole(Request $request, User $user, CurrentCompany $current, SetCompanyRole $setRole, OffboardVaultUser $offboard): RedirectResponse
    {
        $company = $current->require();
        $validated = $request->validate(['role' => ['required', Rule::enum(CompanyRole::class)]]);
        $role = CompanyRole::from($validated['role']);
        $was = $this->membershipOf($company, $user)->role;

        $this->guardChange($request, $company, $user);

        // Only Admins hand out or take away Admin (Settings can be granted to other roles).
        if (($role === CompanyRole::Admin || $was === CompanyRole::Admin) && ! $current->isAdmin()) {
            abort(403);
        }

        if ($was === CompanyRole::Admin && $role !== CompanyRole::Admin) {
            $this->guardLastAdmin($company, $user);
        }

        $before = $offboard->usable($user);
        $setRole->handle($company, $user, $role);
        $after = $offboard->usable($user)->pluck('id')->flip();
        $lost = $offboard->record($user, $this->viewer($request), 'role', $before->reject(fn ($item) => $after->has($item->id))->values());

        // A linked partner record keeps the same Admin / Partner role.
        if (in_array($role, CompanyRole::partnerRoles(), true)) {
            Partner::query()->where('user_id', $user->id)->whereNull('archived_at')->update(['role' => $role]);
        }

        activity('members')->performedOn($company)->causedBy($request->user())
            ->withProperties(['user_id' => $user->id, 'from' => $was->value, 'to' => $role->value])
            ->log('changed a role');

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} is now {$role->withArticle()}".($lost === null ? '' : self::rotateHint($lost->item_ids))]);

        return back();
    }

    /** Remove someone from the company (their account stays; spec §16). */
    public function destroy(Request $request, User $user, CurrentCompany $current, SetCompanyRole $setRole, OffboardVaultUser $offboard): RedirectResponse
    {
        $company = $current->require();
        $membership = $this->membershipOf($company, $user);

        $this->guardChange($request, $company, $user);

        if ($membership->role === CompanyRole::Admin) {
            abort_unless($current->isAdmin(), 403);
            $this->guardLastAdmin($company, $user);
        }

        $usable = $offboard->usable($user);
        $setRole->remove($company, $user);
        $offboarding = $offboard->record($user, $this->viewer($request), 'removed', $usable);
        $offboard->removeAccess($user, $this->viewer($request));
        Partner::query()->where('user_id', $user->id)->update(['user_id' => null]);
        Member::query()->where('user_id', $user->id)->update(['user_id' => null]);

        activity('members')->performedOn($company)->causedBy($request->user())
            ->withProperties(['user_id' => $user->id])
            ->log('removed someone from the company');

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} was removed from {$company->name}".($offboarding === null ? '' : self::rotateHint($offboarding->item_ids))]);

        return back();
    }

    /** "Lost your device?": an Admin turns off someone's 2FA so they can set it up again. */
    public function resetTwoFactor(Request $request, User $user, CurrentCompany $current): RedirectResponse
    {
        $company = $current->require();
        $this->membershipOf($company, $user);

        abort_unless($current->isAdmin(), 403);
        abort_if($user->is($request->user()), 403);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $user->trustedDevices()->delete();

        activity('security')->performedOn($user)->causedBy($request->user())
            ->withProperties(['company_id' => $company->id])
            ->log('reset two-factor authentication');

        Inertia::flash('toast', ['type' => 'success', 'message' => "2FA reset for {$user->name}. They’ll set it up again from Profile."]);

        return back();
    }

    /** @param list<int> $ids */
    private static function rotateHint(array $ids): string
    {
        $n = count($ids);

        return $n === 0 ? '' : ". They could use {$n} vault ".($n === 1 ? 'item' : 'items').': rotate '.($n === 1 ? 'it' : 'them').' from the list below.';
    }

    private function viewer(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function membershipOf(Company $company, User $user): Membership
    {
        /** @var Membership|null $membership */
        $membership = Membership::query()->where('company_id', $company->id)->where('user_id', $user->id)->first();

        abort_if($membership === null, 404);

        return $membership;
    }

    /** Nobody changes their own role or removes themselves; the workspace owner stays Admin. */
    private function guardChange(Request $request, Company $company, User $user): void
    {
        abort_if($user->is($request->user()), 403);

        if ($company->workspace->isOwnedBy($user)) {
            throw ValidationException::withMessages(['role' => "{$user->name} owns the workspace, so they stay an Admin."]);
        }
    }

    private function guardLastAdmin(Company $company, User $user): void
    {
        $admins = Membership::query()->where('company_id', $company->id)->where('role', CompanyRole::Admin->value)->where('user_id', '!=', $user->id)->count();

        if ($admins === 0) {
            throw ValidationException::withMessages(['role' => 'A company needs at least one Admin.']);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function pendingInvites(): array
    {
        return Invite::query()->pending()->where('kind', InviteKind::Email->value)->latest()->get()
            ->map(fn (Invite $invite) => [
                'id' => $invite->id,
                'email' => $invite->email,
                'role' => $invite->role->value,
                'expiresAt' => $invite->expires_at->toIso8601String(),
            ])->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function shareLink(): ?array
    {
        $link = Invite::query()->pending()->where('kind', InviteKind::Link->value)->latest('id')->first();

        return $link === null ? null : [
            'id' => $link->id,
            'url' => $link->url(),
            'role' => $link->role->value,
            'expiresAt' => $link->expires_at->toIso8601String(),
            'uses' => $link->uses,
            'tail' => substr($link->token(), -4),
        ];
    }

    /**
     * Partner and team-member records not yet linked to a user, for "Link to".
     *
     * @param  Collection<int, Partner>  $partners
     * @param  Collection<int, Member>  $members
     * @return list<array{value: string, label: string}>
     */
    private function linkOptions(Collection $partners, Collection $members): array
    {
        return [
            ...$partners->whereNull('user_id')->map(fn (Partner $p) => ['value' => "partner:{$p->id}", 'label' => "Partner — {$p->name}"])->values()->all(),
            ...$members->whereNull('user_id')->map(fn (Member $m) => ['value' => "member:{$m->id}", 'label' => "Team member — {$m->name}"])->values()->all(),
        ];
    }
}
