<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Design;

use App\Support\Design\BrandAssetBuilder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;
use Tests\TestCase;

/**
 * BrandAssetBuilder builds the whole brand raster manifest (mark, inverse
 * mark, two lockup crops, favicon.ico, apple-touch-icon.png) from a single
 * master PNG. These tests exercise it against a synthetic fixture generated
 * with GD at run time — no binary fixture is committed — because the real
 * artwork lives at docs/design/brand/source/logo.png (a local pipeline
 * input, not a test fixture; see this batch's task-3 report).
 *
 * CI-run only: this host's PHP is 8.3.6, composer.lock requires >=8.5, so
 * `php artisan`/PHPUnit cannot execute here (Global Constraints, brand
 * identity plan). Every method below carries #[RequiresPhpExtension('gd')]
 * so hosts without ext-gd skip rather than fail — this repo's CI php job
 * gains `gd` in this same batch (.github/workflows/ci.yml).
 *
 * Path note: this file lives at
 * tests/Unit/Support/Design/BrandAssetBuilderTest.php, mirroring
 * app/Support/Design/BrandAssetBuilder.php's actual directory (every other
 * class under app/Support/Design/ — FilamentPaletteGenerator, StatusIntent
 * — has its test under tests/Unit/Support/Design/, e.g.
 * StatusIntentTest.php). The task-3 brief's file list names the path
 * without the `Support` segment (tests/Unit/Design/BrandAssetBuilderTest.php);
 * that was a brief shorthand, not a directory that exists elsewhere in this
 * codebase. PSR-4 and PHPUnit's directory autodiscovery both work fine with
 * either path — this note exists purely so `git log --grep`/`git blame`
 * against the brief's literal path finds this file's actual history instead
 * of nothing.
 */
#[RequiresPhpExtension('gd')]
final class BrandAssetBuilderTest extends TestCase
{
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeDirectory($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    public function test_builds_the_full_manifest_deterministically(): void
    {
        $src = $this->makeSyntheticLogo();
        $out = sys_get_temp_dir().'/brand-'.uniqid('', true);
        $this->tempDirs[] = $out;
        $brand = $out.'/brand';
        $root = $out.'/public';

        $manifest1 = BrandAssetBuilder::build($src, $brand, $root, whiteKey: true);
        $bytes1 = file_get_contents($brand.'/mark-96.png');

        $manifest2 = BrandAssetBuilder::build($src, $brand, $root, whiteKey: true);

        $this->assertSame($manifest1, $manifest2);
        $this->assertSame($bytes1, file_get_contents($brand.'/mark-96.png'));

        foreach (['mark-96.png', 'mark-inverse-96.png', 'lockup-320.png', 'lockup-640.png'] as $file) {
            $this->assertFileExists($brand.'/'.$file);
        }

        [$width, $height] = getimagesize($brand.'/mark-96.png');
        $this->assertSame(96, $height);

        $ico = file_get_contents($root.'/favicon.ico');
        $this->assertSame("\x00\x00\x01\x00", substr($ico, 0, 4));
        $this->assertSame(3, unpack('v', substr($ico, 4, 2))[1]);

        $this->assertFileExists($root.'/apple-touch-icon.png');
        [$appleWidth] = getimagesize($root.'/apple-touch-icon.png');
        $this->assertSame(180, $appleWidth);
    }

    public function test_returns_the_exact_sorted_ten_file_manifest(): void
    {
        $src = $this->makeSyntheticLogo();
        $out = sys_get_temp_dir().'/brand-'.uniqid('', true);
        $this->tempDirs[] = $out;

        $manifest = BrandAssetBuilder::build($src, $out.'/brand', $out.'/public', whiteKey: true);

        $this->assertSame([
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
        ], $manifest);
    }

    public function test_white_keyed_corner_pixel_of_mark_is_transparent(): void
    {
        $src = $this->makeSyntheticLogo();
        $out = sys_get_temp_dir().'/brand-'.uniqid('', true);
        $this->tempDirs[] = $out;

        BrandAssetBuilder::build($src, $out.'/brand', $out.'/public', whiteKey: true);

        $mark = imagecreatefrompng($out.'/brand/mark-96.png');
        imagesavealpha($mark, true);
        $corner = imagecolorat($mark, 0, 0);
        $alpha = ($corner >> 24) & 0x7F;

        // The bounding-box crop's corner sits outside the round mark shape,
        // so it should be (near-)fully transparent. Not asserted at exactly
        // 127: IMG_BICUBIC scaling down to 96px blends the fully-transparent
        // corner with a sliver of nearby opaque pixels, so the true value is
        // "clearly, mostly transparent" rather than the maximum possible.
        $this->assertGreaterThan(64, $alpha, 'corner pixel of the bounding-box crop should be white-keyed (mostly) transparent');

        imagedestroy($mark);
    }

    public function test_build_on_a_missing_source_throws(): void
    {
        $out = sys_get_temp_dir().'/brand-'.uniqid('', true);
        $this->tempDirs[] = $out;

        $this->expectException(RuntimeException::class);

        BrandAssetBuilder::build($out.'/does-not-exist.png', $out.'/brand', $out.'/public', whiteKey: true);
    }

    public function test_no_separator_band_throws_a_runtime_exception(): void
    {
        // A source with no clean white gap between "mark" and "wordmark" —
        // a single solid block filling the lower 40% — must fail closed
        // rather than guess a split point.
        $im = imagecreatetruecolor(200, 300);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $white = imagecolorallocatealpha($im, 255, 255, 255, 0);
        imagefill($im, 0, 0, $white);

        $brown = imagecolorallocatealpha($im, 150, 90, 40, 0);
        imagefilledellipse($im, 100, 70, 90, 90, $brown);

        // Solid block spanning the entire bottom 40% (y=180..299): no zero
        // band of any height can exist inside the search window.
        $dark = imagecolorallocatealpha($im, 30, 30, 30, 0);
        imagefilledrectangle($im, 0, 180, 199, 299, $dark);

        $path = sys_get_temp_dir().'/brand-src-'.uniqid('', true).'.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = sys_get_temp_dir().'/brand-'.uniqid('', true);
        $this->tempDirs[] = $out;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no separator band found');

        try {
            BrandAssetBuilder::build($path, $out.'/brand', $out.'/public', whiteKey: true);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Writes a synthetic 200x300 PNG fixture to a temp file and returns its
     * path:
     *   - an opaque brown ellipse ("mark", top) — brown falls inside the
     *     [10 deg, 50 deg] hue / >0.15 saturation inverse-recolour band;
     *   - a cluster of green circles ("leaves", mid) — green (~hue 120 deg)
     *     must NOT be recolour by the inverse mark;
     *   - an isolated, unambiguous 10px fully-white separator band
     *     (rows 185-194) with opaque content immediately adjacent on BOTH
     *     sides (mark content ends at row 184, wordmark content starts at
     *     row 195 — no gap either side that could merge into the band and
     *     widen it);
     *   - a dark rectangle ("wordmark" text stand-in, rows 195-250)
     *     directly below the band;
     *   - a trailing blank canvas margin below the wordmark, rows 251-299
     *     (49px) — deliberately WIDER than the true 10px separator band.
     *
     * That last point is what makes this fixture an actual regression test
     * for BrandAssetBuilder::findSeparatorBand()'s content-bbox restriction
     * (see that method's docblock), not just a fixture that happens to
     * produce the right answer either way. An UNRESTRICTED "widest
     * zero-opaque band inside the bottom 40% of height" search (the
     * brief's literal wording, searching the full canvas rather than the
     * artwork's own content bounding box) picks the 49px trailing margin
     * over the true 10px separator — and since there is no wordmark
     * content below that margin, the resulting wordmark-region bounding
     * box is empty and BrandAssetBuilder::build() throws
     * RuntimeException('no separator band found...') instead of
     * succeeding. The bbox-restricted search correctly excludes the
     * trailing margin (it is outside the content bounding box) and finds
     * the true band, so build() succeeds normally.
     *
     * Verified manually against both code paths before committing (PHPUnit
     * cannot run on this host — Global Constraints): with the restriction
     * removed, every test using this fixture throws/fails; with it in
     * place, they pass. See the task-3 report's fix-report addendum for
     * the exact before/after command output.
     *
     * An earlier version of this fixture had the white band immediately
     * bordered by more untouched (white) background on both sides, which
     * merged into one wide zero-run that happened to still be the widest
     * one even without the bbox restriction — so it did not actually
     * exercise the restriction at all. This version closes that gap.
     */
    private function makeSyntheticLogo(): string
    {
        $width = 200;
        $height = 300;

        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $white = imagecolorallocatealpha($im, 255, 255, 255, 0);
        imagefill($im, 0, 0, $white);

        // Brown "mark" ellipse, top.
        $brown = imagecolorallocatealpha($im, 150, 90, 40, 0);
        imagefilledellipse($im, 100, 70, 90, 90, $brown);

        // Green "leaves" cluster, mid.
        $green = imagecolorallocatealpha($im, 34, 139, 34, 0);
        imagefilledellipse($im, 100, 140, 30, 30, $green);
        imagefilledellipse($im, 75, 150, 30, 30, $green);
        imagefilledellipse($im, 125, 150, 30, 30, $green);
        imagefilledellipse($im, 88, 165, 30, 30, $green);
        imagefilledellipse($im, 112, 165, 30, 30, $green);

        // Explicit "stem" cap: guarantees the mark's opaque content reaches
        // exactly to row 184, immediately adjacent to the separator band
        // below (row 185) with no gap — the circles alone bottom out
        // around row 180 and would otherwise leave a few blank rows that
        // could merge into the band.
        imagefilledrectangle($im, 90, 175, 110, 184, $green);

        // The TRUE mark/wordmark separator: isolated, 10px (>= the 8px
        // fail-closed floor), bordered by opaque content on both sides.
        imagefilledrectangle($im, 0, 185, $width - 1, 194, $white);

        // Dark "wordmark" text stand-in block, starting immediately after
        // the band (row 195, no gap).
        $dark = imagecolorallocatealpha($im, 30, 30, 30, 0);
        imagefilledrectangle($im, 30, 195, 170, 250, $dark);

        // Rows 251-299 (49px) are left as untouched white background — the
        // trailing canvas margin the discrimination above depends on.
        $path = sys_get_temp_dir().'/brand-src-'.uniqid('', true).'.png';
        imagepng($im, $path);
        imagedestroy($im);

        $this->tempDirs[] = $path; // file, but removeDirectory() below no-ops on files it can't rmdir

        return $path;
    }

    private static function removeDirectory(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            self::removeDirectory($path.'/'.$item);
        }

        @rmdir($path);
    }
}
