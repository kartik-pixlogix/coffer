<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\Impersonator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Stop impersonating" in the app's banner (D86): back to the workspace in /admin.
 * /admin is Filament, not Inertia, so an Inertia visit gets a hard navigation (S1, D111)
 * instead of a redirect it would open as an HTML modal.
 */
final class StopImpersonationController extends Controller
{
    public function __invoke(Request $request, Impersonator $impersonator): Response
    {
        $impersonation = $impersonator->stop();

        $to = $impersonation?->workspace_id !== null
            ? '/admin/workspaces/'.$impersonation->workspace_id
            : '/admin';

        return $request->header('X-Inertia') !== null ? Inertia::location(url($to)) : redirect()->to($to);
    }
}
