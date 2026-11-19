<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Companies\CompanyLifecycle;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Settings › Company › Archive or delete (V4, D139–D141). The current company is archived
 * (type its name) or deleted (its name and the password); archived and deleted companies
 * the person manages are restored or kept from the same card. Admin or workspace owner only.
 */
final class CompanyLifecycleController extends Controller
{
    public function archive(Request $request, CurrentCompany $current, CompanyLifecycle $lifecycle): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $current->require();
        Gate::authorize('company.remove', $company);

        $request->validate([
            'confirmation' => ['required', 'string', Rule::in([$company->name])],
        ], ['confirmation.required' => 'Type the company name exactly to confirm.', 'confirmation.in' => 'Type the company name exactly to confirm.']);

        $lifecycle->archive($company, $user);
        $moved = $this->leave($request, $user, $company);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$company->name} archived. It’s read-only and doesn’t count toward the plan".($moved ? '; restore it from Settings › Company.' : '.')]);

        return redirect()->route('home');
    }

    public function destroy(Request $request, CurrentCompany $current, CompanyLifecycle $lifecycle): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $current->require();
        Gate::authorize('company.remove', $company);

        $request->validate([
            'password' => ['required', 'current_password'],
            'confirmation' => ['required', 'string', Rule::in([$company->name])],
        ], ['confirmation.required' => 'Type the company name exactly to confirm.', 'confirmation.in' => 'Type the company name exactly to confirm.']);

        // Never strand the person: their last company goes with the workspace instead (D98).
        if (! $user->companies()->whereKeyNot($company->id)->exists()) {
            throw ValidationException::withMessages(['confirmation' => $company->workspace->isOwnedBy($user)
                ? "{$company->name} is your only company. To erase it, delete the workspace in Profile › Your data."
                : "{$company->name} is your only company, so it can’t be deleted here. The workspace owner can delete the whole workspace."]);
        }

        $when = $lifecycle->scheduleDeletion($company, $user);
        $request->session()->forget(CurrentCompany::SESSION_KEY);

        Inertia::flash('toast', ['type' => 'warning', 'message' => "{$company->name} will be erased on ".$when->format('j M Y').'. Until then you can keep it from Settings › Company.']);

        return redirect()->route('home');
    }

    public function restore(Request $request, Company $company, CompanyLifecycle $lifecycle): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('company.remove', $company);

        $lifecycle->restore($company, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$company->name} restored. It’s back in the company switcher."]);

        return back();
    }

    public function keep(Request $request, Company $company, CompanyLifecycle $lifecycle): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('company.remove', $company);

        $archived = $lifecycle->keep($company, $user);
        Inertia::flash('toast', $archived
            ? ['type' => 'warning', 'message' => "{$company->name} stays, archived: the plan has no room for another company. Upgrade, then restore it."]
            : ['type' => 'success', 'message' => "{$company->name} stays. Nothing was deleted."]);

        return back();
    }

    /** After archiving, open another of the person's companies when they have one. */
    private function leave(Request $request, User $user, Company $company): bool
    {
        $other = $user->companies()->whereNull('companies.archived_at')->whereKeyNot($company->id)->exists();

        if ($other) {
            $request->session()->forget(CurrentCompany::SESSION_KEY);
        }

        return $other;
    }
}
