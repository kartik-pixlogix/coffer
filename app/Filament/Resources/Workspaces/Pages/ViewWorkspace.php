<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workspaces\Pages;

use App\Filament\Resources\Workspaces\WorkspaceResource;
use Filament\Resources\Pages\ViewRecord;

/** Workspace detail (D86): usage, subscription, people; Suspend / Unsuspend / Impersonate. */
final class ViewWorkspace extends ViewRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            WorkspaceResource::impersonateAction(),
            WorkspaceResource::changePlanAction(),
            WorkspaceResource::extendTrialAction(),
            WorkspaceResource::suspendAction(),
            WorkspaceResource::unsuspendAction(),
        ];
    }
}
