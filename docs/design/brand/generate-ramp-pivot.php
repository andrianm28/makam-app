<?php

declare(strict_types=1);

/**
 * generate-ramp-pivot.php — generalises generate-ramp.php to an
 * arbitrary pivot slot. generate-ramp.php always anchors its one input
 * hex at slot 600; this script anchors it at whichever slot the caller
 * names, because not every brand anchor sits at the ramp's 600
 * position (Sage: 300, Sand: 200, and now every FFI anchor below).
 * Equivalence to generate-ramp.php's method is proven exact only at pivot
 * 600 -- the two scripts produce byte-identical output there. At every
 * other pivot slot, this script re-expresses the same lightness curve
 * relative to the new pivot; that is not a bit-identical reproduction of
 * what generate-ramp.php itself would produce if run at that slot.
 *
 * Usage: php generate-ramp-pivot.php <#RRGGBB> <pivot-slot 50|100|...|950>
 */

const SLOTS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

// Lightness curve borrowed verbatim from generate-ramp.php's own 600-pivot
// table. These are the ACTUAL lightness values of the proven primary ramp,
// not generated — they are the canonical curve we re-express as positions
// and then apply to any new anchor.
const OLD_ANCHOR_L = 0.24314; // current primary-600's own L
const OLD_SHADE_L = [
    50 => 0.959, 100 => 0.906, 200 => 0.800, 300 => 0.657, 400 => 0.518, 500 => 0.431,
    600 => 0.24314,
    700 => 0.200, 800 => 0.159, 900 => 0.120, 950 => 0.075,
];

function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function rgbToHsl(int $r, int $g, int $b): array
{
    $r /= 255; $g /= 255; $b /= 255;
    $max = max($r, $g, $b); $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) return [0, 0, $l];
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = $max === $r ? fmod((($g - $b) / $d), 6) : ($max === $g ? (($b - $r) / $d) + 2 : (($r - $g) / $d) + 4);
    $h *= 60;
    if ($h < 0) $h += 360;
    return [$h, $s, $l];
}

function hslToHex(float $h, float $s, float $l): string
{
    $h = fmod($h, 360); if ($h < 0) $h += 360;
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
    };
    return sprintf('#%02X%02X%02X', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
}

[$anchorHex, $pivotArg] = [$argv[1] ?? null, (int) ($argv[2] ?? 0)];
if (!$anchorHex || !in_array($pivotArg, SLOTS, true)) {
    fwrite(STDERR, "Usage: php generate-ramp-pivot.php <#RRGGBB> <pivot-slot>\n");
    exit(1);
}

[$h, $s, $pivotL] = rgbToHsl(...hexToRgb($anchorHex));

// Compute position-based curve from the old reference curve.
// The original curve is anchored at slot 600 with lightness OLD_ANCHOR_L.
// We re-express it as positions relative to the OLD pivot slot (which may differ
// from 600), then apply those same positions to the NEW pivot's lightness.
$oldPivotL = OLD_SHADE_L[$pivotArg];

$curvePositions = [];
foreach (OLD_SHADE_L as $shade => $l) {
    if ($shade < $pivotArg) {
        // Fraction of the way from old pivot to white
        $curvePositions[$shade] = ($l - $oldPivotL) / (1 - $oldPivotL);
    } elseif ($shade === $pivotArg) {
        // The pivot slot itself has position 0
        $curvePositions[$shade] = 0.0;
    } else {
        // Fraction of the way from old pivot to black
        $curvePositions[$shade] = ($oldPivotL - $l) / $oldPivotL;
    }
}

// Apply the position-based curve to the new anchor
foreach (SLOTS as $slot) {
    if ($slot === $pivotArg) {
        echo "$slot: $anchorHex (anchor)\n";
        continue;
    }

    $pos = $curvePositions[$slot];
    if ($slot < $pivotArg) {
        // Fraction of the way from pivot to white
        $l = $pivotL + $pos * (1 - $pivotL);
    } else {
        // Fraction of the way from pivot to black
        $l = $pivotL - $pos * $pivotL;
    }

    // Clamp to valid range
    $l = max(0.02, min(0.98, $l));
    echo "$slot: " . hslToHex($h, $s, $l) . "\n";
}
