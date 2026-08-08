<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Support;

use App\Support\CompanyInfo;
use App\Support\ContactInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `/bantuan` — App\Livewire\Public\Support\HelpCentre, screen PUB-060.
 *
 * These tests describe the fix for a real defect found 8 Aug 2026: the
 * persistent "Bantuan" action `<x-mk.header>` renders on EVERY page (plus
 * seven further views) pointed at `/bantuan`, which no route backed, so the
 * one escape hatch design-system.md §6.10 makes mandatory 404d everywhere.
 *
 * Every test below therefore fails until the route is registered:
 *
 *     Route::get('/bantuan', HelpCentre::class)->name('bantuan.index');
 *
 * That registration is deliberately NOT part of this batch (routes/web.php
 * is single-writer and owned elsewhere), so a red run here before wiring is
 * the expected state, not a broken test.
 *
 * Related: tests/Feature/Livewire/Public/Legal/FooterLegalLinksRouteTest's
 * `test_bantuan_link_remains_an_honest_unbuilt_forward_reference` asserts
 * the OPPOSITE (`$this->get('/bantuan')->assertNotFound()`). It encodes the
 * now-fixed gap and must be updated by whoever owns that file when the
 * route lands; it was not edited from here.
 */
final class HelpCentreRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real HTTP requests below render the full layout, which calls
        // `@vite(...)`; CI's `php` job has no frontend build. Same
        // requirement as every other public Livewire route test here.
        $this->withoutVite();
    }

    public function test_bantuan_route_is_registered_with_the_expected_name_and_uri(): void
    {
        // Route::uri() has no leading slash — Laravel's own registration
        // behaviour, the exact detail that failed a previous CI run in
        // FooterLegalLinksRouteTest.
        $this->assertSame('bantuan', Route::getRoutes()->getByName('bantuan.index')?->uri());
    }

    public function test_bantuan_returns_ok_instead_of_the_previous_404(): void
    {
        $this->get('/bantuan')->assertOk();
    }

    public function test_the_persistent_header_bantuan_action_now_resolves_on_every_page(): void
    {
        // This is the regression test for the defect itself. `<x-mk.header>`
        // is shared page-shell content rendered by layouts/app.blade.php, so
        // asserting through an unrelated, stable route proves the link works
        // from anywhere — not just from the help page linking to itself.
        $response = $this->get('/privasi');
        $response->assertOk();
        $response->assertSee('href="/bantuan"', false);

        $this->get('/bantuan')->assertOk();
    }

    public function test_help_centre_has_the_real_heading_and_page_title(): void
    {
        $response = $this->get('/bantuan');

        $response->assertOk();
        $response->assertSee('Bantuan dan Kontak');
        $response->assertSee('<title>Bantuan dan Kontak - Makam.co.id</title>', false);
    }

    public function test_help_centre_states_the_channels_screen_inventory_requires(): void
    {
        // PUB-060: "channels, hours, emergency disclaimer". Asserted through
        // the ContactInfo constants rather than literal strings so this test
        // cannot become a second hand-maintained copy of the canonical
        // contact data (AGENTS.md §Documentation).
        $response = $this->get('/bantuan');

        $response->assertOk();
        $response->assertSee(ContactInfo::PHONE);
        $response->assertSee(ContactInfo::EMAIL);
        $response->assertSee('Telepon');
        $response->assertSee('WhatsApp');
    }

    public function test_help_centre_states_the_operating_hours(): void
    {
        $response = $this->get('/bantuan');

        $response->assertOk();
        $response->assertSee(ContactInfo::BUSINESS_HOURS);
    }

    public function test_help_centre_carries_the_emergency_disclaimer(): void
    {
        $response = $this->get('/bantuan');

        $response->assertOk();
        $response->assertSee('Halaman ini bukan layanan gawat darurat');
        $response->assertSee('Segera hubungi layanan gawat darurat setempat, rumah sakit terdekat, atau aparat yang berwenang di wilayah Anda.');
    }

    public function test_emergency_disclaimer_appears_before_the_contact_channels(): void
    {
        // A safety-ordering assertion, not a cosmetic one: someone landing
        // here hours after a death must read "this is not an emergency
        // service" before they read a phone number. Scoped to the body so
        // the <title> cannot produce a false match.
        $response = $this->get('/bantuan');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);
        $body = substr($body, (int) strpos($body, '<body'));

        $disclaimerAt = strpos($body, 'Halaman ini bukan layanan gawat darurat');
        $channelsAt = strpos($body, 'Kanal Komunikasi');

        $this->assertNotFalse($disclaimerAt);
        $this->assertNotFalse($channelsAt);
        $this->assertLessThan($channelsAt, $disclaimerAt);
    }

    public function test_help_centre_admits_the_contact_channels_are_not_live_yet(): void
    {
        // App\Support\ContactInfo's doc block is explicit that all four
        // constants are placeholders and that EMAIL "is NOT actually
        // configured to receive mail today". A funeral platform showing a
        // hotline it cannot answer is a promise to someone in crisis, so
        // this admission is a required part of the screen. This test exists
        // so a future edit that quietly drops it fails CI.
        $response = $this->get('/bantuan');

        $response->assertOk();
        $response->assertSee('Kanal di atas belum aktif');
    }

    public function test_help_centre_does_not_claim_round_the_clock_availability(): void
    {
        // Nothing in this repository backs a 24/7 support claim; UrgentMode
        // documents that G-OPS-01 (Urgent/At-Need acceptance) is seeded
        // CLOSED and that the platform never claims automatic acceptance.
        $response = $this->get('/bantuan');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);
        $body = substr($body, (int) strpos($body, '<body'));

        $this->assertDoesNotMatchRegularExpression('/24\s*\/\s*7/', $body);
        $this->assertDoesNotMatchRegularExpression('/24\s*jam\s+(nonstop|non-stop|sehari|setiap\s+hari)/i', $body);
        $this->assertStringContainsString('kami tidak beroperasi 24 jam', $body);
    }

    public function test_help_centre_does_not_promise_a_response_time_or_sla(): void
    {
        // No SLA, ticket number, or guaranteed callback window is configured
        // anywhere in this repository. A concrete promise would read as a
        // digit followed by a time unit ("dalam 2 jam", "1x24 jam").
        $response = $this->get('/bantuan');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);
        $body = substr($body, (int) strpos($body, '<body'));

        // The rendered hours string legitimately contains digits, so it is
        // removed before the promise pattern is applied — otherwise this
        // assertion would fail on the very data it is meant to protect.
        $withoutHours = str_replace(ContactInfo::BUSINESS_HOURS, '', $body);

        $this->assertDoesNotMatchRegularExpression('/dalam\s+\d+\s*(x\s*\d+\s*)?(menit|jam|hari)/i', $withoutHours);
        $this->assertStringContainsString('Kami tidak menjanjikan durasi balasan tertentu', $body);
    }

    public function test_help_centre_works_without_javascript(): void
    {
        // design-system.md §6.10: the escape hatch is "never a chat-bubble-
        // only affordance — it must work with JS disabled". This page is
        // static by construction (see HelpCentre's doc block), so the proof
        // is that no interactive Livewire or Alpine binding appears at all:
        // the channels, hours, and disclaimer are plain server-rendered HTML.
        $response = $this->get('/bantuan');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);
        $main = substr($body, (int) strpos($body, '<main'));

        $this->assertStringNotContainsString('wire:click', $main);
        $this->assertStringNotContainsString('wire:model', $main);
        $this->assertStringNotContainsString('wire:submit', $main);
        $this->assertStringNotContainsString('<form', $main);
    }

    public function test_help_centre_links_onward_to_the_faq_and_the_homepage(): void
    {
        // §6.2's "never a dead end" discipline: this page is where other
        // screens send a stuck user, so it must itself offer a next step.
        $response = $this->get('/bantuan');

        $response->assertOk();
        $response->assertSee('href="'.route('faq.index').'"', false);
        $response->assertSee('href="'.route('home').'"', false);
    }

    public function test_help_centre_names_the_legal_entity_from_the_canonical_source(): void
    {
        $response = $this->get('/bantuan');

        $response->assertOk();
        $response->assertSee(CompanyInfo::NAME);
        $response->assertSee(CompanyInfo::ADDRESS);
    }

    public function test_help_centre_does_not_contradict_the_seeded_faq_on_extra_channels(): void
    {
        // The published FAQ article `bagaimana-menghubungi-customer-service`
        // still says additional channels "seperti WhatsApp" will be
        // announced when they become available, so this page must not mint
        // a wa.me deep link implying a live, monitored WhatsApp account.
        $response = $this->get('/bantuan');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);

        $this->assertStringNotContainsString('wa.me', $body);
        $this->assertStringNotContainsString('api.whatsapp.com', $body);
    }
}
