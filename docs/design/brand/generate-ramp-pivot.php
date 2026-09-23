<?php
/**
 * generate-ramp-pivot.php — generalises generate-ramp.php to an
 * arbitrary pivot slot. generate-ramp.php always anchors its one input
 * hex at slot 600; this script anchors it at whichever slot the caller
 * names, because not every brand anchor sits at the ramp's 600
 * position (Sage: 300, Sand: 200, and now every FFI anchor below).
 * Method is otherwise identical: interpolate lightness along the same
 * curve the ramp already uses, holding hue+saturation fixed at the
 * pivot's own H/S.
 *
 * Usage: php generate-ramp-pivot.php <#RRGGBB> <pivot-slot 50|100|...|950>
 */

const SLOTS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

// Lightness curve borrowed verbatim from generate-ramp.php's own 600-pivot
// table, expressed as a fraction of the pivot's lightness per slot offset
// from 600 — this file re-derives it generically off whatever slot is
// asked for, rather than only ever supporting 600.
const CURVE_L_AT_600_PIVOT = [
    50 => 0.97, 100 => 0.925, 200 => 0.855, 300 => 0.755, 400 => 0.639,
    500 => 0.527, 600 => null, 700 => 0.345, 800 => 0.284, 900 => 0.237,
    950 => 0.122,
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

// Re-express the 600-pivot curve as ratios relative to the 600 lightness,
// then apply those ratios to whatever lightness the real pivot slot has —
// this keeps the visual "shape" of the ramp regardless of which slot anchors it.
$curveAt600 = CURVE_L_AT_600_PIVOT;
$curveAt600[600] = 0.527; // the same L generate-ramp.php's own 600 slot assumes as its reference
$pivotRatio = $curveAt600[$pivotArg] / $curveAt600[600];

foreach (SLOTS as $slot) {
    if ($slot === $pivotArg) {
        echo "$slot: $anchorHex (anchor)\n";
        continue;
    }
    $ratio = ($curveAt600[$slot] / $curveAt600[600]) / $pivotRatio;
    $l = max(0.02, min(0.98, $pivotL * $ratio));
    echo "$slot: " . hslToHex($h, $s, $l) . "\n";
}
