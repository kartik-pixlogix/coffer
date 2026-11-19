<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\Workspace;
use App\Services\Privacy\CompanyEraser;
use Illuminate\Support\Facades\DB;

/**
 * Erases a workspace for good (spec §23, D98), after its grace period: its companies with
 * every row, activity-log entry and file of theirs (CompanyEraser), drafts, subscription rows
 * and impersonation records, and the workspace. People keep their accounts (and other
 * workspaces); the ones who belonged only here land on "create a company" next time they sign
 * in. One transaction: it all goes or nothing does.
 */
final readonly class PurgeWorkspace
{
    public function __construct(private CompanyEraser $eraser) {}

    public function handle(Workspace $workspace): void
    {
        // Raw ids, so companies already deleted on their own (V4) go too.
        $companyIds = array_values(DB::table('companies')->where('workspace_id', $workspace->id)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $files = [];

        DB::transaction(function () use ($workspace, $companyIds, &$files): void {
            $files = $this->eraser->erase($companyIds);

            DB::table('company_drafts')->where('workspace_id', $workspace->id)->delete();
            DB::table('subscription_items')->whereIn('subscription_id', DB::table('subscriptions')->select('id')->where('workspace_id', $workspace->id))->delete();
            DB::table('subscriptions')->where('workspace_id', $workspace->id)->delete();
            DB::table('impersonations')->where('workspace_id', $workspace->id)->delete();
            DB::table('data_exports')->where('workspace_id', $workspace->id)->delete();

            activity('privacy')->withProperties(['workspace_id' => $workspace->id, 'companies' => count($companyIds)])->log('Workspace erased');
            DB::table('activity_log')->where('subject_type', $workspace->getMorphClass())->where('subject_id', $workspace->id)->delete();
            DB::table('workspaces')->where('id', $workspace->id)->delete();
        });

        $this->eraser->deleteFiles($files, $companyIds);
    }
}
