<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded timesheet CSV (spec §8.2). The file stays on the private disk until the
 * import finishes or is removed; `mapping` is column index → Coffer field (or null).
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $name
 * @property string $path
 * @property int $size bytes
 * @property int $rows data rows (header excluded)
 * @property list<string> $header
 * @property array<int, string|null>|null $mapping
 * @property ImportStatus $status
 * @property int $imported_count
 * @property int $imported_centi
 * @property int $skipped_count
 * @property string|null $errors_path
 * @property string|null $failure
 * @property CarbonImmutable|null $finished_at
 * @property-read User $user
 */
#[Fillable(['company_id', 'user_id', 'name', 'path', 'size', 'rows', 'header', 'mapping', 'status', 'imported_count', 'imported_centi', 'skipped_count', 'errors_path', 'failure', 'finished_at'])]
class TimesheetImport extends Model
{
    use BelongsToCompany;

    public const string DISK = 'local';

    protected function casts(): array
    {
        return [
            'header' => 'array',
            'mapping' => 'array',
            'status' => ImportStatus::class,
            'size' => 'integer',
            'rows' => 'integer',
            'imported_count' => 'integer',
            'imported_centi' => 'integer',
            'skipped_count' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
