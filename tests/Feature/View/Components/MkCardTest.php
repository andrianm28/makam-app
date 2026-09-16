<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-mk.card>'s `emphasis` axis — design-system.md §3.3, added 14 Sep 2026
 * for the kamboja plan's Tahap 4.
 *
 * These are class-string assertions, which this repo normally avoids, and
 * they are justified here by a defect class this codebase has already been
 * bitten by twice: card.blade.php and badge.blade.php both once built a
 * Tailwind class by interpolating a PHP variable, which the `@source`
 * scanner cannot see (it reads file text and cannot execute PHP), so the
 * component rendered with *no* styling at all and every functional test
 * still passed. The three `$emphasis*` maps must stay static literal
 * strings; asserting the rendered class is the only thing that proves it.
 */
final class MkCardTest extends TestCase
{
    public function test_the_default_emphasis_renders_the_shipped_base_card(): void
    {
        $html = Blade::render('<x-mk.card>Isi</x-mk.card>');

        // design-system.md §3.3 Base: `shadow-sm`, `border-neutral-200`.
        // This is the byte-for-byte guarantee the 27 pre-existing call
        // sites rely on — `emphasis` must not have changed them.
        $this->assertStringContainsString('shadow-sm', $html);
        $this->assertStringContainsString('border-neutral-200', $html);
        $this->assertStringNotContainsString('shadow-none', $html);
        $this->assertStringNotContainsString('shadow-md', $html);
        $this->assertStringNotContainsString('border-primary-200', $html);
    }

    public function test_quiet_rests_with_no_elevation_and_keeps_the_neutral_border(): void
    {
        $html = Blade::render('<x-mk.card emphasis="quiet">Satu baris jawaban</x-mk.card>');

        $this->assertStringContainsString('shadow-none', $html);
        $this->assertStringContainsString('border-neutral-200', $html);
        $this->assertStringNotContainsString('shadow-sm', $html);
    }

    public function test_strong_rests_one_elevation_step_up_with_a_brand_tinted_border(): void
    {
        $html = Blade::render('<x-mk.card emphasis="strong">Pintu masuk journey</x-mk.card>');

        $this->assertStringContainsString('shadow-md', $html);
        $this->assertStringContainsString('border-primary-200', $html);
        $this->assertStringNotContainsString('border-neutral-200', $html);
        // §1.5 reserves `lg`/`xl` for modals and bottom sheets. No card,
        // at any emphasis, may rest or hover there.
        $this->assertStringNotContainsString('shadow-lg', $html);
        $this->assertStringNotContainsString('shadow-xl', $html);
    }

    public function test_an_unrecognised_emphasis_falls_back_to_base(): void
    {
        // Matches how `$padding` and `$intent` already behave in this same
        // component — the file's local convention is a defensive fallback,
        // not icon-medallion.blade.php's throw.
        $html = Blade::render('<x-mk.card emphasis="enormous">Isi</x-mk.card>');

        $this->assertStringContainsString('shadow-sm', $html);
        $this->assertStringContainsString('border-neutral-200', $html);
    }

    public function test_an_intent_card_keeps_its_own_border_and_emphasis_only_moves_elevation(): void
    {
        $html = Blade::render('<x-mk.card intent="success" emphasis="strong">Isi</x-mk.card>');

        // The intent surface still owns border AND background (§3.3's
        // cemetery/service-row variants); `strong` contributes elevation
        // only and must never overwrite that border with `primary-200`.
        $this->assertStringContainsString('border-[var(--mk-intent-success-border)]', $html);
        $this->assertStringContainsString('bg-[var(--mk-intent-success-bg)]', $html);
        $this->assertStringContainsString('shadow-md', $html);
        $this->assertStringNotContainsString('border-primary-200', $html);
    }

    public function test_the_interactive_hover_shadow_is_one_step_above_each_emphasis_resting_level(): void
    {
        $quiet = Blade::render('<x-mk.card as="a" interactive href="/faq/x" emphasis="quiet">Isi</x-mk.card>');
        $base = Blade::render('<x-mk.card as="a" interactive href="/faq/x">Isi</x-mk.card>');
        $strong = Blade::render('<x-mk.card as="a" interactive href="/pemesanan-makam" emphasis="strong">Isi</x-mk.card>');

        $this->assertStringContainsString('hover:shadow-sm', $quiet);
        $this->assertStringContainsString('hover:shadow-md', $base);
        // Capped at `md`: §1.5 keeps `lg` for modals, so a `strong` card's
        // hover feedback is its border and the `hover:bg-primary-50` tint.
        $this->assertStringContainsString('hover:shadow-md', $strong);
        $this->assertStringNotContainsString('hover:shadow-lg', $strong);

        // The tint hover is unchanged by emphasis for an intent-less card.
        $this->assertStringContainsString('hover:bg-primary-50', $strong);
    }
}
