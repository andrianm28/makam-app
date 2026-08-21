<?php

declare(strict_types=1);

// Usage: php generate-ramp.php <anchor-hex> <family-name>
// Derives an 11-step 50-950 tint/shade ramp from a single anchor color,
// matching the lightness-step CURVE (not absolute L values) of the
// existing tokens.css primary ramp — already proven to produce an
// accessible result end-to-end — holding hue+saturation fixed at the
// anchor's own HSL values. Prints each shade's hex plus its WCAG
// contrast ratio against white (the fill/button usage check).

function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function rgbToHsl(int $r, int $g, int $b): array
{
    $r /= 255;
    $g /= 255;
    $b /= 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) {
        return [0.0, 0.0, $l];
    }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match ($max) {
        $r => fmod((($g - $b) / $d), 6),
        $g => (($b - $r) / $d) + 2,
        default => (($r - $g) / $d) + 4,
    };
    $h *= 60;
    if ($h < 0) {
        $h += 360;
    }

    return [$h, $s, $l];
}

function hslToRgb(float $h, float $s, float $l): array
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60 => [$c, $x, 0],
        $h < 120 => [$x, $c, 0],
        $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c],
        $h < 300 => [$x, 0, $c],
        default => [$c, 0, $x],
    };

    return [
        (int) round(($r + $m) * 255),
        (int) round(($g + $m) * 255),
        (int) round(($b + $m) * 255),
    ];
}

function relativeLuminance(int $r, int $g, int $b): float
{
    $chan = function ($v) {
        $v /= 255;

        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $chan($r) + 0.7152 * $chan($g) + 0.0722 * $chan($b);
}

function contrastRatio(array $rgb1, array $rgb2): float
{
    $l1 = relativeLuminance($rgb1[0], $rgb1[1], $rgb1[2]);
    $l2 = relativeLuminance($rgb2[0], $rgb2[1], $rgb2[2]);
    [$lighter, $darker] = $l1 > $l2 ? [$l1, $l2] : [$l2, $l1];

    return ($lighter + 0.05) / ($darker + 0.05);
}

// Today's ACTUAL primary ramp's own lightness curve, expressed as each
// shade's fractional POSITION between the family's 600 shade and the
// relevant endpoint (white for lighter shades, black for darker ones) —
// e.g. shade 50's position is how far 50's L sits along the [600-L, 1.0]
// interval. A position-based (not multiplicative-ratio) curve is what
// makes this scale correctly to a new anchor whose own lightness differs
// notably from the old anchor's — a multiplicative ratio blows up past
// 1.0 and clips every light shade to the same near-white value once the
// new anchor is lighter than the old one (confirmed live while building
// this script against the real secondary anchor). Computed directly from
// resources/css/tokens.css's current, already-AA-verified primary ramp
// (not invented) — the new ramp inherits a curve shape already proven to
// work end-to-end.
$oldAnchorL = 0.24314; // current primary-600's own L
$oldShadeL = [
    50 => 0.959, 100 => 0.906, 200 => 0.800, 300 => 0.657, 400 => 0.518, 500 => 0.431,
    600 => $oldAnchorL,
    700 => 0.200, 800 => 0.159, 900 => 0.120, 950 => 0.075,
];
$curvePositions = [];
foreach ($oldShadeL as $shade => $l) {
    $curvePositions[$shade] = $shade < 600
        ? ($l - $oldAnchorL) / (1 - $oldAnchorL)   // fraction of the way from anchor to white
        : ($shade === 600 ? 0.0 : ($oldAnchorL - $l) / $oldAnchorL); // fraction toward black
}

$anchorHex = $argv[1] ?? null;
$familyName = $argv[2] ?? 'family';
if ($anchorHex === null) {
    fwrite(STDERR, "Usage: php generate-ramp.php <anchor-hex> <family-name>\n");
    exit(1);
}

[$ar, $ag, $ab] = hexToRgb($anchorHex);
[$h, $s, $anchorL] = rgbToHsl($ar, $ag, $ab);

$white = [255, 255, 255];
$hexes = [];

foreach ($curvePositions as $shade => $pos) {
    $l = $shade < 600
        ? $anchorL + $pos * (1 - $anchorL)
        : ($shade === 600 ? $anchorL : $anchorL - $pos * $anchorL);
    $l = min(0.98, max(0.02, $l));
    [$r, $g, $b] = hslToRgb($h, $s, $l);
    $hex = sprintf('#%02X%02X%02X', $r, $g, $b);
    $hexes[$shade] = $hex;
    $contrastVsWhite = contrastRatio([$r, $g, $b], $white);
    $aaPass = $contrastVsWhite >= 4.5 ? 'AA-white-OK' : 'below-4.5';
    printf("--color-%s-%d: %s;  /* white-text contrast %.2f:1 (%s) */\n", $familyName, $shade, $hex, $contrastVsWhite, $aaPass);
}

// Text-on-tint spot check: the 700/800/900 shades used as text color on
// the family's own 50 tint (design-system.md's established
// muted-text-on-tint pattern, e.g. primary-700 as link text on
// primary-50 background).
fwrite(STDERR, "\ntext-on-{$familyName}-50 spot checks:\n");
[$tr, $tg, $tb] = hexToRgb($hexes[50]);
foreach ([700, 800, 900] as $shade) {
    [$sr, $sg, $sb] = hexToRgb($hexes[$shade]);
    $c = contrastRatio([$sr, $sg, $sb], [$tr, $tg, $tb]);
    fwrite(STDERR, sprintf("  %s-%d on %s-50: %.2f:1 (%s)\n", $familyName, $shade, $familyName, $c, $c >= 4.5 ? 'AA-OK' : 'below-4.5'));
}
