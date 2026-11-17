<?php

declare(strict_types=1);

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use App\Models\Plan;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

/** Plans editor (spec §20/§22, D87): every change is written to the platform activity log. */
final class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function afterSave(): void
    {
        /** @var Plan $plan */
        $plan = $this->getRecord();

        activity('platform')
            ->causedBy(Auth::guard('platform')->user())
            ->performedOn($plan)
            ->withProperties(['changes' => $plan->getChanges()])
            ->log('plan updated');
    }
}
