<?php

namespace Pterodactyl\Services\Resellers;

use Pterodactyl\Models\Reseller;
use Illuminate\Support\Facades\Storage;

/**
 * The resolved white-label identity for a request: everything the Blade layer
 * needs, with no model access and no user-typed strings left unvalidated.
 */
class ResellerBranding
{
    /**
     * @param array<int, string> $brandRamp stop => "R G B"
     * @param array<int, string> $accentRamp stop => "R G B"
     */
    private function __construct(
        public readonly ?int $resellerId,
        public readonly ?string $appName,
        public readonly ?string $logoUrl,
        public readonly ?string $faviconUrl,
        public readonly ?string $brandHex,
        public readonly ?string $accentHex,
        public readonly array $brandRamp,
        public readonly array $accentRamp,
    ) {
    }

    /**
     * The stock Solstice theme — no overrides at all.
     */
    public static function none(): self
    {
        return new self(null, null, null, null, null, null, [], []);
    }

    public static function make(Reseller $reseller, ColorRampGenerator $generator): self
    {
        $brand = $generator->normalizeHex($reseller->brand_color);
        $accent = $generator->normalizeHex($reseller->accent_color);

        return new self(
            $reseller->id,
            $reseller->app_name ?: null,
            $reseller->logo_path ? Storage::disk('public')->url($reseller->logo_path) : null,
            $reseller->favicon_path ? Storage::disk('public')->url($reseller->favicon_path) : null,
            $brand,
            $accent,
            $brand ? $generator->ramp($brand) : [],
            $accent ? $generator->ramp($accent) : [],
        );
    }

    public function isEmpty(): bool
    {
        return is_null($this->appName)
            && is_null($this->logoUrl)
            && $this->brandRamp === []
            && $this->accentRamp === [];
    }

    /**
     * The CSS custom-property overrides, as a `--name: value;` string ready to
     * drop inside a :root block.
     *
     * Only the brand and accent ramps are overridden: the light and dark theme
     * blocks in tailwind.css both reference those through var(), so overriding
     * them once on :root re-colors both themes. The neutral ramp is deliberately
     * left alone — that's what keeps contrast readable whatever color is chosen.
     */
    public function cssVariables(): string
    {
        $lines = [];

        foreach ($this->brandRamp as $stop => $triplet) {
            $lines[] = sprintf('--brand-%d: %s;', $stop, $triplet);
        }

        foreach ($this->accentRamp as $stop => $triplet) {
            $lines[] = sprintf('--accent-%d: %s;', $stop, $triplet);
        }

        if ($this->accentHex && $this->brandHex) {
            $lines[] = sprintf('--gradient-from: %s;', $this->accentHex);
            $lines[] = sprintf('--gradient-to: %s;', $this->brandHex);
        }

        return implode("\n            ", $lines);
    }
}
