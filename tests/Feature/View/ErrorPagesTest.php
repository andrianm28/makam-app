<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * `resources/views/errors/404.blade.php` — a real, unmatched route must fall
 * through to this app's own branded, calm, plain-Indonesian empty state, not
 * Laravel's raw unstyled default 404 page. See that view's own doc block for
 * why it is a minimal standalone document rather than `layouts.app`.
 *
 * `withoutVite()` in `setUp()` follows the exact same convention
 * `BrandIdentityTest` already established for a real full-page GET: without
 * it, `@vite(...)` throws `ViteManifestNotFoundException` on this host (no
 * frontend build here — `CLAUDE.md`'s Scope note), and
 * `Illuminate\Foundation\Exceptions\Handler::renderHttpException()` silently
 * swallows that (its own `catch (Throwable $t)` block, since `app.debug` is
 * off in testing) and falls back to Laravel's generic Symfony-rendered page
 * — which still returns the correct 404 status (why the *other* 404 tests
 * across this suite that only assert status, not content, keep passing
 * either way) but would make THIS test's content assertions false negatives.
 */
final class ErrorPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * A route that has never existed and never will — proves the branded
     * page renders for the generic "no matching route" case, not just for
     * an in-app `abort(404)` inside a known route (that path is covered
     * separately by `test_an_abort_404_inside_a_known_route_also_gets_the_branded_page`
     * below).
     */
    public function test_an_unmatched_route_returns_the_branded_404_page(): void
    {
        $response = $this->get('/this-route-does-not-exist-'.uniqid());

        $response->assertNotFound();

        $html = $response->getContent();

        $this->assertStringContainsString('Halaman tidak ditemukan', $html);
        $this->assertStringContainsString('Tautan yang Anda buka mungkin salah ketik', $html);
        $this->assertStringContainsString('Kembali ke beranda', $html);
        $this->assertStringContainsString('href="/"', $html);

        // Real Makam.co.id brand mark, not Laravel's raw default page —
        // same evidence BrandIdentityTest uses for the homepage shell.
        $this->assertStringContainsString('brand/mark-96.png', $html);

        // Laravel's own default 404 copy must be gone, proving this is our
        // view and not a silent fallback.
        $this->assertStringNotContainsString('Not Found', $html);
    }

    /**
     * `App\Livewire\Public\Invoices\InvoiceReceiptPage::mount()` calls
     * `abort(404)` directly for an unmatched `/kwitansi/{reference}` — the
     * exact real-world trigger named in the UI/UX audit finding this page
     * closes. Proves the branded page covers an in-app `abort(404)` inside a
     * registered route, not only routing's own 404.
     */
    public function test_an_abort_404_inside_a_known_route_also_gets_the_branded_page(): void
    {
        $response = $this->get(route('invoice.show', ['reference' => 'INV-DOESNOTEXIST']));

        $response->assertNotFound();
        $this->assertStringContainsString('Halaman tidak ditemukan', $response->getContent());
    }
}
