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

    public function test_it_throws_without_a_heading(): void
    {
        $this->expectException(\Throwable::class);

        Blade::render('<x-mk.hero image="/images/cemetery-garden-01.jpg" />');
    }
}
