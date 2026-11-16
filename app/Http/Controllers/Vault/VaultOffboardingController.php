<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VaultOffboarding;
use App\Services\Vault\VaultAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Dismiss an offboarding rotate list once it's handled (spec §14.2, D73). Admins only. */
final class VaultOffboardingController extends Controller
{
    public function dismiss(Request $request, VaultOffboarding $offboarding, VaultAccess $access): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $access->isAdmin($user), 403);

        $offboarding->update(['dismissed_at' => now()]);
        activity('vault')->performedOn($offboarding)->causedBy($user)->log('Vault offboarding list dismissed');
        Inertia::flash('toast', ['type' => 'success', 'message' => "Rotate list for {$offboarding->user_name} dismissed"]);

        return back();
    }
}
