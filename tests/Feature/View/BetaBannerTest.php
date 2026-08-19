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

    /**
     * CI's own axe-core smoke test (tests/browser/smoke.spec.ts) caught a
     * real violation here: the `region` rule ("Ensure all page content is
     * contained by landmarks") flagged the banner's outer wrapper, which
     * sits directly in `<body>` outside any landmark. Regression guard for
     * that fix.
     */
    public function test_the_beta_banner_wrapper_is_a_labelled_landmark(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('role="region" aria-label="Pemberitahuan beta"', $html);
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
        $this->assertNotFalse($bodyPos);

        // Anchored on the skip link's own OPENING TAG, not its text content
        // ("Lewati ke konten utama"): the text sits after the tag's `<a`
        // start, so anchoring on the text would include the skip link's own
        // `<a` tag inside "before the skip link" and falsely flag it as
        // something preceding itself. Found via its `href="#main"`
        // attribute, then walking back to that attribute's own `<a` —
        // robust to exact whitespace between `<a` and `href` in the
        // rendered HTML, unlike matching a literal multi-line string would
        // be.
        $hrefPos = strpos($html, 'href="#main"');
        $this->assertNotFalse($hrefPos);
        $skipLinkTagStart = strrpos(substr($html, 0, $hrefPos), '<a');
        $this->assertNotFalse($skipLinkTagStart);

        $beforeSkipLink = substr($html, $bodyPos, $skipLinkTagStart - $bodyPos);

        $this->assertDoesNotMatchRegularExpression('/<a\s|<button\s/i', $beforeSkipLink);
    }
}
