<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's saved vault policies (spec §14.4 "Policies", D76). Read only through
 * App\Services\Vault\VaultPolicy::for(); no row means the defaults in config/vault.php.
 *
 * @property int $id
 * @property int $company_id
 * @property int $reauth_minutes
 * @property bool $fresh_code_each_reveal
 * @property int $idle_lock_minutes
 * @property bool $lock_on_close
 * @property int $clipboard_clear_seconds
 * @property int $reveal_seconds
 * @property string $export_who admins | admins_partners | none
 * @property bool $export_reason
 * @property bool $breach_checks
 * @property int $generator_min_length
 * @property int|null $updated_by
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'company_id', 'reauth_minutes', 'fresh_code_each_reveal', 'idle_lock_minutes', 'lock_on_close', 'clipboard_clear_seconds',
    'reveal_seconds', 'export_who', 'export_reason', 'breach_checks', 'generator_min_length', 'updated_by',
])]
class VaultPolicySetting extends Model
{
    use BelongsToCompany;

    protected $table = 'vault_policies';

    protected function casts(): array
    {
        return [
            'reauth_minutes' => 'integer',
            'fresh_code_each_reveal' => 'boolean',
            'idle_lock_minutes' => 'integer',
            'lock_on_close' => 'boolean',
            'clipboard_clear_seconds' => 'integer',
            'reveal_seconds' => 'integer',
            'export_reason' => 'boolean',
            'breach_checks' => 'boolean',
            'generator_min_length' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
