<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Privacy\AnonymiseUser;
use App\Actions\Privacy\PurgeWorkspace;
use App\Models\DataExport;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Nightly (D98): erases workspaces and accounts whose grace period is over, and deletes data
 * export files past their link's expiry. Workspaces first, so an owner who deleted their
 * workspace and then their account is erased in the right order.
 */
final class PrivacyPurge extends Command
{
    protected $signature = 'coffer:privacy-purge';

    protected $description = 'Erase workspaces and accounts past their deletion grace period; delete expired data exports';

    public function handle(PurgeWorkspace $purge, AnonymiseUser $anonymise): int
    {
        $failed = false;

        foreach (Workspace::query()->whereNotNull('deletion_scheduled_for')->where('deletion_scheduled_for', '<=', now())->get() as $workspace) {
            try {
                $purge->handle($workspace);
                $this->line("Erased workspace {$workspace->id}.");
            } catch (Throwable $e) {
                $failed = true;
                Log::critical('Workspace erasure failed', ['workspace' => $workspace->id, 'exception' => $e::class, 'message' => $e->getMessage()]);
                $this->error("Workspace {$workspace->id}: ".$e->getMessage());
            }
        }

        foreach (User::query()->whereNotNull('deletion_scheduled_for')->where('deletion_scheduled_for', '<=', now())->get() as $user) {
            if (Workspace::query()->where('owner_id', $user->id)->exists()) {
                // Still owns a workspace (its own erasure failed or was cancelled): wait.
                $this->warn("Account {$user->id} still owns a workspace; not erased.");

                continue;
            }

            $anonymise->handle($user);
            $this->line("Erased account {$user->id}.");
        }

        $disk = Storage::disk((string) config('privacy.disk'));

        foreach (DataExport::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->get() as $export) {
            if ($export->path !== null) {
                $disk->delete($export->path);
            }

            $export->delete();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
