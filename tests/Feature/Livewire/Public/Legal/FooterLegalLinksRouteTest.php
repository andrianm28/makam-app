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
        $this->assertSame('/privasi', Route::getRoutes()->getByName('legal.privacy')?->uri());
        $this->assertSame('/syarat-ketentuan', Route::getRoutes()->getByName('legal.terms')?->uri());
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

    public function test_bantuan_link_remains_an_honest_unbuilt_forward_reference(): void
    {
        // /bantuan is explicitly out of this batch's scope — still a plain
        // href, not a route() call, and still genuinely unbuilt: no route
        // backs it, so it still 404s exactly as before this batch.
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('href="/bantuan"', false);
        $this->get('/bantuan')->assertNotFound();
    }

    public function test_footer_shows_the_fictional_company_legal_entity_line(): void
    {
        $response = $this->get('/privasi');

        $response->assertOk();
        $response->assertSee('PT Contoh Makam Digital Indonesia');
        $response->assertSee('Jl. Contoh Cendana No. 88, Kuningan, Jakarta Selatan');
    }
}
