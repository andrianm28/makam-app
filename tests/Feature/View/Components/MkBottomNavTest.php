<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-mk.bottom-nav> — the five-tab mobile persistent navigation
 * primitive, FFI full-visual-clone design doc §3, Stage 2 ticket 02,
 * approved by ADR-0044. Unwired: no real screen renders this yet
 * (Stage 3's job) -- these tests render the component in isolation via
 * Blade::render(), matching MkCardTest/MkIconMedallionTest/
 * MkSkeletonTest's established seam.
 *
 * `active` is an explicit prop (matching header.blade.php's own
 * established convention), not request-path auto-detection -- see this
 * plan's Global Constraints for why that deviates from the ticket's own
 * wording. Tests pass it directly; no request-faking needed.
 */
final class MkBottomNavTest extends TestCase
{
    public function test_all_five_tabs_render_with_correct_hrefs_and_order(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $expectedOrder = [
            'href="/"' => 'Beranda',
            'href="/pemesanan-makam"' => 'Pemesanan',
            'href="/perpanjangan"' => 'Perpanjangan',
            'href="/akun"' => 'Akun',
            'href="/bantuan"' => 'Bantuan',
        ];

        $lastPosition = -1;
        foreach ($expectedOrder as $href => $label) {
            $this->assertStringContainsString($href, $html);
            $position = strpos($html, $href);
            $this->assertGreaterThan($lastPosition, $position, "$href out of order");
            $lastPosition = $position;
        }
    }

    public function test_renders_exactly_the_five_canonical_indonesian_labels(): void
    {
        // ADR-0044 names this exact 5-tab set as the approved decision and
        // AGENTS.md forbids inventing alternate navigation labels -- these
        // strings are a product contract, not implementation detail.
        $html = Blade::render('<x-mk.bottom-nav />');

        foreach (['Beranda', 'Pemesanan', 'Perpanjangan', 'Akun', 'Bantuan'] as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $html);
        }
    }

    public function test_renders_exactly_five_tabs(): void
    {
        // ADR-0044: "does not approve or design any navigation item beyond
        // the five named here (no sixth tab...)" -- pin the count so a
        // stray addition fails loudly instead of passing the ordering
        // check above by coincidence.
        $html = Blade::render('<x-mk.bottom-nav />');

        $this->assertSame(5, substr_count($html, '<li>'));
    }

    public function test_hidden_above_lg_breakpoint(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $this->assertStringContainsString('lg:hidden', $html);
    }

    public function test_nav_landmark_wraps_the_whole_component(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('aria-label="Navigasi utama"', $html);
    }

    public function test_default_active_is_null_and_renders_no_aria_current(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $this->assertStringNotContainsString('aria-current="page"', $html);
    }

    public function test_active_akun_gets_aria_current_and_shape_and_colour_marking(): void
    {
        $html = Blade::render('<x-mk.bottom-nav active="akun" />');

        // aria-current="page" appears exactly once, on the Akun anchor.
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));

        $akunAnchorStart = strpos($html, 'href="/akun"');
        $akunAnchorEnd = strpos($html, '</a>', $akunAnchorStart);
        $akunAnchor = substr($html, $akunAnchorStart, $akunAnchorEnd - $akunAnchorStart);

        $this->assertStringContainsString('aria-current="page"', $akunAnchor);
        $this->assertStringContainsString('text-primary-700', $akunAnchor);
        $this->assertStringContainsString('border-t-2', $akunAnchor);
        $this->assertStringContainsString('border-primary-600', $akunAnchor);
        $this->assertStringNotContainsString('border-transparent', $akunAnchor);
    }

    public function test_active_bantuan_gets_aria_current_and_shape_and_colour_marking(): void
    {
        $html = Blade::render('<x-mk.bottom-nav active="bantuan" />');

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));

        $bantuanAnchorStart = strpos($html, 'href="/bantuan"');
        $bantuanAnchorEnd = strpos($html, '</a>', $bantuanAnchorStart);
        $bantuanAnchor = substr($html, $bantuanAnchorStart, $bantuanAnchorEnd - $bantuanAnchorStart);

        $this->assertStringContainsString('aria-current="page"', $bantuanAnchor);
        $this->assertStringContainsString('text-primary-700', $bantuanAnchor);
        $this->assertStringContainsString('border-t-2', $bantuanAnchor);
        $this->assertStringContainsString('border-primary-600', $bantuanAnchor);
        $this->assertStringNotContainsString('border-transparent', $bantuanAnchor);
    }

    public function test_inactive_tabs_carry_transparent_border_not_no_border(): void
    {
        // Same layout-stability technique header.blade.php already uses:
        // inactive tabs hold the border's space with a transparent one,
        // so activating a different tab never shifts anything.
        $html = Blade::render('<x-mk.bottom-nav active="akun" />');

        $berandaAnchorStart = strpos($html, 'href="/"');
        $berandaAnchorEnd = strpos($html, '</a>', $berandaAnchorStart);
        $berandaAnchor = substr($html, $berandaAnchorStart, $berandaAnchorEnd - $berandaAnchorStart);

        $this->assertStringContainsString('border-t-2', $berandaAnchor);
        $this->assertStringContainsString('border-transparent', $berandaAnchor);
        $this->assertStringNotContainsString('aria-current="page"', $berandaAnchor);
    }

    public function test_uses_the_bottomnav_zindex_and_height_tokens(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $this->assertStringContainsString('z-bottomnav', $html);
        $this->assertStringContainsString('mk-bottomnav-total', $html);
        $this->assertStringContainsString('mk-bottomnav-h', $html);
        $this->assertStringContainsString('mk-safe-bottom', $html);
        $this->assertStringContainsString('duration-fast', $html);
    }
}
