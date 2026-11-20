<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * A user's brand gradient (D147): one of the presets in resources/js/lib/accent-presets.json
 * (shared with the picker) or three custom stops and an angle. It replaces the lime token
 * family (`--lime`, `--lime-mid`, `--lime-tint`, `--gradient-lime`, `--on-lime` and the
 * `--selected` tint), so every fill that used lime follows it. Text on it is dark or white,
 * whichever reads better on all three stops. Only `#rrggbb` values and whole-degree angles
 * get in, so the generated CSS can't carry anything else. resources/js/lib/accent.ts mirrors
 * this for the live preview.
 */
final readonly class Accent
{
    public const string DEFAULT = 'lime';

    public const string CUSTOM = 'custom';

    public const int DEFAULT_ANGLE = 135;

    public const string DARK_TEXT = '#1c1c1e';

    public const string LIGHT_TEXT = '#ffffff';

    /** The dark theme's card surface, which the dark `--selected` tint is mixed into. */
    private const string DARK_SURFACE = '#15161a';

    /** @param list<string> $stops */
    private function __construct(
        public string $preset,
        public array $stops,
        public int $angle,
    ) {}

    /** @return array<string, array{name: string, stops: list<string>}> */
    public static function presets(): array
    {
        /** @var array<string, array{name: string, stops: list<string>}>|null $presets */
        static $presets = null;

        if ($presets === null) {
            /** @var array<string, array{name: string, stops: list<string>}> $decoded */
            $decoded = json_decode((string) file_get_contents(resource_path('js/lib/accent-presets.json')), true, flags: JSON_THROW_ON_ERROR);
            $presets = $decoded;
        }

        return $presets;
    }

    public static function default(): self
    {
        return self::make(self::DEFAULT);
    }

    /**
     * @param  array<mixed>|null  $stops  custom only; anything but three #rrggbb strings is refused
     */
    public static function make(string $preset, ?array $stops = null, ?int $angle = null): self
    {
        $angle ??= self::DEFAULT_ANGLE;

        if ($angle < 0 || $angle > 360) {
            throw new InvalidArgumentException('The angle must be 0–360 degrees.');
        }

        if ($preset !== self::CUSTOM) {
            $known = self::presets()[$preset] ?? throw new InvalidArgumentException("No preset {$preset}.");

            return new self($preset, $known['stops'], self::DEFAULT_ANGLE);
        }

        if ($stops === null || count($stops) !== 3) {
            throw new InvalidArgumentException('A custom gradient has three colours.');
        }

        $clean = [];

        foreach ($stops as $stop) {
            if (! is_string($stop) || preg_match('/^#[0-9a-fA-F]{6}$/', $stop) !== 1) {
                throw new InvalidArgumentException('Colours are #rrggbb.');
            }

            $clean[] = strtolower($stop);
        }

        return new self(self::CUSTOM, $clean, $angle);
    }

    /** From the `users.accent` column: anything unreadable falls back to the default. */
    public static function fromStored(mixed $value): self
    {
        if (! is_array($value) || ! is_string($value['preset'] ?? null)) {
            return self::default();
        }

        try {
            $stops = is_array($value['stops'] ?? null) ? $value['stops'] : null;

            return self::make($value['preset'], $stops, is_int($value['angle'] ?? null) ? $value['angle'] : null);
        } catch (InvalidArgumentException) {
            return self::default();
        }
    }

    public function isDefault(): bool
    {
        return $this->preset === self::DEFAULT;
    }

    /** @return array{preset: string, stops?: list<string>, angle?: int}|null null keeps the column empty */
    public function toStored(): ?array
    {
        return match (true) {
            $this->isDefault() => null,
            $this->preset === self::CUSTOM => ['preset' => self::CUSTOM, 'stops' => $this->stops, 'angle' => $this->angle],
            default => ['preset' => $this->preset],
        };
    }

    /** @return array{preset: string, stops: list<string>, angle: int, onColor: string} */
    public function toArray(): array
    {
        return ['preset' => $this->preset, 'stops' => $this->stops, 'angle' => $this->angle, 'onColor' => $this->onColor()];
    }

    /** Dark or white text: the one with the better worst-case contrast across the stops. */
    public function onColor(): string
    {
        return $this->minContrast(self::DARK_TEXT) >= $this->minContrast(self::LIGHT_TEXT) ? self::DARK_TEXT : self::LIGHT_TEXT;
    }

    /** The lowest contrast ratio of a text colour against any of the three stops. */
    public function minContrast(?string $text = null): float
    {
        $text ??= $this->onColor();
        $lowest = INF;

        foreach ($this->stops as $stop) {
            $lowest = min($lowest, self::contrast($stop, $text));
        }

        return $lowest;
    }

    /** The `:root` overrides, or null for the default (the stylesheet's own lime). */
    public function css(): ?string
    {
        if ($this->isDefault()) {
            return null;
        }

        [$a, $b, $c] = $this->stops;
        $selectedLight = self::mix($a, '#ffffff', 0.3);
        $selectedDark = self::mix($a, self::DARK_SURFACE, 0.12);

        return ':root{--lime:'.$a.';--lime-mid:'.$b.';--lime-tint:'.$c
            .';--gradient-lime:linear-gradient('.$this->angle.'deg,'.$a.' 0%,'.$b.' 55%,'.$c.' 100%)'
            .';--on-lime:'.$this->onColor().';--selected:'.$selectedLight.'}'
            .':root[data-theme="dark"]{--selected:'.$selectedDark.'}';
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channel = function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /** `$amount` of `$color` over `$base`, as #rrggbb. */
    private static function mix(string $color, string $base, float $amount): string
    {
        $c = self::rgb($color);
        $b = self::rgb($base);

        return '#'.implode('', array_map(
            fn (int $i): string => str_pad(dechex((int) round($c[$i] * $amount + $b[$i] * (1 - $amount))), 2, '0', STR_PAD_LEFT),
            [0, 1, 2],
        ));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }
}
