<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * The persistent beta banner (`layouts/app.blade.php`, Lane C4 of
 * `docs/superpowers/plans/2026-08-18-public-beta-release.md`) — the plan's
 * own words: "given the payment decision, this is the single cheapest
 * risk-reducer on the whole list."
 */
final class BetaBannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_beta_banner_is_present_on_a_public_page(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Makam.co.id versi Beta', $html);
        $this->assertStringContainsString('beta publik', $html);
        $this->assertStringContainsString('mode simulasi (sandbox)', $html);
    }

    public function test_the_beta_banner_is_not_dismissible(): void
    {
        // `<x-mk.alert>`'s own doc block: a dismiss button is only wired up
        // when `dismissible` is true, and its close control always carries
        // this exact Indonesian aria-label. Its absence proves this banner
        // stayed non-dismissible.
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Tutup"', $html);
    }

    /**
     * Regression guard for the a11y fix this banner's own doc block
     * explains: `<x-mk.header>`'s "Lewati ke konten utama" skip link must
     * stay the FIRST focusable element in the DOM. The banner sits before
     * the header in markup order, so nothing inside it may be a real link
     * or button — a future edit adding one back (e.g. a "Bantuan" link)
     * would silently defeat the skip link for keyboard users.
     */
    public function test_nothing_before_the_skip_link_is_focusable(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $bodyPos = strpos($html, '<body');
        $skipLinkPos = strpos($html, 'Lewati ke konten utama');
        $this->assertNotFalse($bodyPos);
        $this->assertNotFalse($skipLinkPos);

        $beforeSkipLink = substr($html, $bodyPos, $skipLinkPos - $bodyPos);

        $this->assertDoesNotMatchRegularExpression('/<a\s|<button\s/i', $beforeSkipLink);
    }
}
