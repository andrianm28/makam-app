<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-mk.skeleton> — the loading-placeholder primitive, design doc
 * §3 (FFI full-visual-clone), Stage 2 ticket 01.
 *
 * Class-string assertions are deliberate, not incidental: card.blade.php
 * and badge.blade.php both once built a Tailwind class by interpolating
 * a PHP variable, invisible to the @source scanner (it reads file text,
 * not executed PHP), and shipped completely unstyled while every
 * functional test still passed. Asserting the actual rendered class
 * string is what catches that here.
 */
final class MkSkeletonTest extends TestCase
{
    public function test_default_shape_is_text_with_three_lines(): void
    {
        $html = Blade::render('<x-mk.skeleton />');

        $this->assertStringContainsString('aria-busy="true"', $html);
        // three placeholder line elements
        $this->assertSame(3, substr_count($html, 'mk-skeleton-line'));
    }

    public function test_lines_prop_controls_line_count(): void
    {
        $one = Blade::render('<x-mk.skeleton :lines="1" />');
        $ten = Blade::render('<x-mk.skeleton :lines="10" />');

        $this->assertSame(1, substr_count($one, 'mk-skeleton-line'));
        $this->assertSame(10, substr_count($ten, 'mk-skeleton-line'));
    }

    public function test_card_shape_renders_a_single_card_placeholder_block(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="card" />');

        $this->assertStringContainsString('mk-skeleton-card', $html);
        $this->assertStringNotContainsString('mk-skeleton-line', $html);
    }

    public function test_media_shape_renders_a_media_placeholder_block(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="media" />');

        $this->assertStringContainsString('mk-skeleton-media', $html);
    }

    public function test_section_shape_requires_section_rhythm_true(): void
    {
        $withRhythm = Blade::render('<x-mk.skeleton shape="section" :section-rhythm="true" />');
        $this->assertStringContainsString('mk-skeleton-section', $withRhythm);
        $this->assertStringContainsString('py-section', $withRhythm);

        // Omitting section-rhythm on shape="section" must not silently
        // render as if it were true -- it's a misuse, and the component's
        // rendered output must make the missing rhythm class visible so
        // a caller notices, not paper over it.
        $withoutRhythm = Blade::render('<x-mk.skeleton shape="section" />');
        $this->assertStringNotContainsString('py-section', $withoutRhythm);
    }

    public function test_count_prop_renders_that_many_independent_instances(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="card" :count="3" />');

        $this->assertSame(3, substr_count($html, 'mk-skeleton-card'));
    }

    public function test_aria_busy_and_sr_only_announce_are_always_present(): void
    {
        $default = Blade::render('<x-mk.skeleton />');
        $this->assertStringContainsString('aria-busy="true"', $default);
        $this->assertStringContainsString('sr-only', $default);
        $this->assertStringContainsString('Memuat', $default);

        $custom = Blade::render('<x-mk.skeleton announce="Memuat daftar makam…" />');
        $this->assertStringContainsString('Memuat daftar makam…', $custom);
    }

    public function test_uses_the_shimmer_utility_and_no_colour_prop(): void
    {
        $html = Blade::render('<x-mk.skeleton />');

        // mk-skeleton-shimmer (app.css) is the two-tone --mk-skeleton-base/
        // -sheen CSS animation utility -- its presence is what proves both
        // tokens are actually in play, not just the base alone, AND is the
        // sole hook tokens.css's existing global @media
        // (prefers-reduced-motion: reduce) rule needs (it collapses ANY
        // animation-duration to 1ms for *, *::before, *::after -- this
        // component does nothing reduced-motion-specific itself, and
        // must not: a JS-driven effect instead of a real CSS animation
        // would silently escape that global rule).
        $this->assertStringContainsString('mk-skeleton-shimmer', $html);
        $this->assertStringNotContainsString('style=', $html); // no inline colour override
    }
}
