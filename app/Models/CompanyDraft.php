<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The create-company wizard's "Save and exit" draft: one per user per workspace.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property int $step
 * @property array<string, mixed> $payload
 */
#[Fillable(['workspace_id', 'user_id', 'step', 'payload'])]
class CompanyDraft extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'step' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
