<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Domain\OrderWorkflow\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
 * ---------------------------------------------------------------------------
 * THESE TESTS ARE MEANINGLESS ON SQLITE — so the driver is ENFORCED, not
 * merely stated
 * ---------------------------------------------------------------------------
 * SQLite happily compares a string to a uuid column and simply matches
 * nothing, so every assertion below passes on SQLite whether or not the
 * guards exist. PostgreSQL is the only place the bug is visible at all.
 *
 * That used to be a sentence in this doc block, which is a statement and not
 * a guard — the same distinction this branch spent a commit making about a
 * schedule test. It matters here because the default really is SQLite:
 * `phpunit.xml:59-60` sets `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`
 * WITHOUT `force="true"`, so a pre-set environment variable wins. CI sets
 * `DB_CONNECTION=pgsql` against a `postgres:18` service and is fine; a
 * developer running plain `vendor/bin/phpunit` is not, and would have got
 * six reassuring green ticks proving nothing.
 *
 * `setUp()` therefore asserts the driver. A `markTestSkipped` was the
 * alternative and was rejected: a skip is skimmed past in a long run,
 * whereas these tests going red is exactly the signal someone needs before
 * they conclude the guards work.
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

        $this->assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'These tests assert that a non-UUID id does NOT 500. Only PostgreSQL raises SQLSTATE 22P02 on '
            .'that comparison — SQLite matches nothing and every assertion below passes whether or not the '
            .'guards exist, so a green run here on SQLite proves nothing at all. `phpunit.xml` pins '
            .'`DB_CONNECTION=sqlite` without `force="true"`, so set DB_CONNECTION=pgsql (plus DB_HOST/'
            .'DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD) in the environment and re-run. CI already does.'
        );
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
