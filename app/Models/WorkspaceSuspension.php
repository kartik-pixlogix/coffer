<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One suspension of a workspace (S6, D118): platform data, no company scope.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $reason
 * @property int|null $suspended_by
 * @property CarbonImmutable $suspended_at
 * @property CarbonImmutable|null $unsuspended_at
 * @property int|null $unsuspended_by
 */
final class WorkspaceSuspension extends Model
{
    protected $fillable = ['workspace_id', 'reason', 'suspended_by', 'suspended_at', 'unsuspended_at', 'unsuspended_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['suspended_at' => 'immutable_datetime', 'unsuspended_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function suspender(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'suspended_by');
    }

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function unsuspender(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'unsuspended_by');
    }
}
