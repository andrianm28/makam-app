<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Legal;

use App\Platform\SiteSettings\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/syarat-ketentuan` — App\Livewire\Public\Legal\TermsOfService.
 * Presentation-only batch closing the real footer 404 gap; see that
 * class's own doc block.
 */
final class TermsOfServiceRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same requirement/reasoning as every other public Livewire route
        // test in this repo — see PrivacyPolicyRouteTest's own comment.
        $this->withoutVite();
    }

    public function test_terms_of_service_returns_ok(): void
    {
        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
    }

    public function test_terms_of_service_has_the_real_heading(): void
    {
        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
        $response->assertSee('Syarat &amp; Ketentuan', false);
    }

    public function test_terms_of_service_carries_the_draft_notice_honesty_line(): void
    {
        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
        $response->assertSee('Dokumen ini adalah draf awal dan akan diperbarui setelah tinjauan hukum resmi.');
    }

    public function test_terms_of_service_covers_the_required_topics(): void
    {
        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
        $response->assertSee('Definisi Layanan');
        $response->assertSee('Syarat Pemesanan');
        $response->assertSee('Kebijakan Pembayaran');
        $response->assertSee('Kewajiban Pengguna');
        $response->assertSee('Batasan Tanggung Jawab Platform');
        $response->assertSee('Hukum yang Berlaku');
    }

    public function test_terms_of_service_does_not_fabricate_a_specific_refund_percentage_or_timeline(): void
    {
        // assumptions-and-gates.md §5 item 8 ("merchant of record, refund,
        // chargeback, fees, tax, vendor settlement") is a real open
        // business/legal decision — this page must say the payment/refund
        // terms are being finalised, not invent a specific percentage or
        // number of days as if the decision were already made.
        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
        $response->assertDontSee('%');
        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(hari|jam)\b/i', $body);
        $response->assertSee('masih dalam proses finalisasi');
    }

    public function test_terms_of_service_references_the_canonical_four_services_not_invented_labels(): void
    {
        // AGENTS.md Mandatory MVP UX: exactly these four labels, unchanged.
        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
        $response->assertSee('Pemesanan Makam');
        $response->assertSee('Layanan Pemakaman');
        $response->assertSee('Perpanjangan Makam');
        $response->assertSee('FAQ');
    }

    public function test_terms_of_service_page_title_is_set(): void
    {
        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
        $response->assertSee('<title>Syarat &amp; Ketentuan - Makam.co.id</title>', false);
    }

    /**
     * Same H3-as-admin-editable-field behaviour as PrivacyPolicyRouteTest's
     * own test — see that test's doc block.
     */
    public function test_a_configured_legal_review_note_supersedes_the_draft_disclaimer(): void
    {
        SiteSetting::query()->create([
            'key' => SiteSetting::KEY_LEGAL_REVIEW_NOTE,
            'value' => 'Ditinjau 1 Sep 2026 oleh Firma Hukum Contoh',
        ]);

        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
        $response->assertSee('Ditinjau 1 Sep 2026 oleh Firma Hukum Contoh');
        $response->assertDontSee('Dokumen ini adalah draf awal dan akan diperbarui setelah tinjauan hukum resmi.');
    }

    public function test_no_nib_line_is_rendered_until_an_operator_configures_one(): void
    {
        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
        $response->assertDontSee('NIB:');
    }

    public function test_a_configured_nib_is_rendered(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_COMPANY_NIB, 'value' => '1234567890123']);

        $response = $this->get('/syarat-ketentuan');

        $response->assertOk();
        $response->assertSee('NIB: 1234567890123');
    }
}
