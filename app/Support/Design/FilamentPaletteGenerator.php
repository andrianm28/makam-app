<?php

declare(strict_types=1);

namespace App\Support\Design;

use RuntimeException;

/**
 * FilamentPaletteGenerator — resolves OQ-09 (design-system.md §8.3).
 *
 * Filament resolves panel colours in PHP and cannot read a CSS custom
 * property, so `AdminPanelProvider::panel()->colors([...])` has always
 * needed a plain PHP array. design-system.md §8.3 originally showed that
 * array hand-written, hex values copy-pasted from tokens.css — an
 * acknowledged "KNOWN GAP" the document itself flags, because a hand-copy
 * has no protection against ever drifting from the real source of truth.
 *
 * This class is the fix: it PARSES resources/css/tokens.css and derives the
 * array from it. It never hardcodes a colour value itself — every hex in the
 * generated output was read out of tokens.css at generation time, not typed
 * here. If tokens.css changes, regenerating reproduces the change
 * automatically; the two artisan commands built alongside this class
 * (`design:generate-filament-palette` / `design:verify-filament-palette`)
 * are the only supported way to update or check the generated file.
 *
 * Deliberately has NO Laravel/Illuminate dependency (no facades, no
 * container) — every method is a pure function of its string/array inputs.
 * This lets the parsing and diff logic be exercised directly with the
 * plain `php` CLI on a host where `composer install` has not run and
 * `php artisan` is therefore unavailable (this repository's current state;
 * see this batch's report for what was and was not run here).
 */
final class FilamentPaletteGenerator
{
    /**
     * tokens.css colour family -> Filament panel `colors()` key.
     *
     * `secondary` ("Sandstone") is deliberately EXCLUDED: design-system.md
     * §1.2b restricts it to surface/accent use only — "never a filled
     * badge, alert, or button" — and design-system.md §8.3's own
     * `colors()` snippet only ever defined six keys: primary, success,
     * warning, danger, info, gray. This mirrors that, rather than inventing
     * a seventh Filament colour design-system.md never asked for.
     *
     * `neutral` maps to Filament's conventional `gray` key, matching the
     * §8.3 snippet's `'gray' => Color::hex(neutral-600's value)` line exactly.
     *
     * @var array<string, string>
     */
    private const FAMILY_TO_FILAMENT_KEY = [
        'primary' => 'primary',
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        'info' => 'info',
        'neutral' => 'gray',
    ];

    /**
     * Parse resources/css/tokens.css and return the Filament-shaped colour
     * palette: `['primary' => [50 => <hex>, ..., 950 => <hex>], ...]`.
     *
     * Reads every `--color-{family}-{shade}: #hex;` declaration regardless
     * of where in the file it appears (today they only appear inside the
     * `@theme { }` block per tokens.css's own structure — §1 PRIMITIVES).
     * Families not in FAMILY_TO_FILAMENT_KEY (i.e. `secondary`) are parsed
     * but intentionally dropped, not because they were missed.
     *
     * @return array<string, array<int, string>>
     */
    public static function parseTokens(string $tokensCssPath): array
    {
        if (! is_file($tokensCssPath)) {
            throw new RuntimeException("tokens.css not found at: {$tokensCssPath}");
        }

        $css = file_get_contents($tokensCssPath);

        if ($css === false) {
            throw new RuntimeException("Unable to read tokens.css at: {$tokensCssPath}");
        }

        $pattern = '/--color-(primary|secondary|neutral|success|warning|danger|info)-(\d+)\s*:\s*(#[0-9A-Fa-f]{3,8})\s*;/';
        preg_match_all($pattern, $css, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            throw new RuntimeException(
                "No --color-<family>-<shade> tokens found in {$tokensCssPath} — ".
                'either the file moved/changed shape, or this parser needs updating.'
            );
        }

        $palette = [];

        foreach ($matches as $match) {
            $family = $match[1];
            $filamentKey = self::FAMILY_TO_FILAMENT_KEY[$family] ?? null;

            if ($filamentKey === null) {
                // `secondary` — not part of the Filament palette (see the
                // constant's doc comment above). Not an error.
                continue;
            }

            $shade = (int) $match[2];
            $hex = strtoupper($match[3]);

            $palette[$filamentKey][$shade] = $hex;
        }

        foreach (array_keys($palette) as $filamentKey) {
            ksort($palette[$filamentKey]);
        }

        ksort($palette);

        $expectedKeys = array_values(array_unique(array_values(self::FAMILY_TO_FILAMENT_KEY)));
        $missing = array_diff($expectedKeys, array_keys($palette));

        if ($missing !== []) {
            throw new RuntimeException(
                'tokens.css is missing an entire colour family Filament needs: '.
                implode(', ', $missing)
            );
        }

        return $palette;
    }

    /**
     * Render the generated PHP source for the palette file. Deterministic:
     * the same $palette input always produces byte-identical output, so
     * `design:verify-filament-palette` can compare by re-parsing and
     * re-rendering rather than relying on fragile text diffing against a
     * file that might carry stale formatting or comments.
     *
     * @param  array<string, array<int, string>>  $palette
     */
    public static function render(array $palette): string
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * GENERATED FILE — DO NOT EDIT BY HAND.';
        $lines[] = ' *';
        $lines[] = ' * Generated by: php artisan design:generate-filament-palette';
        $lines[] = ' * Source:       resources/css/tokens.css';
        $lines[] = ' *';
        $lines[] = ' * Resolves design-system.md §8.3 OQ-09: Filament resolves panel colours';
        $lines[] = ' * in PHP and cannot read a CSS custom property, so this array is';
        $lines[] = ' * generated from tokens.css rather than hand-maintained. Regenerate';
        $lines[] = ' * after any token change:';
        $lines[] = ' *';
        $lines[] = ' *   php artisan design:generate-filament-palette';
        $lines[] = ' *';
        $lines[] = ' * and confirm there is no drift with:';
        $lines[] = ' *';
        $lines[] = ' *   php artisan design:verify-filament-palette';
        $lines[] = ' *';
        $lines[] = ' * (design-system.md §9.5 CI gate 6). A manual edit here will be silently';
        $lines[] = ' * overwritten by the next regeneration and will fail the verify command';
        $lines[] = ' * until then.';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'return '.self::exportArray($palette).';';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Structural diff between the palette parsed fresh from tokens.css and
     * the array committed in the generated file. Returns a list of
     * human-readable difference lines; empty means no drift.
     *
     * @param  array<string, array<int, string>>  $expected  from parseTokens()
     * @param  array<string, array<int, string>>  $actual  from `require`-ing the generated file
     * @return list<string>
     */
    public static function diff(array $expected, array $actual): array
    {
        $lines = [];

        foreach ($expected as $family => $shades) {
            if (! array_key_exists($family, $actual)) {
                $lines[] = "missing colour family '{$family}'";

                continue;
            }

            foreach ($shades as $shade => $hex) {
                $actualHex = $actual[$family][$shade] ?? null;

                if ($actualHex === null) {
                    $lines[] = "missing '{$family}.{$shade}'";
                } elseif (strtoupper((string) $actualHex) !== $hex) {
                    $lines[] = "'{$family}.{$shade}' expected {$hex}, generated file has {$actualHex}";
                }
            }
        }

        foreach ($actual as $family => $shades) {
            if (! array_key_exists($family, $expected)) {
                $lines[] = "generated file has stale colour family '{$family}' no longer in tokens.css";

                continue;
            }

            foreach (array_keys($shades) as $shade) {
                if (! array_key_exists($shade, $expected[$family])) {
                    $lines[] = "generated file has stale shade '{$family}.{$shade}' no longer in tokens.css";
                }
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, array<int, string>>  $palette
     */
    private static function exportArray(array $palette): string
    {
        $familyLines = [];

        foreach ($palette as $family => $shades) {
            $shadeLines = [];

            foreach ($shades as $shade => $hex) {
                $shadeLines[] = "        {$shade} => '{$hex}',";
            }

            $familyLines[] = "    '{$family}' => [\n".implode("\n", $shadeLines)."\n    ],";
        }

        return "[\n".implode("\n", $familyLines)."\n]";
    }
}
