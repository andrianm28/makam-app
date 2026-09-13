<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Domain\OrderWorkflow\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UXO-01 / FN-3 — a mistyped id returned HTTP 500 to a visitor.
 *
 * Every id in these URLs addresses a `uuid`-typed primary key. PostgreSQL
 * refuses to compare a non-UUID string against one (SQLSTATE 22P02,
 * "invalid input syntax for type uuid"), so a truncated WhatsApp link, an
 * old bookmark, or a typo did not reach the page's own not-found state at
 * all — it raised, and the visitor got the error page.
 *
 * The guard is the one this codebase already uses everywhere else
 * (`Str::isUuid()` before the lookup — `BookingDraftQuery`,
 * `DownloadDocument`, `RenewalPayment::resolveGrave()`,
 * `PreNeedInterestPage::resolveSubject()`, `BasePlotFloorMapPage`), and the
 * destination is each page's EXISTING not-found state, not a new one. The
 * right destination is not the same everywhere and this test does not
 * flatten them:
 *
 *   - `/sertifikat/...` already `abort(404)`s an unknown subject, and an
 *     unknown type, an unknown id and an ineligible type are deliberately
 *     indistinguishable there (no enumeration, no existence leak) — so a
 *     malformed id must be a 404 too, for the same reason;
 *   - `/kenangan/...`, `/perpanjangan/...` and `/pembayaran/...` render an
 *     in-page state with an explanation and a way forward, and a 200
 *     carrying that state is the honest answer rather than a bare 404.
 *
 * SQLite would mask every one of these: it happily compares a string to a
 * uuid column and simply matches nothing. This suite runs on PostgreSQL,
 * which is the only place the bug is visible.
 */
final class MalformedPublicIdDegradesHonestlyTest extends TestCase
{
    use RefreshDatabase;

    private const MALFORMED_ID = 'not-a-uuid';

    protected function setUp(): void
    {
        parent::setUp();

        // Full-layout renders reach layouts/app.blade.php's `@vite(...)`;
        // this host has no frontend build.
        $this->withoutVite();
    }

    public function test_the_memorial_family_page_renders_its_not_visible_state(): void
    {
        $this->get('/kenangan/'.self::MALFORMED_ID)
            ->assertOk()
            ->assertSee('Memorial tidak tersedia');
    }

    public function test_the_certificate_status_page_is_a_404_like_any_unknown_subject(): void
    {
        $this->get('/sertifikat/'.urlencode(Order::class).'/'.self::MALFORMED_ID)
            ->assertNotFound();
    }

    public function test_the_renewal_confirmation_page_renders_its_not_found_state(): void
    {
        $this->get(route('perpanjangan.konfirmasi', ['perpanjangan' => self::MALFORMED_ID]))
            ->assertOk()
            ->assertSee('Data perpanjangan tidak ditemukan.');
    }

    public function test_the_renewal_payment_page_renders_its_not_found_state(): void
    {
        $this->get(route('perpanjangan.pembayaran', ['perpanjangan' => self::MALFORMED_ID]))
            ->assertOk()
            ->assertSee('Data perpanjangan tidak ditemukan.');
    }

    public function test_the_payment_return_page_renders_its_pending_state(): void
    {
        $this->get(route('payments.return', ['session' => self::MALFORMED_ID]))
            ->assertOk();
    }

    public function test_the_payment_cancel_page_renders(): void
    {
        $this->get(route('payments.cancel', ['session' => self::MALFORMED_ID]))
            ->assertOk();
    }
}
