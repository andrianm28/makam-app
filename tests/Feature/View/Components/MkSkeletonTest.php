<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-mk.skeleton> — the loading-placeholder primitive, design-system.md
 * §6.1 (component-backed form), Stage 2 ticket 01.
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
        $this->assertSame(3, substr_count($html, 'mk-skeleton-shimmer'));
    }

    public function test_lines_prop_controls_line_count_for_text_shape(): void
    {
        $one = Blade::render('<x-mk.skeleton :lines="1" />');
        $ten = Blade::render('<x-mk.skeleton :lines="10" />');

        $this->assertSame(1, substr_count($one, 'mk-skeleton-shimmer'));
        $this->assertSame(10, substr_count($ten, 'mk-skeleton-shimmer'));
    }

    public function test_lines_is_ignored_for_non_text_shapes(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="card" :lines="10" />');

        $this->assertSame(1, substr_count($html, 'mk-skeleton-shimmer'));
    }

    public function test_count_renders_that_many_instances_for_text_shape(): void
    {
        // count IS meaningful for shape="text" -- the design doc, the
        // spec, and the ticket all scope ONLY `lines` as text-only, never
        // `count`. Three instances of three lines each = nine shimmer
        // blocks.
        $html = Blade::render('<x-mk.skeleton :count="3" />');

        $this->assertSame(9, substr_count($html, 'mk-skeleton-shimmer'));
    }

    public function test_count_prop_renders_that_many_independent_instances_for_other_shapes(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="card" :count="3" />');

        $this->assertSame(3, substr_count($html, 'mk-skeleton-shimmer'));
    }

    public function test_card_shape_renders_a_card_placeholder_block(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="card" />');

        $this->assertStringContainsString('h-40', $html);
        $this->assertStringNotContainsString('aspect-video', $html);
        $this->assertStringNotContainsString('h-64', $html);
    }

    public function test_media_shape_renders_a_media_placeholder_block(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="media" />');

        $this->assertStringContainsString('aspect-video', $html);
        $this->assertStringNotContainsString('h-40', $html);
        $this->assertStringNotContainsString('h-64', $html);
    }

    public function test_section_shape_with_rhythm_true_gets_the_full_padded_wrapper(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="section" :section-rhythm="true" />');

        $this->assertStringContainsString('h-64', $html);
        $this->assertStringContainsString('py-section', $html);
        $this->assertStringContainsString('lg:py-section-lg', $html);
    }

    public function test_section_shape_without_rhythm_does_not_silently_apply_it(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="section" />');

        $this->assertStringContainsString('h-64', $html);
        $this->assertStringNotContainsString('py-section', $html);
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

    public function test_aria_busy_cannot_be_overridden_by_a_caller(): void
    {
        $html = Blade::render('<x-mk.skeleton aria-busy="false" />');

        $this->assertSame(1, substr_count($html, 'aria-busy='));
        $this->assertStringContainsString('aria-busy="true"', $html);
        $this->assertStringNotContainsString('aria-busy="false"', $html);
    }

    public function test_unknown_shape_falls_back_to_text(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="not-a-real-shape" />');

        $this->assertSame(3, substr_count($html, 'mk-skeleton-shimmer'));
    }

    public function test_uses_the_shimmer_utility(): void
    {
        $html = Blade::render('<x-mk.skeleton />');

        $this->assertStringContainsString('mk-skeleton-shimmer', $html);
    }
}
