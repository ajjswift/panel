<?php

namespace Pterodactyl\Services\Resellers;

/**
 * Expands a single brand color into the 50–900 ramp the theme expects.
 *
 * The stock Solstice ramps (resources/scripts/assets/tailwind.css) are Tailwind's
 * hand-tuned palettes, which shift hue and saturation between stops. Those can't
 * be re-derived from one hex, so this instead mixes the base color toward white
 * and black in *linear* light — which gives a visually even ramp and, crucially,
 * reproduces the 500 stop exactly, so a reseller who picks the stock purple gets
 * the stock look at the shade that matters most.
 */
class ColorRampGenerator
{
    /**
     * How far each stop is mixed toward white (positive) or black (negative)
     * from the base color at 500.
     */
    private const MIX = [
        50 => 0.96,
        100 => 0.90,
        200 => 0.78,
        300 => 0.60,
        400 => 0.34,
        500 => 0.0,
        600 => -0.20,
        700 => -0.42,
        800 => -0.60,
        900 => -0.74,
    ];

    /**
     * Expand a hex color into a stop => "R G B" map, in the RGB-triplet form the
     * CSS custom properties consume.
     *
     * @return array<int, string>
     */
    public function ramp(string $hex): array
    {
        [$r, $g, $b] = $this->toRgb($hex);

        $ramp = [];
        foreach (self::MIX as $stop => $amount) {
            $target = $amount >= 0 ? 255.0 : 0.0;
            $ratio = abs($amount);

            $ramp[$stop] = sprintf(
                '%d %d %d',
                $this->mix($r, $target, $ratio),
                $this->mix($g, $target, $ratio),
                $this->mix($b, $target, $ratio),
            );
        }

        return $ramp;
    }

    /**
     * Normalise user input to `#rrggbb`, or null if it isn't a color. Callers
     * treat null as "use the stock theme" rather than erroring — branding is
     * cosmetic and should never break a page render.
     */
    public function normalizeHex(?string $hex): ?string
    {
        if (is_null($hex)) {
            return null;
        }

        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? '#' . strtolower($hex) : null;
    }

    /**
     * @return array{int, int, int}
     */
    private function toRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Mix a channel toward a target, doing the interpolation in linear light so
     * the ramp doesn't wash out in the middle the way a naive sRGB mix does.
     */
    private function mix(int $channel, float $target, float $ratio): int
    {
        $mixed = $this->toLinear($channel / 255) * (1 - $ratio)
            + $this->toLinear($target / 255) * $ratio;

        return (int) round($this->toSrgb($mixed) * 255);
    }

    private function toLinear(float $value): float
    {
        return $value <= 0.04045
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }

    private function toSrgb(float $value): float
    {
        return $value <= 0.0031308
            ? $value * 12.92
            : 1.055 * $value ** (1 / 2.4) - 0.055;
    }
}
