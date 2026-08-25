<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Legal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * layouts/app.blade.php's shared footer — verifies the `/privasi` and
 * `/syarat-ketentuan` links were switched from raw, previously-404ing
 * `<a href>`s to `route('legal.privacy')` / `route('legal.terms')` calls,
 * and that both now resolve to a real 200 rather than the bare 404 the
 * footer's own (pre-this-batch) doc comment named as a known gap. Also
 * covers the new fictional company legal-entity line the same footer
 * update adds.
 *
 * Exercised via `/privasi` rather than the homepage: the footer is shared
 * page-shell content rendered on every page using `layouts.app`, not
 * homepage-specific, and `/privasi` is a route this batch itself owns —
 * asserting through it keeps this test independent of HomePage's own
 * (unrelated, parallel-batch-owned) domain reads.
 */
final class FooterLegalLinksRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_legal_routes_are_registered_with_the_expected_names_and_uris(): void
    {
        // Route::uri() returns the URI WITHOUT a leading slash (Laravel's
        // own registration behaviour, not this batch's convention) — the
        // original assertion here expected a leading '/' and failed the
        // first real CI run over that alone.
        $this->assertSame('privasi', Route::getRoutes()->getByName('legal.privacy')?->uri());
        $this->assertSame('syarat-ketentuan', Route::getRoutes()->getByName('legal.terms')?->uri());
    }

    public function test_footer_privacy_and_terms_links_are_generated_via_named_routes_and_resolve_to_a_real_page(): void
    {
        $response = $this->get('/privasi');
        $response->assertOk();

        // The rendered href must match exactly what route() produces for
        // the real named routes — proving the footer no longer hardcodes
        // these paths as bare strings.
        $response->assertSee('href="'.route('legal.privacy').'"', false);
        $response->assertSee('href="'.route('legal.terms').'"', false);

        // Following the links themselves must 200, not 404 — the concrete
        // evidence that these are now real, working routes and not the
        // dead links the footer's doc comment previously described.
        $this->get(route('legal.privacy'))->assertOk();
        $this->get(route('legal.terms'))->assertOk();
    }

    /**
     * RENAMED from `test_bantuan_link_remains_an_honest_unbuilt_forward_
     * reference`, which asserted `$this->get('/bantuan')->assertNotFound()`.
     *
     * That assertion was correct when written on 26 Jul 2026: `/bantuan` was
     * explicitly out of that batch's scope, so the honest thing was to pin
     * the gap rather than pretend it did not exist. But pinning a gap means
     * the test starts guarding it — and this one would have failed the
     * moment the gap was closed, which is exactly what happened.
     *
     * The premise changed on 8 Aug 2026. `/bantuan` was found to be linked
     * from `<x-mk.header>` on EVERY page plus seven further views while no
     * route backed it, making `design-system.md` §6.10's mandatory support
     * escape hatch a site-wide 404. `App\Livewire\Public\Support\HelpCentre`
     * (PUB-060) now serves it. This test asserts the new reality directly —
     * the footer still links it, and following the link now reaches a real
     * page — instead of asserting a now-false negative.
     */
    public function test_bantuan_link_from_the_footer_reaches_the_real_help_page(): void
    {
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('href="/bantuan"', false);

        // The link is still a plain href rather than a route() call in the
        // shared footer/header markup, so following it is the only thing
        // that actually proves the destination exists.
        $this->get('/bantuan')->assertOk();
    }

    public function test_footer_shows_the_fictional_company_legal_entity_line(): void
    {
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('PT Contoh Makam Digital Indonesia');
        $response->assertSee('Jl. Contoh Cendana No. 88, Kuningan, Jakarta Selatan');
    }
}
