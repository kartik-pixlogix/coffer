<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AccentUpdateRequest;
use Illuminate\Http\RedirectResponse;

final class AccentController extends Controller
{
    /**
     * Save the brand gradient from the top-bar colour picker (D147).
     */
    public function update(AccentUpdateRequest $request): RedirectResponse
    {
        // The tenant guard: platform admins (D85) have no accent here.
        $user = $request->user('web');
        abort_if($user === null, 403);

        $user->accent = $request->accent()->toStored();
        $user->save();

        return back();
    }
}
