<?php

declare(strict_types=1);

namespace App\Support\Design;

use RuntimeException;

/**
 * BrandAssetBuilder — raster logo asset pipeline (brand-identity-adoption
 * plan, Task 3; spec docs/superpowers/specs/2026-08-17-brand-identity-design.md).
 *
 * Takes the stakeholder-supplied master artwork
 * (`docs/design/brand/source/logo.png` — a single 1054x1492 lockup with the
 * figure-8 + leaves mark above the "makam.co.id" wordmark) and derives the
 * full raster manifest a web app needs: a standalone mark (full colour and
 * an inverse/white variant), two lockup crops for header/footer use, and
 * the favicon.ico + apple-touch-icon.png web-convention icon files.
 *
 * Deliberately has NO Laravel/Illuminate dependency (no facades, no
 * container) — every method is a pure function of its string/array inputs,
 * the same "exercised directly with the plain `php` CLI" precedent
 * FilamentPaletteGenerator (app/Support/Design/FilamentPaletteGenerator.php)
 * establishes, because this host's PHP (8.3.6) is below composer.lock's
 * required floor (>=8.5) and `php artisan` cannot run here.
 *
 * Algorithm overview (see the four private helpers of the same name for the
 * exact per-pixel rules):
 *
 *   1. White-key: source has no alpha channel, only a near-white backdrop.
 *      When $whiteKey is true, any pixel with r,g,b all > 248 becomes fully
 *      transparent (GD alpha 127). No soft/two-threshold feathering — the
 *      brief flags this as PROVISIONAL (OQ-12) until official vector art
 *      replaces the raster source; a hard cutoff can leave a faint light
 *      fringe on anti-aliased edges, which is the accepted trade-off here.
 *   2. Mark/wordmark split: per-row opaque-pixel counts (post-keying), the
 *      widest zero-opaque row band is the separator. The brief specifies
 *      searching "inside the bottom 40% of height"; this implementation
 *      additionally restricts that search to the vertical extent of the
 *      image's own opaque content (its bounding box), not the full canvas.
 *      Reasoning: the REAL source (unlike the test's synthetic fixture) has
 *      a large blank margin below the wordmark before the canvas edge. A
 *      literal "bottom 40% of the whole canvas" search finds that trailing
 *      margin — not the true mark/wordmark separator — because it is wider.
 *      Restricting the search window to [bottomStart, contentBBox.maxY]
 *      excludes canvas padding while still searching only the lower portion
 *      of the artwork, and was verified against the real source: it finds a
 *      25px separator band (well above the 8px fail-closed floor) between
 *      the mark (ends ~y=1103) and the wordmark text (starts ~y=1129) of
 *      the real 1054x1492 source. See the batch's task-3 report for the
 *      verification script output.
 *   3. Inverse mark: per opaque pixel, RGB -> HSV hue/saturation; hue in
 *      [10 deg, 50 deg] (the brown/earth range Task 1 sampled) and
 *      saturation > 0.15 is replaced with white, alpha preserved. Green
 *      leaves (hue ~128 deg) are untouched by construction — they never
 *      fall in the recolour band.
 *   4. Exports: imagescale() with IMG_BICUBIC, PNG + WebP for every sized
 *      asset (imagewebp() availability is asserted, not silently skipped —
 *      the manifest contract requires both formats), and a hand-packed
 *      PNG-in-ICO favicon.ico (valid since Windows Vista, accepted by every
 *      modern browser).
 */
final class BrandAssetBuilder
{
    /**
     * White-key threshold: a pixel with r, g, and b all strictly greater
     * than this is treated as "background white" and keyed to transparent.
     */
    private const WHITE_KEY_THRESHOLD = 248;

    /**
     * GD's alpha channel runs 0 (fully opaque) .. 127 (fully transparent) —
     * half the range of a typical 0-255 alpha byte. 127 is "fully
     * transparent" throughout this class.
     */
    private const GD_ALPHA_TRANSPARENT = 127;

    /** Inverse-recolour hue band (degrees), inclusive both ends. */
    private const RECOLOR_HUE_MIN = 10.0;

    private const RECOLOR_HUE_MAX = 50.0;

    /** Inverse-recolour minimum saturation (0..1), exclusive. */
    private const RECOLOR_SATURATION_MIN = 0.15;

    /** Fail-closed floor for the mark/wordmark separator band, in pixels. */
    private const MIN_BAND_HEIGHT = 8;

    /**
     * The one literal hardcoded colour in this file: the inverse-mark
     * recolour target and the apple-touch-icon flatten backdrop. This is a
     * generated-artwork recolour target, not a UI design decision, so it is
     * exempted from GATE 2 (ci/verify-docs.sh) by an explicit, commented
     * path exclusion — the same precedent already used for
     * app/Support/Design/generated/FilamentPalette.php.
     */
    private const WHITE_HEX = '#FFFFFF';

    /**
     * Build the full brand asset manifest from $sourcePng.
     *
     * @return list<string> sorted list of the ten emitted asset basenames
     *                      (eight go to $brandOutDir, two — favicon.ico and
     *                      apple-touch-icon.png — go to $publicRootDir by
     *                      web convention; the manifest itself is the flat,
     *                      sorted basename list the brief specifies).
     */
    public static function build(
        string $sourcePng,
        string $brandOutDir,
        string $publicRootDir,
        bool $whiteKey
    ): array {
        if (! is_file($sourcePng)) {
            throw new RuntimeException("BrandAssetBuilder: source PNG not found: {$sourcePng}");
        }

        if (! function_exists('imagewebp')) {
            throw new RuntimeException(
                'BrandAssetBuilder: GD built without WebP support (imagewebp() missing) — '.
                'the manifest contract requires both PNG and WebP for every sized asset; refusing to skip silently.'
            );
        }

        self::ensureDirectory($brandOutDir);
        self::ensureDirectory($publicRootDir);

        $src = self::loadNormalized($sourcePng);

        if ($whiteKey) {
            self::applyWhiteKey($src);
        }

        $overallBBox = self::opaqueBoundingBox($src);

        if ($overallBBox === null) {
            throw new RuntimeException('BrandAssetBuilder: source image has no opaque content after keying.');
        }

        [$bandStart, $bandEnd] = self::findSeparatorBand($src, $overallBBox);

        $markRowBBox = self::opaqueBoundingBoxInRows($src, $overallBBox['minY'], $bandStart - 1);
        $wordmarkRowBBox = self::opaqueBoundingBoxInRows($src, $bandEnd + 1, $overallBBox['maxY']);

        if ($markRowBBox === null || $wordmarkRowBBox === null) {
            throw new RuntimeException('no separator band found — supply a mark-only source');
        }

        // --- mark (full colour) ---------------------------------------
        $markCrop = self::crop($src, $markRowBBox);
        $mark96 = self::scaleToHeight($markCrop, 96);

        // --- mark (inverse / white variant) — recolour a fresh crop so
        // the full-colour $markCrop above is never mutated.
        $markInverseSource = self::crop($src, $markRowBBox);
        self::applyInverseRecolor($markInverseSource);
        $markInverse96 = self::scaleToHeight($markInverseSource, 96);

        // --- lockup (mark + wordmark), tight bbox + 4% padding ---------
        $lockupBBox = self::padBBox($overallBBox, imagesx($src), imagesy($src), 0.04);
        $lockupCrop = self::crop($src, $lockupBBox);
        $lockup320 = self::scaleToWidth($lockupCrop, 320);
        $lockup640 = self::scaleToWidth($lockupCrop, 640);

        // --- write the eight brand/ files -------------------------------
        self::writePng($mark96, $brandOutDir.'/mark-96.png');
        self::writeWebp($mark96, $brandOutDir.'/mark-96.webp');
        self::writePng($markInverse96, $brandOutDir.'/mark-inverse-96.png');
        self::writeWebp($markInverse96, $brandOutDir.'/mark-inverse-96.webp');
        self::writePng($lockup320, $brandOutDir.'/lockup-320.png');
        self::writeWebp($lockup320, $brandOutDir.'/lockup-320.webp');
        self::writePng($lockup640, $brandOutDir.'/lockup-640.png');
        self::writeWebp($lockup640, $brandOutDir.'/lockup-640.webp');

        // --- favicon.ico (PNG-in-ICO, 16/32/48) -------------------------
        $pngBlobs = [];

        foreach ([16, 32, 48] as $size) {
            $icon = self::buildSquareIconCanvas($markCrop, $size);
            $pngBlobs[$size] = self::pngBytes($icon);
            imagedestroy($icon);
        }

        self::writeFaviconIco($pngBlobs, $publicRootDir);

        // --- apple-touch-icon.png (180x180, flattened onto white) ------
        $appleTouch = self::buildAppleTouchIcon($markCrop, 180);
        self::writePng($appleTouch, $publicRootDir.'/apple-touch-icon.png');

        foreach ([$src, $markCrop, $mark96, $markInverseSource, $markInverse96, $lockupCrop, $lockup320, $lockup640, $appleTouch] as $im) {
            imagedestroy($im);
        }

        $manifest = [
            'apple-touch-icon.png',
            'favicon.ico',
            'lockup-320.png',
            'lockup-320.webp',
            'lockup-640.png',
            'lockup-640.webp',
            'mark-96.png',
            'mark-96.webp',
            'mark-inverse-96.png',
            'mark-inverse-96.webp',
        ];
        sort($manifest);

        return $manifest;
    }

    // ---------------------------------------------------------------------
    // Loading / normalization
    // ---------------------------------------------------------------------

    /**
     * @return \GdImage
     */
    private static function loadNormalized(string $path)
    {
        $im = @imagecreatefrompng($path);

        if ($im === false) {
            throw new RuntimeException("BrandAssetBuilder: unable to decode PNG at {$path}");
        }

        // Palette PNGs return palette indices from imagecolorat(), not RGBA
        // — normalize to truecolor first so every later pixel read is RGBA.
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }

        imagealphablending($im, false);
        imagesavealpha($im, true);

        return $im;
    }

    private static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("BrandAssetBuilder: unable to create output directory: {$dir}");
        }
    }

    // ---------------------------------------------------------------------
    // Step 1: white-key
    // ---------------------------------------------------------------------

    private static function applyWhiteKey($im): void
    {
        $w = imagesx($im);
        $h = imagesy($im);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                if ($r > self::WHITE_KEY_THRESHOLD && $g > self::WHITE_KEY_THRESHOLD && $b > self::WHITE_KEY_THRESHOLD) {
                    $transparent = imagecolorallocatealpha($im, $r, $g, $b, self::GD_ALPHA_TRANSPARENT);
                    imagesetpixel($im, $x, $y, $transparent);
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Bounding boxes / row analysis
    // ---------------------------------------------------------------------

    /**
     * @return array{minX:int,minY:int,maxX:int,maxY:int}|null
     */
    private static function opaqueBoundingBox($im): ?array
    {
        return self::opaqueBoundingBoxInRows($im, 0, imagesy($im) - 1);
    }

    /**
     * @return array{minX:int,minY:int,maxX:int,maxY:int}|null
     */
    private static function opaqueBoundingBoxInRows($im, int $y0, int $y1): ?array
    {
        if ($y0 > $y1) {
            return null;
        }

        $w = imagesx($im);
        $minX = null;
        $minY = null;
        $maxX = null;
        $maxY = null;

        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha < self::GD_ALPHA_TRANSPARENT) {
                    if ($minX === null || $x < $minX) {
                        $minX = $x;
                    }
                    if ($maxX === null || $x > $maxX) {
                        $maxX = $x;
                    }
                    if ($minY === null || $y < $minY) {
                        $minY = $y;
                    }
                    if ($maxY === null || $y > $maxY) {
                        $maxY = $y;
                    }
                }
            }
        }

        if ($minX === null || $minY === null || $maxX === null || $maxY === null) {
            return null;
        }

        return ['minX' => $minX, 'minY' => $minY, 'maxX' => $maxX, 'maxY' => $maxY];
    }

    /**
     * @return array<int,int> row index (0-based, absolute) => opaque pixel count
     */
    private static function rowOpaqueCounts($im, int $y0, int $y1): array
    {
        $w = imagesx($im);
        $counts = [];

        for ($y = $y0; $y <= $y1; $y++) {
            $count = 0;

            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha < self::GD_ALPHA_TRANSPARENT) {
                    $count++;
                }
            }

            $counts[$y] = $count;
        }

        return $counts;
    }

    // ---------------------------------------------------------------------
    // Step 2: mark/wordmark band split
    // ---------------------------------------------------------------------

    /**
     * @param  array{minX:int,minY:int,maxX:int,maxY:int}  $bbox
     * @return array{0:int,1:int} [bandStart, bandEnd] absolute row indices, inclusive
     */
    private static function findSeparatorBand($im, array $bbox): array
    {
        $height = imagesy($im);
        $bottomStart = (int) floor($height * 0.6);

        // Restrict the search to inside the artwork's own opaque content —
        // see the class docblock's "Mark/wordmark split" note for why: the
        // real source has a wide blank margin below the wordmark before the
        // canvas edge, which would otherwise be found as the "widest zero
        // band" instead of the true separator.
        $searchTop = max($bottomStart, $bbox['minY']);
        $searchBottom = $bbox['maxY'];

        $bandHeight = 0;
        $bandStart = -1;

        if ($searchTop <= $searchBottom) {
            $counts = self::rowOpaqueCounts($im, $searchTop, $searchBottom);

            $curStart = -1;
            $curLen = 0;
            $bestStart = -1;
            $bestLen = 0;

            for ($y = $searchTop; $y <= $searchBottom; $y++) {
                if ($counts[$y] === 0) {
                    if ($curStart === -1) {
                        $curStart = $y;
                        $curLen = 0;
                    }
                    $curLen++;
                } else {
                    if ($curLen > $bestLen) {
                        $bestLen = $curLen;
                        $bestStart = $curStart;
                    }
                    $curStart = -1;
                    $curLen = 0;
                }
            }

            if ($curLen > $bestLen) {
                $bestLen = $curLen;
                $bestStart = $curStart;
            }

            $bandHeight = $bestLen;
            $bandStart = $bestStart;
        }

        if ($bandHeight < self::MIN_BAND_HEIGHT) {
            throw new RuntimeException('no separator band found — supply a mark-only source');
        }

        return [$bandStart, $bandStart + $bandHeight - 1];
    }

    // ---------------------------------------------------------------------
    // Cropping / padding
    // ---------------------------------------------------------------------

    /**
     * @param  array{minX:int,minY:int,maxX:int,maxY:int}  $bbox
     * @return \GdImage
     */
    private static function crop($im, array $bbox)
    {
        $w = $bbox['maxX'] - $bbox['minX'] + 1;
        $h = $bbox['maxY'] - $bbox['minY'] + 1;

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, self::GD_ALPHA_TRANSPARENT);
        imagefill($out, 0, 0, $transparent);

        imagealphablending($im, false);
        imagecopy($out, $im, 0, 0, $bbox['minX'], $bbox['minY'], $w, $h);

        return $out;
    }

    /**
     * @param  array{minX:int,minY:int,maxX:int,maxY:int}  $bbox
     * @return array{minX:int,minY:int,maxX:int,maxY:int}
     */
    private static function padBBox(array $bbox, int $imgW, int $imgH, float $pct): array
    {
        $w = $bbox['maxX'] - $bbox['minX'] + 1;
        $h = $bbox['maxY'] - $bbox['minY'] + 1;
        $padX = (int) round($w * $pct);
        $padY = (int) round($h * $pct);

        return [
            'minX' => max(0, $bbox['minX'] - $padX),
            'minY' => max(0, $bbox['minY'] - $padY),
            'maxX' => min($imgW - 1, $bbox['maxX'] + $padX),
            'maxY' => min($imgH - 1, $bbox['maxY'] + $padY),
        ];
    }

    // ---------------------------------------------------------------------
    // Step 3: inverse mark recolour
    // ---------------------------------------------------------------------

    private static function applyInverseRecolor($im): void
    {
        [$wr, $wg, $wb] = self::hexToRgb(self::WHITE_HEX);

        $w = imagesx($im);
        $h = imagesy($im);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha >= self::GD_ALPHA_TRANSPARENT) {
                    continue; // transparent pixel, nothing to recolour
                }

                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                [$hue, $saturation] = self::rgbToHueSaturation($r, $g, $b);

                if ($hue >= self::RECOLOR_HUE_MIN && $hue <= self::RECOLOR_HUE_MAX && $saturation > self::RECOLOR_SATURATION_MIN) {
                    $white = imagecolorallocatealpha($im, $wr, $wg, $wb, $alpha);
                    imagesetpixel($im, $x, $y, $white);
                }
            }
        }
    }

    /**
     * @return array{0:float,1:float} [hue in degrees 0..360, saturation 0..1]
     */
    private static function rgbToHueSaturation(int $r, int $g, int $b): array
    {
        $rf = $r / 255;
        $gf = $g / 255;
        $bf = $b / 255;

        $max = max($rf, $gf, $bf);
        $min = min($rf, $gf, $bf);
        $delta = $max - $min;

        $saturation = $max === 0.0 ? 0.0 : $delta / $max;

        if ($delta === 0.0) {
            $hue = 0.0;
        } elseif ($max === $rf) {
            $hue = 60 * fmod((($gf - $bf) / $delta), 6);
        } elseif ($max === $gf) {
            $hue = 60 * ((($bf - $rf) / $delta) + 2);
        } else {
            $hue = 60 * ((($rf - $gf) / $delta) + 4);
        }

        if ($hue < 0) {
            $hue += 360;
        }

        return [$hue, $saturation];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    // ---------------------------------------------------------------------
    // Step 4: scaling / export
    // ---------------------------------------------------------------------

    /**
     * @return \GdImage
     */
    private static function scaleToHeight($im, int $targetHeight)
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $targetWidth = max(1, (int) round($w * $targetHeight / $h));

        return self::scale($im, $targetWidth, $targetHeight);
    }

    /**
     * @return \GdImage
     */
    private static function scaleToWidth($im, int $targetWidth)
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $targetHeight = max(1, (int) round($h * $targetWidth / $w));

        return self::scale($im, $targetWidth, $targetHeight);
    }

    /**
     * @return \GdImage
     */
    private static function scale($im, int $w, int $h)
    {
        imagealphablending($im, false);
        imagesavealpha($im, true);

        $out = imagescale($im, $w, $h, IMG_BICUBIC);

        if ($out === false) {
            throw new RuntimeException('BrandAssetBuilder: imagescale() failed.');
        }

        imagealphablending($out, false);
        imagesavealpha($out, true);

        return $out;
    }

    private static function writePng($im, string $path): void
    {
        if (! imagepng($im, $path)) {
            throw new RuntimeException("BrandAssetBuilder: failed to write PNG to {$path}");
        }
    }

    private static function pngBytes($im): string
    {
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private static function writeWebp($im, string $path): void
    {
        if (! imagewebp($im, $path, 90)) {
            throw new RuntimeException("BrandAssetBuilder: failed to write WebP to {$path}");
        }
    }

    // ---------------------------------------------------------------------
    // favicon.ico / apple-touch-icon.png
    // ---------------------------------------------------------------------

    /**
     * A square, transparent-background canvas of $size x $size with the
     * mark centred and fit within ~86% of the canvas (a small margin keeps
     * the mark from touching the edge at tiny favicon sizes).
     *
     * @return \GdImage
     */
    private static function buildSquareIconCanvas($markCrop, int $size)
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, self::GD_ALPHA_TRANSPARENT);
        imagefill($canvas, 0, 0, $transparent);

        $mw = imagesx($markCrop);
        $mh = imagesy($markCrop);
        $fit = $size * 0.86;
        $scaleFactor = min($fit / $mw, $fit / $mh);
        $tw = max(1, (int) round($mw * $scaleFactor));
        $th = max(1, (int) round($mh * $scaleFactor));

        $scaled = self::scale($markCrop, $tw, $th);

        $dstX = (int) round(($size - $tw) / 2);
        $dstY = (int) round(($size - $th) / 2);

        imagealphablending($canvas, false);
        imagecopy($canvas, $scaled, $dstX, $dstY, 0, 0, $tw, $th);
        imagedestroy($scaled);

        return $canvas;
    }

    /**
     * 180x180, flattened onto an opaque white backdrop (Apple ignores
     * alpha in touch-icon PNGs, so a naive transparent export shows through
     * to whatever the OS puts behind it — flatten explicitly instead), mark
     * centred and fit within 80% of the canvas.
     *
     * @return \GdImage
     */
    private static function buildAppleTouchIcon($markCrop, int $size)
    {
        [$wr, $wg, $wb] = self::hexToRgb(self::WHITE_HEX);

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);
        $white = imagecolorallocate($canvas, $wr, $wg, $wb);
        imagefill($canvas, 0, 0, $white);

        $mw = imagesx($markCrop);
        $mh = imagesy($markCrop);
        $fit = $size * 0.8;
        $scaleFactor = min($fit / $mw, $fit / $mh);
        $tw = max(1, (int) round($mw * $scaleFactor));
        $th = max(1, (int) round($mh * $scaleFactor));

        $scaled = self::scale($markCrop, $tw, $th);

        $dstX = (int) round(($size - $tw) / 2);
        $dstY = (int) round(($size - $th) / 2);

        // Blending mode true so the mark's anti-aliased/transparent edges
        // composite cleanly onto the white backdrop instead of punching a
        // raw transparent hole into an opaque-alpha canvas.
        imagealphablending($canvas, true);
        imagecopy($canvas, $scaled, $dstX, $dstY, 0, 0, $tw, $th);
        imagedestroy($scaled);

        return $canvas;
    }

    /**
     * @param  array<int,string>  $pngBlobs  size (16|32|48) => raw PNG bytes
     */
    private static function writeFaviconIco(array $pngBlobs, string $publicRootDir): void
    {
        // ICONDIR: reserved=0, type=1 (icon), count=3.
        $dir = pack('vvv', 0, 1, 3);
        $off = 6 + 16 * 3; // header + 3 x 16-byte ICONDIRENTRY

        foreach ([16, 32, 48] as $size) {
            $blob = $pngBlobs[$size];
            // ICONDIRENTRY: width, height, colorcount=0, reserved=0,
            // planes=1, bitcount=32, bytesInRes, imageOffset.
            $dir .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($blob), $off);
            $off += strlen($blob);
        }

        file_put_contents($publicRootDir.'/favicon.ico', $dir.implode('', $pngBlobs));
    }
}
