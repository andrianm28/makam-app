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
        // The fallback slot's content is a plain <a>, not <x-mk.button>,
        // deliberately: this test proves gate-closed-page's OWN slot
        // mechanism (does content passed to `fallback`/`support` render at
        // all), not button.blade.php's behavior — a different component's
        // test already covers that. `Blade::render()` (an ad-hoc string
        // compile, not the normal view-rendering path any real page uses)
        // has a documented limitation with a component that both has
        // @props defaults AND is nested inside a named slot of another
        // Blade::render() string: the nested component's props default
        // extraction did not run, producing "Undefined variable $loading"
        // for <x-mk.button> specifically in that position. Real usage
        // (a real Blade view rendering <x-mk.button> normally, including
        // nested in a slot) is unaffected — only this specific ad-hoc
        // testing pattern reproduces it. Confirmed 26 Jul 2026 against the
        // first real Postgres CI run (this repo's SQLite-vs-Postgres split
        // did not surface it locally as a php -l/syntax issue since it is
        // a runtime view-compilation behavior, not a syntax error).
        $html = Blade::render(<<<'BLADE'
            <x-mk.gate-closed-page heading="Fitur ini belum tersedia">
                Penjelasan singkat.
                <x-slot:fallback>
                    <a href="/kontak">Daftar minat</a>
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
