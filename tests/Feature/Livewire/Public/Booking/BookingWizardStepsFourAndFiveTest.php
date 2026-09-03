<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\ServiceCatalog\Actions\RecordServiceDefinitionPriceVersion;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCatalogQuery;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardStepsFourAndFiveTest extends TestCase
{
    use RefreshDatabase;

    private function jakartaCemeteryWithoutPackages(): Cemetery
    {
        return Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();
    }

    /**
     * @return list<array{code: string, quantity: int}>
     */
    private function basicServicesPayload(): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'quantity' => 1],
            ServiceCode::BASIC_CODES,
        );
    }

    /**
     * DISCOVERY's service-selection section (the old Step 4) reveals once
     * city, cemetery and service type are all chosen — client-side
     * component state, not persisted until the single `saveStep1()` save.
     * No `BookingDraft` exists for this fixture at all.
     */
    private function wizardWithServiceTypeSelected(): Testable
    {
        $cemetery = $this->jakartaCemeteryWithoutPackages();

        return Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $cemetery->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE);
    }

    /**
     * Makes the summary total assertion independent of whatever dummy
     * amounts the dev-data seed currently records: removes the seeded
     * `price_versions` row(s) for `$code` (a builder-level mass delete, so
     * the append-only guard `PriceVersion::booted()` never fires — the same
     * isolation mechanism `PriceVersioningTest` uses) and records the
     * test's OWN price through the real Action. This test owns its prices,
     * not the seed.
     */
    private function setTestOwnedPrice(string $code, string $amount): void
    {
        $service = ServiceDefinition::findByCode($code);

        PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->where('priceable_id', $service->id)
            ->delete();

        (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: $amount,
            actorReference: 'booking-wizard-step-5-test',
            reason: 'Test-owned price for the Step 5 summary total assertion, independent of the dummy seed.',
            source: 'Test fixture (synthetic — not real catalogue pricing).',
        );
    }

    public function test_step_4_offers_every_basic_and_additional_service(): void
    {
        $component = $this->wizardWithServiceTypeSelected();

        // Asserted as the submitted VALUE, not as visible text: the code is
        // what reaches `saveStep1()`, while the visible label is now the
        // catalogue's own name (see the next test).
        foreach (ServiceCode::KNOWN_CODES as $code) {
            $component->assertSeeHtml('value="'.$code.'"');
        }
    }

    /**
     * Step 4 used to render bare enum codes ("DOCUMENT_PROCESSING",
     * "TENT_AND_CHAIRS") while Step 5's summary showed the real seeded
     * Indonesian names for the same services, on the same journey, two
     * clicks apart. Both now read from `ServiceDefinition::name`.
     */
    public function test_step_4_labels_each_service_with_its_real_catalogue_name(): void
    {
        $component = $this->wizardWithServiceTypeSelected();

        foreach (ServiceCatalogQuery::allActive() as $definition) {
            $component->assertSee($definition->name);
        }

        // A spot-check against the seed migration itself, so a regression
        // that made `name` fall back to `code` cannot pass the loop above.
        $component->assertSee('Pengurusan Dokumen');
        $component->assertSee('Penggalian Makam');
        $component->assertSee('Tenda & Kursi');
    }

    /**
     * Fix round 1 for the wizard-screen-consolidation Task 2 regression:
     * `BookingWizard::render()` now runs `ServiceCatalogQuery::allActive()`
     * on every Screen 1 render (steps 1-4), not only on the exact SERVICES
     * step — so, mirroring `BookingWizardRouteTest::
     * test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing`'s own
     * reasoning one query over, a failed catalogue read must degrade
     * honestly rather than 500. Drops only the FOREIGN KEY CONSTRAINTS
     * referencing `service_definitions` (not the dependent tables
     * themselves) before dropping the table itself — cheaper than the route
     * test's full reverse-dependency table teardown, and sufficient here
     * since `service_definitions` has exactly three direct referencers.
     *
     * Deliberately a bare, cityless mount (no draft, `$this->city === ''`,
     * `currentStep === LOCATION`) rather than `draftAtStep4()`'s cemetery-
     * bound draft: with a real cemetery selected, Screen 1's Step 2 markup
     * calls `pickerAppliesTo()`/`CemeteryPublicQuery::findPublishedById()`
     * once per listed cemetery card DURING Blade rendering — a pre-existing
     * call site (unrelated to this task) with no failure guard of its own.
     * Under PostgreSQL a failed statement poisons the whole ambient
     * transaction until rollback, so once the (now correctly caught)
     * catalogue-read failure poisons it, those LATER, unguarded per-card
     * calls throw too — a real, separately-scoped gap, not something this
     * fix round's render()-guard change caused or is responsible for
     * covering. Keeping the city empty here means no cemetery card ever
     * renders, isolating this test to exactly the interaction this fix
     * covers: the catalogue read itself degrading honestly.
     *
     * Because the city is empty, Step 4's own section (and the honest
     * "Daftar layanan sedang tidak dapat dimuat" alert inside it) is not
     * on screen in THIS scenario — Step 4 isn't reachable without a city.
     * What this test proves is that the query this task's `render()` guard
     * change made run more eagerly (on every Screen 1 render, not only the
     * exact SERVICES step) no longer raises uncaught when it fails; the
     * alert's own wiring is covered separately by
     * `BookingWizardRouteTest::
     * test_a_failed_cemetery_and_services_catalog_read_degrades_honestly_instead_of_500ing`,
     * which reaches a real Step 4 view.
     */
    public function test_a_failed_services_catalog_read_degrades_honestly_instead_of_500ing(): void
    {
        Schema::table('service_package_items', function (Blueprint $table): void {
            $table->dropForeign(['service_definition_id']);
        });
        Schema::table('substitution_policies', function (Blueprint $table): void {
            $table->dropForeign(['substitute_service_definition_id']);
        });
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->dropForeign(['service_definition_id']);
        });
        Schema::dropIfExists('service_definitions');

        $component = Livewire::test(BookingWizard::class)
            ->assertOk()
            ->assertSee('Langkah 1');

        $this->assertSame(1, $component->instance()->currentScreen());
    }

    public function test_completing_discovery_with_both_basics_advances_to_customer_and_deceased_data(): void
    {
        $this->wizardWithServiceTypeSelected()
            ->call('continueFromDiscovery')
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA);
    }

    /**
     * R3 (pre-flight ruling, human-confirmed 08 Aug 2026): the canonical
     * seed migration (`2026_07_26_220000_seed_service_definition_dummy_
     * operational_data.php`) seeds a real `price_versions` row for every
     * one of the 12 catalogue services, so Step 5 with only the two
     * mandatory basics selected always has both prices available in this
     * environment; the honest "Harga belum tersedia" path is never
     * reachable from this fixture and is NOT what this test should assert.
     * That degraded state is already covered by Task 4's own dedicated
     * fixture (`BookingDraftQueryTest::
     * test_summary_marks_a_missing_price_honestly_instead_of_fabricating_a_total`,
     * which supersedes the seeded price before asserting).
     *
     * Since the de-hardcoding retrofit, the seeded amounts are PROCEDURAL
     * dummy data (`ServiceOperationalExampleData::dummyPrices()`), so this
     * test no longer relies on them: it sets its OWN prices for
     * `DOCUMENT_PROCESSING` and `GRAVE_DIGGING` first (see
     * `setTestOwnedPrice()`), then proves the happy path renders the
     * correct computed total from exactly those amounts.
     */
    public function test_step_5_shows_the_real_computed_total_from_test_owned_prices(): void
    {
        $this->setTestOwnedPrice(ServiceCode::DOCUMENT_PROCESSING, '350000.00');
        $this->setTestOwnedPrice(ServiceCode::GRAVE_DIGGING, '750000.00');

        // `saveStep1()`/`continueFromDiscovery()` redirect to the resumable
        // draft URL on success, so the assertions below need a FRESH
        // component instance resumed by draft id — not a further assertion
        // chained onto the same (now-redirecting) instance.
        $draftId = $this->wizardWithServiceTypeSelected()
            ->call('continueFromDiscovery')
            ->assertHasNoErrors()
            ->get('draftId');

        $this->assertIsString($draftId);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSee('Rp 350.000')
            ->assertSee('Rp 750.000')
            ->assertSee('Rp 1.100.000')
            ->assertDontSee('Harga belum tersedia');
    }

    public function test_the_autosave_indicator_shows_saved_after_a_successful_step_save(): void
    {
        $cemetery = $this->jakartaCemeteryWithoutPackages();

        Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA, $cemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServicesPayload())
            ->assertSet('autosaveState', 'saved');
    }

    public function test_the_autosave_indicator_shows_failed_after_a_rejected_step(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', '', null, null, null, [])
            ->assertSet('autosaveState', 'failed');
    }

    /**
     * A UI/UX audit (25 Aug 2026) found Step 4's rows showed only a
     * checkbox and the service name — design-system.md §3.3's normative
     * Service/add-on row spec (PUB-013) also requires price, fulfillment
     * owner, and availability, even though the price data was already
     * sitting on the same `ServiceDefinition` rows Step 5's summary
     * correctly reads. This test proves the row now shows the price, using
     * a test-owned price (same isolation `setTestOwnedPrice()` gives the
     * Step 5 total test above) so it does not depend on the dummy seed's
     * own amounts.
     */
    public function test_step_4_shows_the_price_for_each_service_row(): void
    {
        $this->setTestOwnedPrice(ServiceCode::DOCUMENT_PROCESSING, '350000.00');

        $this->wizardWithServiceTypeSelected()
            ->assertSee('Rp 350.000');
    }

    /**
     * `ServiceOperationalExampleData::operationalDefaults()` (see
     * `2026_07_26_220000_seed_service_definition_dummy_operational_data.php`'s
     * own doc block) records real, seeded `fulfillment_owner` values for all
     * 12 catalogue services — DOCUMENT_PROCESSING is `platform`,
     * GRAVE_DIGGING is `cemetery_operator`, every additional service
     * (AMBULANCE included) is `vendor`. This is real fixture data, not
     * invented for this test.
     */
    public function test_step_4_shows_the_fulfillment_owner_for_each_service_row(): void
    {
        $component = $this->wizardWithServiceTypeSelected();

        $component->assertSee(FulfillmentOwner::label(FulfillmentOwner::PLATFORM));
        $component->assertSee(FulfillmentOwner::label(FulfillmentOwner::CEMETERY_OPERATOR));
        $component->assertSee(FulfillmentOwner::label(FulfillmentOwner::VENDOR));
    }

    /**
     * `ServiceDefinition::description` seeds `null` for every one of the 12
     * catalogue rows (`2026_07_26_180700_...`'s own doc block: "no
     * description copy beyond the code/label pair"), so this test sets one
     * directly to prove the row actually renders it when present, rather
     * than asserting against a value the seed never populates.
     */
    public function test_step_4_shows_the_description_when_the_catalogue_has_one(): void
    {
        ServiceDefinition::findByCode(ServiceCode::AMBULANCE)
            ->update(['description' => 'Layanan ambulans untuk pemindahan jenazah.']);

        $this->wizardWithServiceTypeSelected()
            ->assertSee('Layanan ambulans untuk pemindahan jenazah.');
    }

    /**
     * design-system.md §3.3: a service row's "availability" is honest, not
     * fabricated — a service whose catalogue price has not been recorded
     * yet shows the SAME "Harga belum tersedia" state Step 5's summary
     * already renders for a missing price (`BookingDraftQuery::summary()`),
     * not an invented in-stock/out-of-stock signal.
     */
    public function test_step_4_shows_an_honest_unavailable_price_badge_when_no_price_version_exists(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::AMBULANCE);

        PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->where('priceable_id', $service->id)
            ->delete();

        $this->wizardWithServiceTypeSelected()
            ->assertSee('Harga belum tersedia');
    }
}
