<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Memorial;

use App\Domain\Memorial\MemorialQrImage;
use PHPUnit\Framework\TestCase;

/**
 * `App\Domain\Memorial\MemorialQrImage` — the endroid/qr-code SVG wrapper
 * (`.kiro/specs/memorial-and-qr/requirements.md` AC4: the QR encodes only
 * the token URL; `.kiro/specs/memorial-and-qr/design.md` "QR: endroid/qr-code
 * encodes the token URL only").
 *
 * ---------------------------------------------------------------------------
 * What "assert the SVG embeds the payload" means against the REAL writer
 * ---------------------------------------------------------------------------
 * The plan brief anticipated asserting the payload appears in the writer's
 * output (e.g. "a rendered text node or an attribute"). Verified against
 * the installed `endroid/qr-code` 6.1 `SvgWriter`: it does NOT embed the
 * payload as readable text, a title, or an attribute — the payload exists
 * only as the encoded data inside the QR matrix itself, which is exactly
 * the point (a QR image must not expose its payload as scraped text).
 *
 * The assertions therefore prove the payload is carried by the only channel
 * the writer has, honestly:
 *   1. the output is a well-formed SVG document;
 *   2. the SAME payload renders deterministically (byte-identical);
 *   3. DIFFERENT payloads render DIFFERENT output — two distinct token URLs
 *      cannot produce the same matrix, so the matrix provably varies with
 *      the payload it encodes.
 *
 * No PNG assertions anywhere: the writer is used in its SVG form and the CI
 * runner has no gd extension.
 */
final class MemorialQrImageTest extends TestCase
{
    private MemorialQrImage $image;

    protected function setUp(): void
    {
        parent::setUp();

        $this->image = new MemorialQrImage;
    }

    public function test_svg_returns_a_well_formed_svg_document(): void
    {
        $svg = $this->image->svg('https://makam.test/m/'.str_repeat('a', 48));

        $this->assertStringStartsWith('<?xml', $svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('viewBox', $svg);
    }

    public function test_svg_is_deterministic_for_the_same_payload(): void
    {
        $payload = 'https://makam.test/m/'.str_repeat('b', 48);

        $this->assertSame($this->image->svg($payload), $this->image->svg($payload));
    }

    /**
     * The payload affects the matrix: two different token URLs must never
     * render the same QR image (two tokens with identical images would
     * decode to whichever URL the scanner resolves first).
     */
    public function test_distinct_payloads_render_distinct_svgs(): void
    {
        $first = $this->image->svg('https://makam.test/m/'.str_repeat('c', 48));
        $second = $this->image->svg('https://makam.test/m/'.str_repeat('d', 48));

        $this->assertNotSame($first, $second);
    }

    public function test_the_payload_length_carries_into_the_output_size(): void
    {
        // A long payload must produce a denser (larger) matrix than a short
        // one — direct evidence the payload is encoded into the QR data.
        $short = $this->image->svg('https://makam.test/m/short');
        $long = $this->image->svg('https://makam.test/m/'.str_repeat('e', 200));

        $this->assertGreaterThan(strlen($short), strlen($long));
    }
}
