<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\JournalLine;
use App\Models\Project;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Projects (spec §16): name, client and status. Used by timesheets, income
 * and expenses. A project in use can only be archived; unused ones can be deleted.
 */
final class ProjectsController extends Controller
{
    public function index(CurrentCompany $current): Response
    {
        $company = $current->require();
        $lines = JournalLine::query()
            ->where('company_id', $company->id)
            ->whereNotNull('project_id')
            ->selectRaw('project_id, COUNT(DISTINCT journal_entry_id) as n')
            ->groupBy('project_id')
            ->pluck('n', 'project_id');
        $projects = Project::query()->orderBy('status')->orderBy('name')->get();

        return Inertia::render('settings/projects', [
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'client' => $p->client,
                'status' => $p->status->value,
                'records' => (int) ($lines[$p->id] ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request, CurrentCompany $current): RedirectResponse
    {
        $data = $this->validated($request, $current, null);
        $project = Project::query()->create($data + ['status' => ProjectStatus::Active]);

        activity('projects')->performedOn($project)->causedBy($request->user())->log('added a project');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$project->name} added"]);

        return back();
    }

    public function update(Request $request, Project $project, CurrentCompany $current): RedirectResponse
    {
        $data = $this->validated($request, $current, $project);
        $project->update($data);

        activity('projects')->performedOn($project)->causedBy($request->user())->log('updated a project');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$project->name} updated"]);

        return back();
    }

    /** Archive or restore: archived projects can't be picked for new records. */
    public function status(Request $request, Project $project): RedirectResponse
    {
        $archive = $project->status === ProjectStatus::Active;
        $project->update(['status' => $archive ? ProjectStatus::Archived : ProjectStatus::Active]);

        activity('projects')->performedOn($project)->causedBy($request->user())->log($archive ? 'archived a project' : 'restored a project');
        Inertia::flash('toast', ['type' => 'success', 'message' => $archive ? "{$project->name} archived" : "{$project->name} restored"]);

        return back();
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        if ($project->inUse()) {
            throw ValidationException::withMessages(['project' => "{$project->name} is on existing records, so it can only be archived."]);
        }

        $project->delete();

        activity('projects')->performedOn($project)->causedBy($request->user())->log('deleted a project');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$project->name} deleted"]);

        return back();
    }

    /** @return array{name: string, client: string|null} */
    private function validated(Request $request, CurrentCompany $current, ?Project $project): array
    {
        /** @var array{name: string, client?: string|null} $data */
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('projects', 'name')->where('company_id', $current->require()->id)->ignore($project?->id),
            ],
            'client' => ['nullable', 'string', 'max:120'],
        ], [
            'name.required' => 'Add the project’s name.',
            'name.unique' => 'There’s already a project with this name.',
        ]);

        return [
            'name' => trim($data['name']),
            'client' => filled($data['client'] ?? null) ? trim((string) $data['client']) : null,
        ];
    }
}
