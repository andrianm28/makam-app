<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Legal;

use App\Platform\SiteSettings\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/privasi` — App\Livewire\Public\Legal\PrivacyPolicy. Presentation-only
 * batch closing the real footer 404 gap; see that class's own doc block.
 */
final class PrivacyPolicyRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real HTTP requests below render the full layout (`@vite(...)` in
        // layouts/app.blade.php); this host's CI `php` job has no prior
        // frontend build. Same requirement/reasoning as every other public
        // Livewire route test in this repo (e.g. FaqIndexRouteTest,
        // HomePageRouteTest).
        $this->withoutVite();
    }

    public function test_privacy_policy_returns_ok(): void
    {
        $response = $this->get('/privasi');

        $response->assertOk();
    }

    public function test_privacy_policy_has_the_real_heading(): void
    {
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('Kebijakan Privasi');
    }

    public function test_privacy_policy_carries_the_draft_notice_honesty_line(): void
    {
        // This exact sentence is the one honesty marker the batch's brief
        // requires to stay regardless of "show the full page publicly" —
        // a fabricated-but-unlabeled legal document creates real legal
        // exposure a fabricated marketing page does not. This test exists
        // so a future edit that drops the sentence fails CI rather than
        // going unnoticed.
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('Dokumen ini adalah draf awal dan akan diperbarui setelah tinjauan hukum resmi.');
    }

    public function test_privacy_policy_stays_consistent_with_the_homepages_private_document_storage_claim(): void
    {
        // home-page.blade.php already promises: "Dokumen seperti KTP, KK,
        // dan surat keterangan kematian disimpan secara privat dan
        // diperiksa sebelum dapat diakses siapa pun, termasuk tim kami." —
        // this page must not contradict that established discipline.
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('Dokumen seperti KTP, KK, dan surat keterangan kematian disimpan secara privat dan diperiksa sebelum dapat diakses siapa pun, termasuk tim kami.');
    }

    public function test_privacy_policy_covers_the_required_topics(): void
    {
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('Data yang Kami Kumpulkan');
        $response->assertSee('Bagaimana Data Digunakan');
        $response->assertSee('Penyimpanan Dokumen Privat');
        $response->assertSee('Berapa Lama Data Disimpan');
        $response->assertSee('Hak Anda');
        $response->assertSee('Kontak');
    }

    public function test_privacy_policy_does_not_fabricate_a_specific_final_retention_period(): void
    {
        // assumptions-and-gates.md §5 item 11 ("document retention ...")
        // is a real open decision — this page must say the policy is being
        // finalised, not invent a specific number of years/months as if
        // settled. A concrete duration would use a digit immediately
        // followed by "tahun"/"bulan"; assert that pattern is absent.
        $response = $this->get('/privasi');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(tahun|bulan)/i', $body);
        $response->assertSee('masih dalam proses finalisasi');
    }

    public function test_privacy_policy_page_title_is_set(): void
    {
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('<title>Kebijakan Privasi - Makam.co.id</title>', false);
    }

    /**
     * H3 as an admin-editable field (App\Support\LegalReviewStatus) — once
     * an operator enters a review confirmation via the admin Site Settings
     * page, the draft disclaimer must stop showing and the confirmation
     * text must appear instead. No code deploy required for this
     * transition.
     */
    public function test_a_configured_legal_review_note_supersedes_the_draft_disclaimer(): void
    {
        SiteSetting::query()->create([
            'key' => SiteSetting::KEY_LEGAL_REVIEW_NOTE,
            'value' => 'Ditinjau 1 Sep 2026 oleh Firma Hukum Contoh',
        ]);

        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('Ditinjau 1 Sep 2026 oleh Firma Hukum Contoh');
        $response->assertDontSee('Dokumen ini adalah draf awal dan akan diperbarui setelah tinjauan hukum resmi.');
    }

    public function test_no_nib_line_is_rendered_until_an_operator_configures_one(): void
    {
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertDontSee('NIB:');
    }

    public function test_a_configured_nib_is_rendered(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_COMPANY_NIB, 'value' => '1234567890123']);

        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('NIB: 1234567890123');
    }
}
