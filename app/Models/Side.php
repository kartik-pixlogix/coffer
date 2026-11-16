<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * JV only: a parent organisation with a share of the venture. Side shares total 100%.
 *
 * @property int $id
 * @property string $name
 * @property int $equity_bps
 * @property int $position
 * @property-read Collection<int, Partner> $partners
 */
#[Fillable(['company_id', 'name', 'equity_bps', 'position'])]
class Side extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SideFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'equity_bps' => 'integer',
        ];
    }

    /** @return HasMany<Partner, $this> */
    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class)->whereNull('archived_at')->orderBy('position')->orderBy('id');
    }
}
