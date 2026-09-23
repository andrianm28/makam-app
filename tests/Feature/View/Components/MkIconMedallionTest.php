<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * <x-mk.icon-medallion>'s `size` scale — design-system.md §3.3a.
 *
 * `xl` was added 14 Sep 2026 for the kamboja plan's Tahap 4 ("perbesar
 * <x-mk.icon-medallion> pada kartu layanan"). Same class-string rationale
 * as MkCardTest: the `$sizes`/`$iconSizes` maps must stay static literal
 * strings or Tailwind's `@source` scanner generates no CSS for them and
 * the tile silently renders unstyled while every other test stays green.
 *
 * This class also owns `tone`'s closed-list contract (`primary` |
 * `secondary` | `brand`, renamed from `earth`/`leaf` 23 Sep 2026) and its
 * throw-on-unknown-value behaviour, not just `size`.
 */
final class MkIconMedallionTest extends TestCase
{
    public function test_xl_renders_a_64px_tile_with_a_proportional_mark(): void
    {
        $html = Blade::render('<x-mk.icon-medallion icon="document-text" tone="primary" size="xl" />');

        // 4rem tile / 1.75rem mark, both on tokens.css's 4px --spacing
        // scale — the ~45% mark-to-tile ratio `md` and `lg` already use.
        $this->assertStringContainsString('size-16', $html);
        $this->assertStringContainsString('size-7', $html);
        $this->assertStringNotContainsString('size-11', $html);
        $this->assertStringNotContainsString('size-13', $html);
    }

    public function test_the_existing_sizes_are_unchanged_by_the_xl_addition(): void
    {
        $md = Blade::render('<x-mk.icon-medallion icon="document-text" tone="primary" />');
        $lg = Blade::render('<x-mk.icon-medallion icon="document-text" tone="primary" size="lg" />');

        $this->assertStringContainsString('size-11', $md);
        $this->assertStringContainsString('size-5', $md);
        $this->assertStringContainsString('size-13', $lg);
        $this->assertStringContainsString('size-6', $lg);
    }

    public function test_xl_is_a_size_only_and_carries_no_tone_of_its_own(): void
    {
        // Tahap 4 is explicitly SIZE only: the `brand` fill Tahap 2 added
        // (ADR-0040 D3) must not travel with the new size, and `primary`
        // must still render its tint at `xl`.
        $primary = Blade::render('<x-mk.icon-medallion icon="document-text" tone="primary" size="xl" />');
        $brand = Blade::render('<x-mk.icon-medallion icon="document-text" tone="brand" size="xl" />');

        $this->assertStringContainsString('bg-primary-100', $primary);
        $this->assertStringContainsString('text-primary-800', $primary);
        $this->assertStringNotContainsString('bg-primary-600', $primary);

        $this->assertStringContainsString('bg-primary-600', $brand);
        $this->assertStringContainsString('text-neutral-0', $brand);
    }

    public function test_the_old_earth_and_leaf_tone_names_now_throw(): void
    {
        // A rename, not a dual-accepting alias. icon-medallion.blade.php
        // already throws InvalidArgumentException for any tone not in its
        // $tones map (the same defensive pattern badge.blade.php uses for
        // $intent -- see the component's own file-header comment). Once
        // the map keys are renamed from earth/leaf to primary/secondary,
        // this throw fires for the old names automatically -- no logic
        // change, just a map-key rename.
        //
        // Blade wraps every exception thrown while compiling/rendering a
        // view in Illuminate\View\ViewException (to attach the view file
        // path), and here it does so twice -- once for the anonymous
        // component's own compiled view, once for the outer render -- so
        // the exception actually observed is a ViewException wrapping a
        // ViewException wrapping the real InvalidArgumentException.
        // expectException(InvalidArgumentException::class) fails for that
        // reason alone (see MkHeroTest::test_it_throws_without_a_heading()
        // and MkLogoTest::test_unknown_variant_throws() for the same
        // pattern). Walk the getPrevious() chain down to the real cause.
        try {
            Blade::render('<x-mk.icon-medallion icon="document-text" tone="earth" />');
            $this->fail('Expected an exception when tone="earth" is rendered.');
        } catch (\Throwable $e) {
            $cause = $e;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }

            $this->assertInstanceOf(InvalidArgumentException::class, $cause);
            $this->assertStringContainsString('Unsupported <x-mk.icon-medallion> tone [earth]', $cause->getMessage());
        }
    }

    public function test_leaf_also_throws_after_the_rename(): void
    {
        try {
            Blade::render('<x-mk.icon-medallion icon="document-text" tone="leaf" />');
            $this->fail('Expected an exception when tone="leaf" is rendered.');
        } catch (\Throwable $e) {
            $cause = $e;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }

            $this->assertInstanceOf(InvalidArgumentException::class, $cause);
            $this->assertStringContainsString('Unsupported <x-mk.icon-medallion> tone [leaf]', $cause->getMessage());
        }
    }

    public function test_secondary_renders_the_secondary_tint(): void
    {
        $html = Blade::render('<x-mk.icon-medallion icon="document-text" tone="secondary" />');

        $this->assertStringContainsString('bg-secondary-100', $html);
        $this->assertStringContainsString('text-secondary-800', $html);
    }

    public function test_the_default_tone_is_primary(): void
    {
        $html = Blade::render('<x-mk.icon-medallion icon="document-text" />');

        $this->assertStringContainsString('bg-primary-100', $html);
        $this->assertStringContainsString('text-primary-800', $html);
    }
}
