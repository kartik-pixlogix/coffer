<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DPDPA data export (D98): the requester's own personal data (`account`), or a whole
 * workspace for its owner (`workspace`). Not company-scoped: it belongs to a person.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $workspace_id
 * @property string $kind account | workspace
 * @property ExportStatus $status
 * @property string|null $path
 * @property int|null $size
 * @property string|null $error
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Workspace|null $workspace
 */
#[Fillable(['user_id', 'workspace_id', 'kind', 'status', 'path', 'size', 'error', 'expires_at'])]
class DataExport extends Model
{
    public const string ACCOUNT = 'account';

    public const string WORKSPACE = 'workspace';

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'expires_at' => 'datetime',
            'size' => 'integer',
        ];
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

    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Ready && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
