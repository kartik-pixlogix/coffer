<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * "Access ends" choices on the share drawer and on access requests (VaultShare.dc.html):
 * never, or 24 hours / 7 days / 30 days from when access is given.
 */
enum VaultAccessDuration: string
{
    case Never = 'never';
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function endsAt(?CarbonImmutable $from = null): ?CarbonImmutable
    {
        $from ??= CarbonImmutable::now();

        return match ($this) {
            self::Never => null,
            self::Day => $from->addHours(24),
            self::Week => $from->addDays(7),
            self::Month => $from->addDays(30),
        };
    }

    /** "for 24 hours", "no end date" (the request card's span). */
    public function span(): string
    {
        return match ($this) {
            self::Never => 'no end date',
            self::Day => 'for 24 hours',
            self::Week => 'for 7 days',
            self::Month => 'for 30 days',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Never',
            self::Day => '24 hours',
            self::Week => '7 days',
            self::Month => '30 days',
        };
    }
}
