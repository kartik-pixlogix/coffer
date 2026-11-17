<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\Impersonator;
use Illuminate\Http\RedirectResponse;

/** "Stop impersonating" in the app's banner (D86): back to the workspace in /admin. */
final class StopImpersonationController extends Controller
{
    public function __invoke(Impersonator $impersonator): RedirectResponse
    {
        $impersonation = $impersonator->stop();

        $to = $impersonation?->workspace_id !== null
            ? '/admin/workspaces/'.$impersonation->workspace_id
            : '/admin';

        return redirect()->to($to);
    }
}
