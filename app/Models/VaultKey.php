<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's vault data key, wrapped (AES-256-GCM) by the master key (spec §14.3, D66).
 * Read only through App\Services\Vault\VaultKeys; never scoped by the current company
 * because rotation runs across companies.
 *
 * @property int $id
 * @property int $company_id
 * @property int $version
 * @property string $wrapped_key
 * @property string $master_key_id
 * @property CarbonImmutable|null $retired_at
 */
#[Fillable(['company_id', 'version', 'wrapped_key', 'master_key_id', 'retired_at'])]
#[Hidden(['wrapped_key'])]
class VaultKey extends Model
{
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
