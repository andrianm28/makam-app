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
        $this->tempDirs[] = dirname($path); // harmless if not otherwise cleaned

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
     *   - an explicit, unambiguous >=8px fully-white horizontal band
     *     (rows 185-210, 26px) — the mark/wordmark separator;
     *   - a dark rectangle ("wordmark" text stand-in, rows 220-270) below
     *     the band, with white margin below it down to the canvas edge
     *     (this deliberately mirrors the real source's trailing white
     *     margin below the wordmark, so this fixture exercises the same
     *     content-bbox-restricted band search the real image needs).
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

        // Green "leaves" cluster, mid — stays clear of y=180+ so it never
        // reaches the explicit white band drawn below.
        $green = imagecolorallocatealpha($im, 34, 139, 34, 0);
        imagefilledellipse($im, 100, 140, 30, 30, $green);
        imagefilledellipse($im, 75, 150, 30, 30, $green);
        imagefilledellipse($im, 125, 150, 30, 30, $green);
        imagefilledellipse($im, 88, 165, 30, 30, $green);
        imagefilledellipse($im, 112, 165, 30, 30, $green);

        // Explicit, unambiguous white separator band — overwrites anything
        // that might have bled into rows 185-210.
        imagefilledrectangle($im, 0, 185, $width - 1, 210, $white);

        // Dark "wordmark" text stand-in block, below the band.
        $dark = imagecolorallocatealpha($im, 30, 30, 30, 0);
        imagefilledrectangle($im, 30, 220, 170, 270, $dark);

        // Rows 271-299 remain white background (trailing margin, mirrors
        // the real source).
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
