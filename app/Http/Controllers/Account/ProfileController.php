<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Fortify\DisableTwoFactorAuthentication;
use App\Auth\DeviceLabel;
use App\Enums\NumberFormat;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account › Profile and security (Profile.dc.html, spec §17): profile details, password,
 * two-factor authentication and sessions on one page.
 */
final class ProfileController extends Controller
{
    /** Time zones offered first; any valid zone is accepted. */
    public const array TIMEZONES = [
        'Asia/Kolkata' => 'Asia/Kolkata (IST)',
        'Asia/Dubai' => 'Asia/Dubai (GST)',
        'Asia/Singapore' => 'Asia/Singapore (SGT)',
        'Europe/London' => 'Europe/London (GMT)',
        'Europe/Berlin' => 'Europe/Berlin (CET)',
        'America/New_York' => 'America/New_York (ET)',
        'America/Los_Angeles' => 'America/Los_Angeles (PT)',
        'Australia/Sydney' => 'Australia/Sydney (AET)',
        'UTC' => 'UTC',
    ];

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $status = $request->session()->get('status');
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
        $enabled = $user->hasTwoFactorEnabled();

        return Inertia::render('account/profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'verified' => $user->email_verified_at !== null,
                'timezone' => $user->timezone,
                'numberFormat' => $user->number_format->value,
                'avatarUrl' => $user->avatar_url,
                'colour' => Partner::query()->where('user_id', $user->id)->value('colour'),
            ],
            'timezones' => collect(self::TIMEZONES + [$user->timezone => $user->timezone])
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'password' => ['changedAt' => $user->password_changed_at?->toDateString()],
            'twoFactor' => [
                'enabled' => $enabled,
                'pending' => $pending,
                'confirmedAt' => $user->two_factor_confirmed_at?->toDateString(),
                'qrSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
                'secret' => $pending ? decrypt($user->two_factor_secret) : null,
                // Codes are shown while setting up, and once right after "Make new recovery codes".
                'recoveryCodes' => $pending || ($enabled && $status === 'recovery-codes-generated') ? $user->recoveryCodes() : null,
                'codesLeft' => $enabled ? count($user->recoveryCodes()) : 0,
                'trustedDevices' => $user->trustedDevices()->where('expires_at', '>', now())->count(),
                // Spec §23: 2FA is required for Admins.
                'required' => DisableTwoFactorAuthentication::isRequiredFor($user),
            ],
            'sessions' => $this->sessions($request, $user),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'timezone' => ['required', 'timezone:all'],
            'number_format' => ['required', Rule::enum(NumberFormat::class)],
        ], [
            'name.required' => 'Add your name.',
            'email.unique' => 'Another account uses this email.',
        ]);

        $validated['email'] = Str::lower($validated['email']);
        $user->fill($validated);
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $emailChanged ? 'Profile saved. Check your inbox to confirm the new email.' : 'Profile saved']);

        return back();
    }

    /**
     * Signed-in browsers from the database sessions table (spec §17).
     *
     * @return array<int, array<string, mixed>>
     */
    private function sessions(Request $request, User $user): array
    {
        $currentId = $request->session()->getId();

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session) => [
                'id' => $session->id === $currentId ? 'current' : hash('sha256', (string) $session->id),
                'device' => DeviceLabel::from($session->user_agent),
                'mobile' => DeviceLabel::isMobile($session->user_agent),
                'ip' => $session->ip_address,
                'current' => $session->id === $currentId,
                'lastActive' => date(DATE_ATOM, (int) $session->last_activity),
            ])
            ->values()
            ->all();
    }
}
