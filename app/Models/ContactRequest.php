<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactTopic;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A lead from the Contact / Book a demo form (spec §21, D84). Platform data, not tenant
 * data: no company, read in the super-admin panel.
 *
 * @property int $id
 * @property ContactTopic $topic
 * @property string $name
 * @property string $email
 * @property string|null $company
 * @property string|null $phone
 * @property string|null $team_size
 * @property string|null $message
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable|null $handled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['topic', 'name', 'email', 'company', 'phone', 'team_size', 'message', 'ip_address', 'user_agent', 'handled_at'])]
class ContactRequest extends Model
{
    protected function casts(): array
    {
        return [
            'topic' => ContactTopic::class,
            'handled_at' => 'datetime',
        ];
    }
}
