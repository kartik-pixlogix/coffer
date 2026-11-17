<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** "Tell us this link is broken" on the 404 page (State404.dc.html, D89): a log line for support. */
final class BrokenLinkController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        Log::warning('Broken link reported', [
            'path' => $data['path'],
            'referer' => $request->headers->get('referer'),
            'user_id' => $request->user()?->id,
        ]);

        return back();
    }
}
