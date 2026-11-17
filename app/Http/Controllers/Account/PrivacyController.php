<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Privacy\ScheduleDeletion;
use App\Enums\ExportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateDataExport;
use App\Models\DataExport;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Account › Your data (spec §23 "DPDPA-ready", D98). Everyone can export their own data and
 * delete their account; only a workspace's owner can export or delete the workspace. Each
 * deletion needs the password and a typed confirmation, and waits out the grace period.
 */
final class PrivacyController extends Controller
{
    public function export(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'kind' => ['required', Rule::in([DataExport::ACCOUNT, DataExport::WORKSPACE])],
            'workspace_id' => ['required_if:kind,'.DataExport::WORKSPACE, 'nullable', 'integer'],
        ]);

        $workspace = null;

        if ($data['kind'] === DataExport::WORKSPACE) {
            $workspace = Workspace::query()->find($data['workspace_id']);
            abort_unless($workspace instanceof Workspace && $workspace->isOwnedBy($user), 403);
        }

        $export = DataExport::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace?->id,
            'kind' => $data['kind'],
            'status' => ExportStatus::Queued,
        ]);

        activity('privacy')->performedOn($export)->causedBy($user)->log($workspace ? 'Workspace export requested' : 'Personal data export requested');
        GenerateDataExport::dispatch($export->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'We’re preparing your export. It appears here when it’s ready.']);

        return back();
    }

    public function download(Request $request, DataExport $export): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()?->id && $export->isDownloadable() && $export->path !== null, 404);

        activity('privacy')->performedOn($export)->causedBy($request->user())->log('Data export downloaded');

        return Storage::disk((string) config('privacy.disk'))->download($export->path, "coffer-{$export->kind}-export-{$export->id}.zip");
    }

    public function deleteAccount(Request $request, ScheduleDeletion $schedule): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate([
            'password' => ['required', 'current_password'],
            'confirmation' => ['required', Rule::in(['DELETE'])],
        ], ['confirmation.in' => 'Type DELETE to confirm.']);

        $when = $schedule->account($user);

        Inertia::flash('toast', ['type' => 'warning', 'message' => 'Your account will be deleted on '.$when->format('j M Y').'. You can keep it until then.']);

        return back();
    }

    public function keepAccount(Request $request, ScheduleDeletion $schedule): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $schedule->keepAccount($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your account stays. Nothing was deleted.']);

        return back();
    }

    public function deleteWorkspace(Request $request, Workspace $workspace, ScheduleDeletion $schedule): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($workspace->isOwnedBy($user), 403);

        $request->validate([
            'password' => ['required', 'current_password'],
            'confirmation' => ['required', Rule::in([$workspace->name])],
        ], ['confirmation.in' => 'Type the workspace name exactly to confirm.']);

        $when = $schedule->workspace($workspace, $user);

        Inertia::flash('toast', ['type' => 'warning', 'message' => "{$workspace->name} will be deleted on ".$when->format('j M Y').'. Its subscription is cancelled.']);

        return back();
    }

    public function keepWorkspace(Request $request, Workspace $workspace, ScheduleDeletion $schedule): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($workspace->isOwnedBy($user), 403);

        $schedule->keepWorkspace($workspace, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$workspace->name} stays. Choose a plan in Billing to keep using it."]);

        return back();
    }
}
