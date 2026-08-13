<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Directory;

use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Directory\CemeteryDetail;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `/cemeteries/{cemeterySlug}` (route name `cemeteries.show`) — Sprint 4
 * S4-T6, AC3, AC4, AC5, AC6, AC11, AC12.
 *
 * `CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]` is used as the worked
 * example throughout: it is one of the two example cemeteries that carry
 * `cemetery_packages` rows, so it exercises AC6's package/class granularity
 * rather than only the empty state.
 */
final class CemeteryDetailRouteTest extends TestCase
{
    use RefreshDatabase;

    private const string EXAMPLE_SLUG = CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_published_cemetery_detail_renders_successfully(): void
    {
        $cemetery = $this->exampleCemetery();

        $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))
            ->assertOk()
            ->assertSee($cemetery->name);
    }

    /**
     * 404 discipline — an unknown slug and an unpublished cemetery must be
     * indistinguishable from outside. A different status or message would
     * leak that the record exists.
     */
    public function test_an_unknown_slug_returns_404(): void
    {
        $this->get(route('cemeteries.show', ['cemeterySlug' => 'tidak-ada-lokasi-ini']))
            ->assertNotFound();
    }

    public function test_a_draft_cemetery_returns_404_not_a_hidden_page(): void
    {
        $draft = Cemetery::query()
            ->where('publication_status', CemeteryPublicationStatus::DRAFT)
            ->firstOrFail();

        $this->get(route('cemeteries.show', ['cemeterySlug' => $draft->slug]))
            ->assertNotFound();
    }

    /**
     * The two 404s above must be the SAME response — asserted directly
     * rather than inferred from two separate status assertions.
     */
    public function test_the_draft_and_unknown_slug_responses_are_indistinguishable(): void
    {
        $draft = Cemetery::query()
            ->where('publication_status', CemeteryPublicationStatus::DRAFT)
            ->firstOrFail();

        $unknownStatus = $this->get(route('cemeteries.show', ['cemeterySlug' => 'tidak-ada-lokasi-ini']))->status();
        $draftStatus = $this->get(route('cemeteries.show', ['cemeterySlug' => $draft->slug]))->status();

        $this->assertSame($unknownStatus, $draftStatus);
        $this->assertSame(404, $draftStatus);
    }

    /**
     * AC3 — every field the detail page owes.
     *
     * Note on `assertSee()` and escaping — this failed in CI on 8 Aug 2026
     * and the fix is not the obvious one. These assertions deliberately use
     * the DEFAULT `escape: true`, not `escape: false`.
     *
     * `button.blade.php:81` renders `href="{{ $href }}"`, so Blade escapes
     * the seeded Google Maps URL's `&` to `&amp;` in the real HTML — which
     * is correct and safe, not a view bug. `assertSee($value, $escape =
     * true)` applies `e()` to the EXPECTED value (`TestResponse.php:710`),
     * so the default escapes the needle the same way Blade escaped the
     * output and the two match. Passing `escape: false` searches for the
     * raw `&query=` in a response containing `&amp;query=`, and never
     * matches.
     *
     * The trap: `escape: false` looks harmless on a value with no
     * HTML-special characters (a photo path, a bare route URL) and passes
     * by coincidence. Every assertion in these files targets auto-escaped
     * Blade output, so none of them wants it — do not reintroduce it here
     * on the theory that an attribute value is "already raw".
     */
    public function test_the_detail_page_shows_every_ac3_field(): void
    {
        $cemetery = $this->exampleCemetery();

        $response = $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]));

        $response->assertSee($cemetery->type);
        $response->assertSee($cemetery->name);
        $response->assertSee($cemetery->address);
        $response->assertSee((string) $cemetery->primary_photo_path);
        $response->assertSee((string) $cemetery->google_maps_url);
        $response->assertSee((string) $cemetery->price_source);
        // BOTH ends of AC3's "attributed price range".
        // `CemeteryPresenter::priceRange()` renders "{symbol} {min} - {symbol}
        // {max}"; asserting only the minimum let a regression that dropped or
        // corrupted the upper half pass on both routes.
        $response->assertSee(number_format((float) $cemetery->price_min, 0, ',', '.'));
        $response->assertSee(number_format((float) $cemetery->price_max, 0, ',', '.'));

        foreach ((array) $cemetery->facilities as $facility) {
            $response->assertSee((string) $facility);
        }
    }

    /**
     * AC11 — THE test for this criterion. The map link is removed at the
     * data level (both the operator URL and the coordinates it would
     * otherwise be derived from), so `Cemetery::googleMapsUrl()` returns
     * null, and the textual address must still render.
     */
    public function test_the_address_still_renders_when_no_map_link_can_be_produced(): void
    {
        $cemetery = $this->exampleCemetery();

        $cemetery->update([
            'google_maps_url' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->assertNull($cemetery->fresh()?->googleMapsUrl());

        $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))
            ->assertOk()
            ->assertSee($cemetery->address)
            ->assertDontSee('Buka di Google Maps');
    }

    public function test_the_map_link_renders_when_the_cemetery_has_one(): void
    {
        $cemetery = $this->exampleCemetery();

        $this->assertNotNull($cemetery->googleMapsUrl());

        $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))
            ->assertSee('Buka di Google Maps')
            ->assertSee((string) $cemetery->googleMapsUrl());
    }

    /**
     * AC12 + the negative criterion, asserted on the rendered HTML.
     */
    public function test_restricted_capability_modes_never_reach_the_rendered_detail_page(): void
    {
        $cemetery = $this->exampleCemetery();

        $body = (string) $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))->getContent();

        $this->assertStringNotContainsString('registry_mode', $body);
        $this->assertStringNotContainsString('certificate_mode', $body);
    }

    /**
     * The same assertion with the restricted modes deliberately set to
     * their most sensitive values — so the test cannot pass merely because
     * the seeded value happens to be the innocuous `NONE`.
     */
    public function test_restricted_modes_do_not_leak_even_when_set_to_their_strongest_values(): void
    {
        $cemetery = $this->exampleCemetery();

        CemeteryCapabilityProfile::query()
            ->where('cemetery_id', $cemetery->id)
            ->current()
            ->update([
                'registry_mode' => 'AUTHORITATIVE',
                'certificate_mode' => 'PLATFORM_MANAGED',
            ]);

        $body = (string) $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))->getContent();

        $this->assertStringNotContainsString('AUTHORITATIVE', $body);
        $this->assertStringNotContainsString('PLATFORM_MANAGED', $body);
        $this->assertStringNotContainsString('registry_mode', $body);
        $this->assertStringNotContainsString('certificate_mode', $body);
    }

    /**
     * AC5/AC6 — the seeded packages include one marked `AVAILABLE`, and
     * under the cemetery's indicative mode it must NOT render as success.
     */
    public function test_an_available_package_does_not_render_as_success_while_the_cemetery_is_indicative(): void
    {
        $cemetery = $this->exampleCemetery();

        $body = (string) $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))->getContent();

        $this->assertStringContainsString('Perlu konfirmasi', $body);
        $this->assertStringNotContainsString('--mk-intent-success', $body);
    }

    public function test_the_package_and_class_rows_render_for_a_cemetery_that_has_them(): void
    {
        $cemetery = $this->exampleCemetery();

        $packages = $cemetery->packages()->active()->get();
        $this->assertGreaterThan(0, $packages->count());

        $response = $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]));

        foreach ($packages as $package) {
            $response->assertSee($package->name);

            if ($package->class_label !== null) {
                $response->assertSee((string) $package->class_label);
            }
        }
    }

    /**
     * §6.2 — a cemetery with no configured packages is an ORDINARY state
     * (the seed populates them on two of ten), and must read as "not
     * recorded yet", never as a failure and never as a bare "no data".
     */
    public function test_a_cemetery_without_packages_shows_an_explained_empty_state(): void
    {
        $cemetery = Cemetery::query()
            ->published()
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))
            ->assertOk()
            ->assertSee('Rincian paket dan kelas belum tersedia untuk lokasi ini.')
            ->assertSee('Tanyakan ketersediaan');
    }

    /**
     * AC4 — a cemetery with NO capability profile at all must still render,
     * on safe defaults, labelled `Perlu konfirmasi`. Not a blank page and
     * not a 500.
     */
    public function test_a_cemetery_with_no_capability_profile_renders_on_safe_defaults(): void
    {
        $cemetery = $this->exampleCemetery();

        CemeteryCapabilityProfile::query()->where('cemetery_id', $cemetery->id)->delete();

        $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))
            ->assertOk()
            ->assertSee($cemetery->name)
            ->assertSee('Perlu konfirmasi');
    }

    /**
     * §6.5 — the package read failing must not take the page down, and must
     * be reported as a read failure rather than as "this cemetery has no
     * packages". Conflating the two would tell a family a package does not
     * exist when we simply could not read it.
     */
    public function test_a_package_read_failure_is_reported_separately_from_an_empty_package_list(): void
    {
        $cemetery = $this->exampleCemetery();

        // `booking_drafts` FK-references `cemetery_packages`, so PostgreSQL
        // blocks the drop (2BP01). The table is empty here — no draft was
        // created — so dropping it first takes the package table away.
        //
        // Order-orchestration tables FK-referencing `booking_drafts` /
        // `orders` / `quotes` (Task 3/4/5, 12 Aug 2026) are dropped first so
        // PostgreSQL's `DROP TABLE` of `booking_drafts` is not blocked by the
        // incoming FKs (2BP01) — the same tripwire this comment documents.
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('order_documents');
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('order_parties');
        Schema::dropIfExists('deceased_profiles');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('funeral_cases');
        Schema::dropIfExists('pre_need_interests');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('booking_drafts');
        Schema::drop('cemetery_packages');

        Livewire::test(CemeteryDetail::class, ['cemeterySlug' => $cemetery->slug])
            ->assertSet('packagesUnavailable', true)
            ->assertSee('Daftar paket sedang tidak dapat dimuat')
            ->assertDontSee('Rincian paket dan kelas belum tersedia untuk lokasi ini.');
    }

    /**
     * §6.4 / privacy-limited — with `map_mode` on its `LOCATION_ONLY` safe
     * default, the page must SAY that plot-level detail is not public,
     * rather than silently rendering nothing there. design-system.md §6.2:
     * privacy-limited is a distinct state from not found.
     */
    public function test_the_page_states_that_plot_level_detail_is_not_public(): void
    {
        $cemetery = $this->exampleCemetery();

        $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))
            ->assertSee('Peta blok dan petak')
            ->assertSee('tidak ditampilkan secara publik');
    }

    public function test_the_customer_service_escape_hatch_is_present(): void
    {
        $cemetery = $this->exampleCemetery();

        $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))
            ->assertSee('Hubungi Customer Service')
            ->assertSee(route('bantuan.index'));
    }

    public function test_the_detail_page_links_back_to_the_directory(): void
    {
        $cemetery = $this->exampleCemetery();

        $this->get(route('cemeteries.show', ['cemeterySlug' => $cemetery->slug]))
            ->assertSee(route('cemeteries.index'))
            ->assertSee('Kembali ke direktori');
    }

    private function exampleCemetery(): Cemetery
    {
        return Cemetery::query()->published()->where('slug', self::EXAMPLE_SLUG)->firstOrFail();
    }
}
