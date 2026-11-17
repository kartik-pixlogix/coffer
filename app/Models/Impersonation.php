<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One impersonation session (spec §22, D86): who (platform admin), whom (tenant user), why
 * and when it started and ended. Written when it starts; never edited except `ended_at`.
 *
 * @property int $id
 * @property int $platform_admin_id
 * @property int $user_id
 * @property int|null $workspace_id
 * @property string $reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property-read PlatformAdmin $admin
 * @property-read User $user
 * @property-read Workspace|null $workspace
 */
#[Fillable(['platform_admin_id', 'user_id', 'workspace_id', 'reason', 'ip_address', 'user_agent', 'started_at', 'ended_at'])]
class Impersonation extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
