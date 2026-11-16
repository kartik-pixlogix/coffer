<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Actions\Companies\LaunchCompany;
use App\Actions\Workspaces\CreateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\CompanyWizardRequest;
use App\Models\CompanyDraft;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The create-company wizard (CreateCompany*.dc.html, spec §3.1). New sign-ups land here;
 * later the workspace owner reaches it from the switcher's "New company".
 */
final class CreateCompanyController extends Controller
{
    public function create(Request $request, CreateWorkspace $createWorkspace): Response
    {
        $user = $this->user($request);
        $workspace = $this->workspace($user, $createWorkspace);
        $draft = CompanyDraft::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->first();

        return Inertia::render('companies/create', [
            'workspace' => ['name' => $workspace->name],
            'owner' => ['name' => $user->name, 'email' => $user->email],
            // A brand-new workspace's first company is usually named after it.
            'suggestedName' => $workspace->companies()->exists() ? '' : $workspace->name,
            'hasCompanies' => $user->companies()->exists(),
            'draft' => $draft === null ? null : ['step' => $draft->step, 'data' => $draft->payload],
            'currencies' => CompanyWizardRequest::CURRENCIES,
        ]);
    }

    /** "Next": checks the step (100% rules, unique short code) before moving on. */
    public function check(CompanyWizardRequest $request): RedirectResponse
    {
        return back();
    }

    /** "Save and exit": keeps the draft for next time (spec §3.1). */
    public function saveDraft(Request $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $validated = $request->validate([
            'step' => ['required', 'integer', 'between:1,3'],
            'data' => ['required', 'array'],
        ]);

        $user = $this->user($request);
        $workspace = $this->workspace($user, $createWorkspace);

        // The draft is the wizard's own state; keep it bounded.
        abort_if(strlen((string) json_encode($validated['data'])) > 64_000, 422, 'The draft is too large.');

        CompanyDraft::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['step' => $validated['step'], 'payload' => $validated['data']],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft saved. Pick up where you left off any time.']);

        return $user->companies()->exists() ? redirect()->route('home') : redirect()->route('companies.create');
    }

    public function store(CompanyWizardRequest $request, LaunchCompany $launch, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $user = $this->user($request);
        $workspace = $this->workspace($user, $createWorkspace);

        /** @var array{company: array<string, mixed>, sides?: list<array<string, mixed>>, partners: list<array<string, mixed>>, members?: list<array<string, mixed>>, invite_members?: bool} $data */
        $data = $request->validated();
        $company = $launch->handle($workspace, $user, $data);

        $request->session()->put(CurrentCompany::SESSION_KEY, $company->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$company->name} is ready."]);

        return redirect()->route('dashboard');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /** The workspace the user owns; someone who only joined others' companies gets their own. */
    private function workspace(User $user, CreateWorkspace $createWorkspace): Workspace
    {
        return $user->ownedWorkspaces()->oldest('id')->first()
            ?? $createWorkspace->handle($user, $user->name.'’s workspace');
    }
}
