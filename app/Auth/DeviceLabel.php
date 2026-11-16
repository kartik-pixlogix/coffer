<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * "Chrome on Windows", "Safari on iPhone"… from a user agent, for the sessions and
 * trusted-device lists. Deliberately small: browser family and platform only.
 */
final class DeviceLabel
{
    /** @var array<string, string> pattern → browser (order matters: Edge and Opera also say Chrome) */
    private const array BROWSERS = [
        '/Edg(e|A|iOS)?\//' => 'Edge',
        '/OPR\/|Opera/' => 'Opera',
        '/Firefox\/|FxiOS\//' => 'Firefox',
        '/CriOS\/|Chrome\//' => 'Chrome',
        '/Safari\//' => 'Safari',
    ];

    /** @var array<string, string> pattern → platform */
    private const array PLATFORMS = [
        '/iPhone/' => 'iPhone',
        '/iPad/' => 'iPad',
        '/Android/' => 'Android',
        '/Windows/' => 'Windows',
        '/Mac OS X|Macintosh/' => 'macOS',
        '/CrOS/' => 'ChromeOS',
        '/Linux/' => 'Linux',
    ];

    public static function from(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return 'Unknown device';
        }

        $browser = self::match(self::BROWSERS, $userAgent);
        $platform = self::match(self::PLATFORMS, $userAgent);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => 'Unknown device',
        };
    }

    public static function isMobile(?string $userAgent): bool
    {
        return $userAgent !== null && preg_match('/iPhone|iPad|Android|Mobile/', $userAgent) === 1;
    }

    /** @param array<string, string> $patterns */
    private static function match(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
