<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The top bar's company switcher. Only companies the user belongs to can be chosen. */
final class SwitchCompanyController extends Controller
{
    public function __invoke(Request $request, Company $company): RedirectResponse
    {
        abort_unless($request->user('web')?->belongsToCompany($company), 404);

        $request->session()->put(CurrentCompany::SESSION_KEY, $company->id);

        return redirect()->route('home');
    }
}
