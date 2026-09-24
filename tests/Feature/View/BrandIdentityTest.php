<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * End-to-end check that the public site shell (`layouts/app.blade.php` via
 * the homepage route) actually carries the real Makam.co.id brand identity
 * rather than the retired placeholder monogram, and that the
 * favicon/apple-touch-icon links from Task 4's <head> wiring are present.
 *
 * UPDATED 24 Sep 2026 (pixel-fidelity 1:1 visual clone of FFI) — the footer
 * no longer renders the inverse-variant mark. It was `bg-primary-900`
 * (dark) until this batch reversed that to a light surface matching FFI's
 * real footer, so it now renders the SAME normal-variant mark the header
 * does — `brand/mark-inverse-96.png` no longer appears anywhere on the
 * public shell. Scoped per-region (not a bare `assertStringContainsString`)
 * because both regions now share one filename — a plain "contains" check
 * would stay vacuously true even if only one of the two actually rendered
 * it.
 */
final class BrandIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_shell_carries_the_real_brand(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertNotFalse($html);

        $headerStart = strpos($html, '<header');
        $this->assertNotFalse($headerStart, 'Expected a <header> element.');
        $headerEnd = strpos($html, '</header>', $headerStart);
        $this->assertNotFalse($headerEnd);
        $header = substr($html, $headerStart, $headerEnd - $headerStart);

        $footerStart = strpos($html, '<footer');
        $this->assertNotFalse($footerStart, 'Expected a <footer> element.');
        $footerEnd = strpos($html, '</footer>', $footerStart);
        $this->assertNotFalse($footerEnd);
        $footer = substr($html, $footerStart, $footerEnd - $footerStart);

        $this->assertStringContainsString('brand/mark-96.png', $header);
        $this->assertStringContainsString('brand/mark-96.png', $footer);
        $this->assertStringNotContainsString('brand/mark-inverse-96.png', $html);

        $this->assertStringContainsString('rel="icon"', $html);
        $this->assertStringContainsString('favicon.ico', $html);
        $this->assertStringContainsString('apple-touch-icon.png', $html);
        $this->assertStringNotContainsString('M9 22V10.5', $html);              // old placeholder SVG gone
    }
}
