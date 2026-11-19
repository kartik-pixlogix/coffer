<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Services\Auth\TwoFactorCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;

/**
 * A refused code at the sign-in 2FA step, with a message that says why: a wrong code, a code
 * that was just used (a second submit of the same code, or one already spent), or a sign-in
 * that has expired (no pending login in the session), which goes back to the password step.
 */
final class FailedTwoFactorSignIn implements FailedTwoFactorLoginResponse
{
    public const string WRONG_CODE = 'That code didn’t match. Codes change every 30 seconds.';

    public const string WRONG_RECOVERY = 'That recovery code didn’t match, or it has been used already.';

    public const string EXPIRED = 'Your sign-in expired. Enter your email and password again.';

    public function __construct(private readonly TwoFactorCodes $codes) {}

    public function toResponse($request)
    {
        $user = User::query()->find($request->session()->get('login.id'));

        if (! $user instanceof User) {
            return $this->fail($request, 'email', self::EXPIRED, route('login'));
        }

        if ($request->filled('recovery_code')) {
            return $this->fail($request, 'recovery_code', self::WRONG_RECOVERY);
        }

        $code = preg_replace('/\s+/', '', (string) $request->input('code')) ?? '';

        return $this->fail(
            $request,
            'code',
            $this->codes->wasJustUsed($user, $code) ? TwoFactorCodes::REPLAY_MESSAGE : self::WRONG_CODE,
        );
    }

    private function fail(Request $request, string $key, string $message, ?string $to = null): RedirectResponse
    {
        if ($request->wantsJson()) {
            throw ValidationException::withMessages([$key => [$message]]);
        }

        return redirect()->to($to ?? route('two-factor.login'))->withErrors([$key => $message]);
    }
}
