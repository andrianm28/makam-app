<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Legal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `/wakaf-tanah` — App\Livewire\Public\Legal\WakafTanah. Closes AC12 of
 * `.kiro/specs/public-home-and-navigation` (PUB-072); see that class's own
 * doc block.
 */
final class WakafTanahRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_wakaf_tanah_returns_ok(): void
    {
        $response = $this->get('/wakaf-tanah');

        $response->assertOk();
    }

    public function test_wakaf_tanah_route_is_registered_with_the_expected_name_and_uri(): void
    {
        $this->assertSame('wakaf-tanah', Route::getRoutes()->getByName('legal.wakaf-tanah')?->uri());
    }

    public function test_wakaf_tanah_has_the_real_heading(): void
    {
        $response = $this->get('/wakaf-tanah');

        $response->assertOk();
        $response->assertSee('Wakaf Tanah');
    }

    public function test_wakaf_tanah_covers_the_four_ac12_required_topics(): void
    {
        // AC12: purpose, general requirements, the six-step process as
        // information, and the help-centre contact channel.
        $response = $this->get('/wakaf-tanah');

        $response->assertOk();
        $response->assertSee('Tentang Wakaf Tanah');
        $response->assertSee('Syarat Umum');
        $response->assertSee('Gambaran Proses');
        $response->assertSee('Kontak');
    }

    public function test_wakaf_tanah_presents_all_six_process_steps_as_information(): void
    {
        $response = $this->get('/wakaf-tanah');

        $response->assertOk();
        $response->assertSee('Pilih tujuan wakaf');
        $response->assertSee('Isi data pemilik dan tanah');
        $response->assertSee('Verifikasi dan survei');
        $response->assertSee('Proses administrasi');
        $response->assertSee('Penilaian komersial atau non komersial');
        $response->assertSee('Dokumentasi dan serah terima');
    }

    public function test_wakaf_tanah_has_no_form_no_upload_and_no_interest_registration_control(): void
    {
        // AC12: "SHALL NOT present a form, accept an upload, or register
        // interest through this page." Assert the negative directly
        // against the real rendered HTML, not just the absence of a
        // specific known control.
        $response = $this->get('/wakaf-tanah');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertNotFalse($html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('type="file"', $html);
        $this->assertStringNotContainsString('Ajukan', $html);
        $this->assertStringNotContainsString('Daftar Minat', $html);
    }

    public function test_wakaf_tanah_does_not_fabricate_specific_eligibility_requirements(): void
    {
        // The PRD names "syarat umum" as a required section but never
        // states what those requirements actually are — this page must
        // say the requirements are still being finalised, not invent
        // them, matching PrivacyPolicy's own retention-period discipline.
        $response = $this->get('/wakaf-tanah');

        $response->assertOk();
        $response->assertSee('masih dalam proses finalisasi');
    }

    public function test_wakaf_tanah_links_to_the_real_help_centre(): void
    {
        $response = $this->get('/wakaf-tanah');

        $response->assertOk();
        $response->assertSee('href="/bantuan"', false);

        $this->get('/bantuan')->assertOk();
    }

    public function test_wakaf_tanah_page_title_is_set(): void
    {
        $response = $this->get('/wakaf-tanah');

        $response->assertOk();
        $response->assertSee('<title>Wakaf Tanah - Makam.co.id</title>', false);
    }

    public function test_wakaf_tanah_bottom_nav_renders_with_no_active_tab(): void
    {
        // /wakaf-tanah is not one of the bottom nav's five tabs — same
        // convention as FaqIndexRouteTest::
        // test_bottom_nav_renders_with_no_active_tab.
        $response = $this->get('/wakaf-tanah');

        $response->assertSee('aria-label="Navigasi utama"', false);

        $html = $response->getContent();
        $this->assertNotFalse($html);
        $start = strpos($html, 'aria-label="Navigasi utama"');
        $this->assertNotFalse($start, 'Bottom nav not found');
        $end = strpos($html, '</nav>', $start);
        $this->assertNotFalse($end, 'Bottom nav is unterminated');

        $bottomNav = substr($html, $start, $end - $start);
        $this->assertSame(0, substr_count($bottomNav, 'aria-current="page"'), 'No tab should be active in bottom nav for Wakaf Tanah');
    }
}
