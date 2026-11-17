<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The database channel, also filling the `company_id` and `category` columns from the
 * payload, so the bell can scope and filter rows with plain indexed queries (D78).
 */
final class CompanyDatabaseChannel extends DatabaseChannel
{
    /** @return array<string, mixed> */
    protected function buildPayload($notifiable, Notification $notification)
    {
        $payload = parent::buildPayload($notifiable, $notification);
        $data = is_array($payload['data']) ? $payload['data'] : [];

        return [
            ...$payload,
            'company_id' => isset($data['company_id']) && is_int($data['company_id']) ? $data['company_id'] : null,
            'category' => isset($data['category']) && is_string($data['category']) ? $data['category'] : null,
        ];
    }
}
