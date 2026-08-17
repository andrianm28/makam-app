<?php

declare(strict_types=1);
// Usage: php docs/design/brand/sample-logo-colours.php docs/design/brand/source/logo.png
// Prints the average brown (hue 15–40°) and green (hue 90–160°) pixel colours.
$src = $argv[1] ?? null;
if ($src === null || ! is_file($src)) {
    fwrite(STDERR, "source PNG missing: {$src}\n");
    exit(1);
}
$im = imagecreatefrompng($src) ?: exit(1);
imagepalettetotruecolor($im);   // imagecolorat on a palette PNG returns an index, not RGBA
imagealphablending($im, false);
imagesavealpha($im, true);
$w = imagesx($im);
$h = imagesy($im);
$buckets = ['brown' => [0, 0, 0, 0], 'green' => [0, 0, 0, 0]];
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgba = imagecolorat($im, $x, $y);
        if ((($rgba >> 24) & 0x7F) === 127) {
            continue;
        }             // fully transparent
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;
        $mx = max($r, $g, $b);
        $mn = min($r, $g, $b);
        if ($mx > 245 && $mn > 235) {
            continue;
        }                    // background
        if ($mx - $mn < 20) {
            continue;
        }                            // grey/flat
        $d = $mx - $mn;
        $l = ($mx + $mn) / 510;
        if ($mx === 0) {
            continue;
        }
        if ($d === 0) {
            continue;
        }
        if ($mx === $r) {
            $hue = 60 * fmod((($g - $b) / $d) + 6, 6);
        } elseif ($mx === $g) {
            $hue = 60 * (($b - $r) / $d + 2);
        } else {
            $hue = 60 * (($r - $g) / $d + 4);
        }
        $key = ($hue >= 15 && $hue <= 40) ? 'brown' : (($hue >= 90 && $hue <= 160) ? 'green' : null);
        if ($key) {
            $buckets[$key][0] += $r;
            $buckets[$key][1] += $g;
            $buckets[$key][2] += $b;
            $buckets[$key][3]++;
        }
    }
}
foreach ($buckets as $name => [$r, $g, $b, $n]) {
    if ($n === 0) {
        fwrite(STDERR, "no {$name} pixels found\n");
        exit(1);
    }
    printf("%s #%02X%02X%02X  (%d px)\n", $name, intdiv($r, $n), intdiv($g, $n), intdiv($b, $n), $n);
}
