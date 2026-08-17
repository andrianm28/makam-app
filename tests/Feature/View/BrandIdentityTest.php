<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * End-to-end check that the public site shell (`layouts/app.blade.php` via
 * the homepage route) actually carries the real Makam.co.id brand identity
 * rather than the retired placeholder monogram — the header renders the
 * normal-variant mark, the footer renders the inverse-variant mark, and the
 * favicon/apple-touch-icon links from Task 4's <head> wiring are present.
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

        $this->assertStringContainsString('brand/mark-96.png', $html);          // header
        $this->assertStringContainsString('brand/mark-inverse-96.png', $html);  // footer
        $this->assertStringContainsString('rel="icon"', $html);
        $this->assertStringContainsString('favicon.ico', $html);
        $this->assertStringContainsString('apple-touch-icon.png', $html);
        $this->assertStringNotContainsString('M9 22V10.5', $html);              // old placeholder SVG gone
    }
}
