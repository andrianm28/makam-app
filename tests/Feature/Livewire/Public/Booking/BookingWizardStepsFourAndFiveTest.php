<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardStepsFourAndFiveTest extends TestCase
{
    use RefreshDatabase;

    private function draftAtStep4(): string
    {
        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-a');

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $cemetery->id], 'idem-b');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => 'NEW_GRAVE'], 'idem-c');

        return $draft->id;
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
        $draftId = $this->draftAtStep4();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        // Asserted as the submitted VALUE, not as visible text: the code is
        // what reaches `saveStep4()`, while the visible label is now the
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
        $draftId = $this->draftAtStep4();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        foreach (ServiceCatalogQuery::allActive() as $definition) {
            $component->assertSee($definition->name);
        }

        // A spot-check against the seed migration itself, so a regression
        // that made `name` fall back to `code` cannot pass the loop above.
        $component->assertSee('Pengurusan Dokumen');
        $component->assertSee('Penggalian Makam');
        $component->assertSee('Tenda & Kursi');
    }

    public function test_saving_step_4_with_both_basics_advances_to_step_5(): void
    {
        $draftId = $this->draftAtStep4();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSet('currentStep', BookingWizardStep::SUMMARY);
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

        $draftId = $this->draftAtStep4();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSee('Rp 350.000')
            ->assertSee('Rp 750.000')
            ->assertSee('Rp 1.100.000')
            ->assertDontSee('Harga belum tersedia');
    }

    public function test_the_autosave_indicator_shows_saved_after_a_successful_step_save(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->assertSet('autosaveState', 'saved');
    }

    public function test_the_autosave_indicator_shows_failed_after_a_rejected_step(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', '')
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

        $draftId = $this->draftAtStep4();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
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
        $draftId = $this->draftAtStep4();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

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

        $draftId = $this->draftAtStep4();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
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

        $draftId = $this->draftAtStep4();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSee('Harga belum tersedia');
    }
}
