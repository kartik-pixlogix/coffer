<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Support\Auth\IntendedUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * After the password (or the 2FA code) at sign-in (G10, G13, D113): the page asked for if
 * it is a page, else `/home` (the role's first permitted page), never an export or file.
 */
final class SignedInResponse implements LoginResponse, TwoFactorLoginResponse
{
    /** @param  Request  $request */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        return redirect()->to(IntendedUrl::pull($request));
    }
}
