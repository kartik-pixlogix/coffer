<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's favourite item (spec §14.1: favourite is per user).
 *
 * @property int $id
 * @property int $vault_item_id
 * @property int $user_id
 */
#[Fillable(['company_id', 'vault_item_id', 'user_id', 'created_at'])]
class VaultFavourite extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;
}
