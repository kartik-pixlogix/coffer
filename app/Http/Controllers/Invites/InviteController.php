<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invites;

use App\Actions\Invites\AcceptInvite;
use App\Concerns\PasswordValidationRules;
use App\Enums\CompanyRole;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use App\Notifications\InviteRenewalRequested;
use App\Support\CurrentCompany;
use App\Support\Permissions\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * The invite page (AcceptInvite.dc.html, spec §2.3): valid invites join by creating a
 * password, with Google, or (signed in as that email) straight away; expired, revoked
 * or used links show the expired state with "Ask for a new link".
 */
final class InviteController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request, string $token): Response
    {
        $invite = Invite::findByToken($token);

        abort_if($invite === null, 404);

        $company = $invite->company;
        $inviter = $invite->inviter;
        $user = $request->user();
        $existingAccount = $invite->email !== null && User::query()->where('email', Str::lower($invite->email))->exists();

        // "Sign in to join" comes back here after sign-in.
        if ($user === null && $existingAccount && $invite->isUsable()) {
            $request->session()->put('url.intended', route('invites.show', $token));
        }

        return Inertia::render('auth/accept-invite', [
            'token' => $token,
            'valid' => $invite->isUsable(),
            'invite' => [
                'email' => $invite->email,
                'role' => $invite->role->value,
                'sentAt' => ($invite->last_sent_at ?? $invite->created_at)?->toDateString(),
                'expiresAt' => $invite->expires_at->toDateString(),
                'endedAt' => $invite->endedAt()->toDateString(),
                'lifetimeDays' => (int) round(($invite->created_at ?? now())->diffInDays($invite->expires_at)),
                'isLink' => $invite->isLink(),
            ],
            'inviter' => ['name' => $inviter->name],
            'company' => [
                'name' => $company->name,
                'initials' => $company->initials(),
                'type' => $company->type->value,
                'sides' => $company->sides()->withoutGlobalScope('company')->pluck('name')->all(),
            ],
            'partner' => $this->partnerLink($invite),
            'abilities' => $this->abilities($invite),
            'account' => $user instanceof User ? ['email' => $user->email, 'matches' => $invite->email === null || Str::lower($invite->email) === Str::lower($user->email)] : null,
            'existingAccount' => $existingAccount,
            'googleEnabled' => GoogleController::enabled(),
            'renewalRequested' => (bool) $request->session()->get("invites.renewal.{$invite->id}"),
        ]);
    }

    /** Join with a new password (no account yet), or as the signed-in user. */
    public function accept(Request $request, string $token, AcceptInvite $accept): RedirectResponse
    {
        $invite = Invite::findByToken($token);
        abort_if($invite === null, 404);

        $user = $request->user();

        if (! $user instanceof User) {
            $validated = $request->validate([
                'email' => [Rule::requiredIf($invite->isLink()), 'nullable', 'string', 'email', 'max:255', Rule::unique(User::class)],
                'name' => ['nullable', 'string', 'max:255'],
                'password' => $this->passwordRules(confirmed: false),
            ], [
                'email.unique' => 'There’s already an account with this email. Sign in first, then open the invite again.',
                'password.min' => 'Use at least 12 characters.',
            ]);

            $email = Str::lower($invite->email ?? $validated['email']);

            if ($invite->email !== null && User::query()->where('email', $email)->exists()) {
                return back()->withErrors(['email' => 'There’s already an account with this email. Sign in first, then open the invite again.']);
            }

            $user = DB::transaction(function () use ($invite, $validated, $email, $accept): User {
                $user = User::query()->create([
                    'name' => $validated['name'] ?? $this->nameFor($invite, $email),
                    'email' => $email,
                    'password' => $validated['password'],
                ]);

                // The invite went to this address, so it is already verified.
                if ($invite->email !== null) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                $accept->handle($invite, $user);

                return $user;
            });

            Auth::login($user);
            $request->session()->regenerate();
        } else {
            if ($invite->email !== null && Str::lower($invite->email) !== Str::lower($user->email)) {
                return back()->withErrors(['invite' => "This invite is for {$invite->email}. Sign out and open it again."]);
            }

            $accept->handle($invite, $user);
        }

        $request->session()->put(CurrentCompany::SESSION_KEY, $invite->company_id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Welcome to {$invite->company->name}."]);

        return redirect()->route('home');
    }

    public function decline(string $token): RedirectResponse
    {
        $invite = Invite::findByToken($token);
        abort_if($invite === null, 404);

        if ($invite->isUsable() && ! $invite->isLink()) {
            $invite->forceFill(['declined_at' => now()])->save();
        }

        return redirect()->route('invites.show', $token);
    }

    /** "Ask {inviter} for a new link": emails the inviter, at most once a day per invite. */
    public function renew(Request $request, string $token): RedirectResponse
    {
        $invite = Invite::findByToken($token);
        abort_if($invite === null, 404);

        $key = "invite-renewal:{$invite->id}:".$request->ip();

        if (! RateLimiter::tooManyAttempts($key, 1)) {
            RateLimiter::hit($key, 24 * 60 * 60);
            $invite->inviter->notify(new InviteRenewalRequested($invite, $invite->company));
        }

        $request->session()->put("invites.renewal.{$invite->id}", true);

        return redirect()->route('invites.show', $token);
    }

    /** @return array{name: string, side: string|null, equityBps: int}|null */
    private function partnerLink(Invite $invite): ?array
    {
        $partner = $invite->partner()->withoutGlobalScope('company')->with(['side' => fn ($q) => $q->withoutGlobalScope('company')])->first();

        return $partner === null ? null : [
            'name' => $partner->name,
            'side' => $partner->side?->name,
            'equityBps' => $partner->equity_bps,
        ];
    }

    /**
     * "As a Partner you can…": the role's pages and actions in this company's matrix,
     * summarised for the invite page.
     *
     * @return array{can: list<string>, cannot: list<string>}
     */
    private function abilities(Invite $invite): array
    {
        $role = $invite->role;

        if ($role === CompanyRole::Admin) {
            return ['can' => ['Everything, including settings, billing and closing the fiscal year'], 'cannot' => []];
        }

        $spatieRole = Role::query()->where('name', $role->value)->where('company_id', $invite->company_id)->first();
        $has = fn (string $permission): bool => $spatieRole !== null && $spatieRole->hasPermissionTo($permission);
        $page = fn (string $key): bool => $has(Catalog::pagePermission($key));
        $action = fn (string $key): bool => $has(Catalog::actionPermission($key));

        $can = [];
        $cannot = [];

        $sees = array_values(array_filter([
            $page('dashboard') ? 'the dashboard' : null,
            $page('reports') ? 'reports' : null,
            $page('settlements') && $invite->company->isJv() ? 'settlements' : null,
        ]));

        if ($sees !== []) {
            $can[] = 'See '.self::listing($sees);
        }

        if ($action('income.manage') && $action('expenses.approve')) {
            $can[] = 'Add income and expenses, and approve them';
        } elseif ($action('expenses.create')) {
            $can[] = $role === CompanyRole::Member ? 'Log your own hours and expenses' : 'Add expenses';
        }

        if ($page('vault')) {
            $can[] = 'Use vault items shared with you';
        }

        if ($role === CompanyRole::Viewer) {
            $can[] = 'Read reports and statements; nothing can be changed';
        }

        $cannot[] = 'Change billing or company settings (Admins only)';

        return ['can' => $can, 'cannot' => $cannot];
    }

    private function nameFor(Invite $invite, string $email): string
    {
        $linked = $invite->partner()->withoutGlobalScope('company')->value('name')
            ?? $invite->member()->withoutGlobalScope('company')->value('name');

        return is_string($linked) ? $linked : Str::headline(Str::before($email, '@'));
    }

    /** @param list<string> $items */
    private static function listing(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
