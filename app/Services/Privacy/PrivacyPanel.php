<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\DataExport;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\URL;

/** Account › Your data props (D98, resources/js/types/privacy.ts). */
final class PrivacyPanel
{
    /**
     * @return array{graceDays: int, exportTtlDays: int, exports: array<int, array<string, mixed>>, deletion: array{scheduledFor: string}|null, ownedWorkspaces: array<int, array<string, mixed>>}
     */
    public function for(User $user): array
    {
        $exports = DataExport::query()->with('workspace')->where('user_id', $user->id)
            ->latest('id')->limit(5)->get()
            ->map(fn (DataExport $e): array => [
                'id' => $e->id,
                'kind' => $e->kind,
                'label' => $e->kind === DataExport::WORKSPACE ? ($e->workspace->name ?? 'Workspace').' — all companies' : 'Your personal data',
                'status' => $e->status->value,
                'createdAt' => $e->created_at?->toIso8601String(),
                'expiresAt' => $e->expires_at?->toIso8601String(),
                'url' => $e->isDownloadable() && $e->expires_at !== null
                    ? URL::temporarySignedRoute('privacy.exports.download', $e->expires_at, ['export' => $e->id])
                    : null,
            ])->values()->all();

        return [
            'graceDays' => (int) config('privacy.grace_days'),
            'exportTtlDays' => (int) config('privacy.export_ttl_days'),
            'exports' => $exports,
            'deletion' => $user->deletion_scheduled_for !== null ? ['scheduledFor' => $user->deletion_scheduled_for->toIso8601String()] : null,
            'ownedWorkspaces' => Workspace::query()->where('owner_id', $user->id)->withCount('companies')->orderBy('name')->get()
                ->map(fn (Workspace $w): array => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'companies' => (int) $w->getAttribute('companies_count'),
                    'deletion' => $w->deletion_scheduled_for !== null ? ['scheduledFor' => $w->deletion_scheduled_for->toIso8601String()] : null,
                ])->values()->all(),
        ];
    }
}
