<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/perpanjangan/cari` — the route Task 4 of the wizard-screen-consolidation
 * plan removed when it merged `GraveSearch` into `RenewalStart`.
 *
 * It was a real, bookmarkable UI link while it existed: `GraveSearch` pushed
 * `?tpu=&nama=&blok=&tanggal=` into browser history through its own
 * `#[Url(history: true)]` properties, so those URLs are out in the world.
 * `RenewalStart` carries the identical four parameter names, so the bookmark
 * still means something — a 404 would be the one answer that does not.
 *
 * (`/perpanjangan/biaya`, retired by the same plan's Task 5, deliberately
 * gets no such redirect: no live UI path ever produced its `?makam=`, so
 * there is no bookmark to honour.)
 */
final class RenewalRouteRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_retired_grave_search_path_redirects_permanently_to_the_merged_screen(): void
    {
        $this->get('/perpanjangan/cari')
            ->assertRedirect('/perpanjangan')
            ->assertStatus(301);
    }

    /**
     * The load-bearing half. A plain `Route::permanentRedirect` would pass
     * this file's first test and still silently drop every parameter here —
     * it filters to PATH variables only (`RedirectController::only($route->
     * getCompiled()->getPathVariables())`) and never reads the query string.
     * Landing a bookmarked search on a bare, empty form is the failure this
     * asserts against.
     *
     * The expected order is alphabetical, not the request's: `Request::
     * getQueryString()` returns Symfony's NORMALISED form, which sorts keys.
     * All four values survive, which is the property that matters —
     * `RenewalStart` reads them by name.
     */
    public function test_the_redirect_preserves_the_bookmarked_search_parameters(): void
    {
        $this->get('/perpanjangan/cari?tpu=abc&nama=Budi&blok=A-1&tanggal=2020-01-31')
            ->assertRedirect('/perpanjangan?blok=A-1&nama=Budi&tanggal=2020-01-31&tpu=abc')
            ->assertStatus(301);
    }
}
