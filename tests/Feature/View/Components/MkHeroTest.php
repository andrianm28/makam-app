<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class MkHeroTest extends TestCase
{
    public function test_it_renders_the_heading_image_and_cta(): void
    {
        $html = Blade::render(
            '<x-mk.hero image="/images/cemetery-garden-01.jpg" heading="Tenang, hormat, terpercaya." :cta="[\'label\' => \'Pemesanan Makam\', \'href\' => \'/pemesanan-makam\']" />'
        );

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
        // design-system.md §2.2: imagery is atmosphere (real cemeteries/
        // gardens, daylight), never content the heading doesn't already
        // convey -- matches this repo's existing convention for
        // decorative images (empty alt, not a missing one).
        $html = Blade::render(
            '<x-mk.hero image="/images/cemetery-garden-01.jpg" heading="Tenang, hormat, terpercaya." :cta="[\'label\' => \'Pemesanan Makam\', \'href\' => \'/pemesanan-makam\']" />'
        );

        $this->assertStringContainsString('alt=""', $html);
    }

    /**
     * Regression test for Task C1 (kamboja design-language plan §2.7/§7,
     * option M2), 13 Sep 2026.
     *
     * The finding: measured on a 360x740 viewport (deviceScaleFactor 2,
     * mobile true) the homepage's `Pesan Makam` CTA sat at y = 1048 px,
     * because this component rendered its 256 px photo band ABOVE the text
     * panel that carries the CTA. M2 reverses that on mobile only, via CSS
     * `order` — so the DOM order must stay photo-then-text (the photo is
     * decorative, alt="", and this keeps reading/tab order untouched) while
     * the rendered order below `md` is text-then-photo.
     *
     * This asserts all three halves of that contract, because any one of
     * them alone can silently regress: the flex context that makes `order`
     * apply at all, the `order-last` that does the lifting, and the `md:`
     * resets that keep desktop byte-identical to what it was.
     */
    public function test_the_photo_renders_after_the_text_panel_on_mobile_only(): void
    {
        $html = Blade::render(
            '<x-mk.hero image="/images/cemetery-garden-01.jpg" heading="Tenang, hormat, terpercaya." :cta="[\'label\' => \'Pemesanan Makam\', \'href\' => \'/pemesanan-makam\']" />'
        );

        // 1. `order` only applies inside a flex (or grid) container, and the
        //    `md:block` reset is what restores the desktop formatting
        //    context exactly rather than approximately.
        $this->assertStringContainsString(
            'relative flex flex-col overflow-hidden rounded-lg md:block',
            $html
        );

        // 2. The photo is the element that moves, and only below `md`.
        $this->assertMatchesRegularExpression(
            '#<picture class="[^"]*\border-last\b[^"]*\bmd:order-none\b[^"]*">#',
            $html
        );

        // 3. DOM order is deliberately NOT changed — the reorder is purely
        //    visual, so the decorative photo never moves ahead of the
        //    heading and CTA in reading or tab order.
        $picturePosition = strpos($html, '<picture');
        $headingPosition = strpos($html, '<h1');
        $this->assertIsInt($picturePosition);
        $this->assertIsInt($headingPosition);
        $this->assertLessThan(
            $headingPosition,
            $picturePosition,
            'The reorder must be CSS-only: <picture> must still precede <h1> in the DOM.'
        );
    }

    public function test_it_throws_without_a_heading(): void
    {
        // Blade wraps every exception thrown while compiling/rendering a
        // view in Illuminate\View\ViewException (to attach the view file
        // path), and here it does so twice -- once for the anonymous
        // component's own compiled view, once for the outer render -- so
        // the exception actually observed is a ViewException wrapping a
        // ViewException wrapping the real InvalidArgumentException.
        // expectException(InvalidArgumentException::class) fails for that
        // reason alone. Walk the getPrevious() chain down to the real cause
        // and assert on both its class and message, so this test can only
        // pass for the specific "missing heading" failure, not any
        // unrelated Throwable (which is what the too-broad
        // expectException(Throwable::class) it replaces would have let through).
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
}
