<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Permissions\Access;
use App\Support\Permissions\Catalog;
use App\Support\Permissions\PageRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/home`, where sign-in and the switcher land (spec §2.1): Members on Timesheets,
 * everyone else on the Dashboard; otherwise the first page the role can open.
 */
final class LandingController extends Controller
{
    public function __invoke(Request $request, CurrentCompany $current, Access $access): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $role = $current->role();

        if ($role === null) {
            return redirect()->route('companies.create');
        }

        $allowed = $access->pages($user);
        $preferred = Catalog::landingPageFor($role);
        $candidates = [$preferred, ...array_keys(array_filter($allowed))];

        foreach ($candidates as $page) {
            $url = ($allowed[$page] ?? false) ? PageRoutes::url($page) : null;

            if ($url !== null) {
                return redirect()->to($url);
            }
        }

        return redirect()->route('profile.edit');
    }
}
