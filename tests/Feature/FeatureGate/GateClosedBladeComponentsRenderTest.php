<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Rendering smoke test for `<x-mk.gate-closed-banner>` and
 * `<x-mk.gate-closed-page>` — design.md's Fallback contract ("The
 * explanatory state is a first-class UI state, not an error") and
 * design-system.md §6.9/§6.4. Proves the two new components compile and
 * render without a PHP error, and checks the handful of structural
 * contracts the doc blocks in those files promise: `role="status"` for the
 * ambient banner, no dismiss control when `dismissible` is false, and the
 * heading/slot content for the full page.
 *
 * Anonymous Blade components under `resources/views/components/mk/` are
 * auto-discovered by Laravel as `<x-mk.*>` — no explicit component
 * registration exists or is needed for either file.
 */
final class GateClosedBladeComponentsRenderTest extends TestCase
{
    public function test_gate_closed_banner_renders_as_an_ambient_status_region(): void
    {
        $html = Blade::render(
            '<x-mk.gate-closed-banner intent="info" :dismissible="false">Online payment not yet available; Step 8 uses manual coordination.</x-mk.gate-closed-banner>'
        );

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('Online payment not yet available', $html);
        // No dismissible=false close button — <x-mk.alert> only renders the
        // "Tutup" button when $dismissible is true.
        $this->assertStringNotContainsString('aria-label="Tutup"', $html);
    }

    public function test_gate_closed_banner_renders_a_dismiss_control_when_dismissible(): void
    {
        $html = Blade::render(
            '<x-mk.gate-closed-banner intent="info" :dismissible="true">WhatsApp not yet available; notifications via email + in-app.</x-mk.gate-closed-banner>'
        );

        $this->assertStringContainsString('aria-label="Tutup"', $html);
    }

    public function test_gate_closed_page_renders_heading_and_explanation(): void
    {
        $html = Blade::render(
            '<x-mk.gate-closed-page heading="Fitur ini belum tersedia">Pendaftaran minat tersedia; pembayaran belum dapat diproses.</x-mk.gate-closed-page>'
        );

        $this->assertStringContainsString('Fitur ini belum tersedia', $html);
        $this->assertStringContainsString('Pendaftaran minat tersedia', $html);
    }

    public function test_gate_closed_page_renders_optional_fallback_and_support_slots(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-mk.gate-closed-page heading="Fitur ini belum tersedia">
                Penjelasan singkat.
                <x-slot:fallback>
                    <x-mk.button href="/kontak">Daftar minat</x-mk.button>
                </x-slot:fallback>
                <x-slot:support>
                    Butuh bantuan? Hubungi 0800-1-MAKAM.
                </x-slot:support>
            </x-mk.gate-closed-page>
            BLADE);

        $this->assertStringContainsString('Daftar minat', $html);
        $this->assertStringContainsString('Butuh bantuan? Hubungi 0800-1-MAKAM.', $html);
    }
}
