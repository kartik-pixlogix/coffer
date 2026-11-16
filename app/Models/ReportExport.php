<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportStatus;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A large report export made in the background (spec §15 "queued if large", D61). The
 * file sits on the private disk; only the user who asked can download it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $report
 * @property string $format pdf | xlsx
 * @property array<string, mixed> $params the period query
 * @property string $label "P&L PDF · 1 Apr – 15 Nov 2026"
 * @property string $filename
 * @property ExportStatus $status
 * @property string|null $path
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['company_id', 'user_id', 'report', 'format', 'params', 'label', 'filename', 'status', 'path', 'finished_at'])]
class ReportExport extends Model
{
    use BelongsToCompany;

    public const string DISK = 'local';

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'status' => ExportStatus::class,
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
