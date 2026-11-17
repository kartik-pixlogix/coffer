<?php

declare(strict_types=1);

namespace App\Enums;

/** What a Contact / Book a demo request is about (spec §21, D84). */
enum ContactTopic: string
{
    case Demo = 'demo';
    case Sales = 'sales';
    case Support = 'support';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Demo => 'Book a demo',
            self::Sales => 'Pricing or Enterprise',
            self::Support => 'Help with my account',
            self::Other => 'Something else',
        };
    }
}
