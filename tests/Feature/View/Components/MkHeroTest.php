<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class MkHeroTest extends TestCase
{
    private const RENDER = '<x-mk.hero image="/images/cemetery-garden-01.jpg" heading="Tenang, hormat, terpercaya." :cta="[\'label\' => \'Pemesanan Makam\', \'href\' => \'/pemesanan-makam\']" />';

    public function test_it_renders_the_heading_image_and_cta(): void
    {
        $html = Blade::render(self::RENDER);

        $this->assertStringContainsString('Tenang, hormat, terpercaya.', $html);
        $this->assertStringContainsString('/images/cemetery-garden-01.jpg', $html);
        $this->assertStringContainsString('Pemesanan Makam', $html);
        $this->assertStringContainsString('/pemesanan-makam', $html);

        // The heading must render inside a real <h1>, not just be present
        // as text somewhere in the markup.
        $this->assertMatchesRegularExpression(
            '#<h1[^>]*>\s*Tenang, hormat, terpercaya\.\s*</h1>#',
            $html
        );

        // The CTA must render as a real <a href="..."> link, not just text
        // containing the label.
        $this->assertMatchesRegularExpression(
            '#<a[^>]*href="/pemesanan-makam"[^>]*>.*Pemesanan Makam.*</a>#s',
            $html
        );
    }

    public function test_the_image_has_an_empty_alt_by_default_since_it_is_decorative(): void
    {
        $html = Blade::render(self::RENDER);

        $this->assertStringContainsString('alt=""', $html);
    }

    public function test_it_throws_without_a_heading(): void
    {
        try {
            Blade::render('<x-mk.hero image="/images/cemetery-garden-01.jpg" />');
            $this->fail('Expected an exception when <x-mk.hero> is rendered without a heading.');
        } catch (\Throwable $e) {
            $cause = $e;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }

            $this->assertInstanceOf(\InvalidArgumentException::class, $cause);
            $this->assertStringContainsString('<x-mk.hero> requires a heading.', $cause->getMessage());
        }
    }

    /**
     * ADDED 24 Sep 2026 (ADR-0045) — `image` is now required, symmetrically
     * with `heading`: the redesigned component is a photo-with-scrim-
     * overlay, and there is no longer a two-block fallback for a missing
     * image (the pre-redesign version rendered the text panel alone).
     */
    public function test_it_throws_without_an_image(): void
    {
        try {
            Blade::render('<x-mk.hero heading="Tenang, hormat, terpercaya." />');
            $this->fail('Expected an exception when <x-mk.hero> is rendered without an image.');
        } catch (\Throwable $e) {
            $cause = $e;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }

            $this->assertInstanceOf(\InvalidArgumentException::class, $cause);
            $this->assertStringContainsString('<x-mk.hero> requires an image.', $cause->getMessage());
        }
    }

    /**
     * ADDED 24 Sep 2026 (ADR-0045, resolves OQ-K5) — SUPERSEDES
     * `test_the_photo_renders_after_the_text_panel_on_mobile_only`, which
     * asserted the old two-block layout's mobile CTA-reorder mechanism
     * (`order-last`/`md:order-none`). That mechanism existed only because
     * the old layout put a photo band ABOVE the CTA-bearing text panel;
     * with the CTA now rendered on top of the photo from first paint on
     * every breakpoint, there is no "CTA buried below the fold" problem
     * left, and the reorder classes are removed as dead complexity, not
     * merely left inert. This test asserts the new reality: the CTA is
     * findable in the rendered HTML before the closing tag of the
     * `<picture>` sibling that follows it in the new DOM order, i.e. text
     * comes first in markup and is positioned over the photo by CSS, not
     * pushed below it.
     */
    public function test_the_heading_and_cta_render_over_the_photo_via_the_scrim(): void
    {
        $html = Blade::render(self::RENDER);

        // The scrim layer renders, referencing the real token by var(),
        // never a hardcoded gradient value duplicated in the component.
        $this->assertStringContainsString('bg-[image:var(--mk-hero-scrim)]', $html);

        // Heading colour is white (text-neutral-0), correct for text over
        // a dark scrim -- the old text-neutral-900 (dark-on-light, correct
        // for the old bg-primary-50 panel) would be unreadable here.
        $this->assertMatchesRegularExpression(
            '#<h1[^>]*\btext-neutral-0\b[^>]*>#',
            $html
        );
        $this->assertStringNotContainsString('text-neutral-900', $html);

        // The old two-block panel surface is genuinely gone, not left
        // alongside the new overlay.
        $this->assertStringNotContainsString('bg-primary-50', $html);

        // The obsoleted mobile-reorder classes are genuinely gone.
        $this->assertStringNotContainsString('order-last', $html);
        $this->assertStringNotContainsString('order-none', $html);

        // Text layer sits after the photo in DOM order, positioned over
        // it via `absolute` + `inset-x-0 bottom-0`, not pushed below it in
        // normal flow.
        $picturePosition = strpos($html, '<picture');
        $headingPosition = strpos($html, '<h1');
        $this->assertIsInt($picturePosition);
        $this->assertIsInt($headingPosition);
        $this->assertLessThan($headingPosition, $picturePosition);
    }
}
