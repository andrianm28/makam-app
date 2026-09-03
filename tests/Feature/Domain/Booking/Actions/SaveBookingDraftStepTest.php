<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaveBookingDraftStepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array{code: string, quantity: int}>  $selectedServices
     * @return array<string, mixed>
     */
    private function discoveryPayload(
        string $cityCode = LaunchCityCode::JAKARTA,
        ?string $cemeteryId = null,
        ?int $cemeteryPackageId = null,
        string $serviceType = BookingServiceType::NEW_GRAVE,
        array $selectedServices = [
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
        ],
    ): array {
        return [
            'city_code' => $cityCode,
            'cemetery_id' => $cemeteryId,
            'cemetery_package_id' => $cemeteryPackageId,
            'service_type' => $serviceType,
            'selected_services' => $selectedServices,
        ];
    }

    // =====================================================================
    // DISCOVERY — merged location + cemetery + service type + services
    // =====================================================================

    public function test_discovery_step_accepts_a_full_valid_payload_in_one_call(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = BookingDraft::create([]);

        $saved = (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::DISCOVERY,
            $this->discoveryPayload(cemeteryId: $cemetery->id),
            'idem-discovery-1',
        );

        $this->assertSame(LaunchCityCode::JAKARTA, $saved->city_code);
        $this->assertSame($cemetery->id, $saved->cemetery_id);
        $this->assertSame(BookingServiceType::NEW_GRAVE, $saved->service_type);
        $this->assertNotEmpty($saved->selected_services);
        $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $saved->current_step);
        $this->assertContains(BookingWizardStep::DISCOVERY, $saved->completed_steps);
        $this->assertSame(2, $saved->version);
    }

    public function test_discovery_step_rejects_a_missing_city_code_with_a_field_keyed_error(): void
    {
        $draft = BookingDraft::create([]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayload(cityCode: '', cemeteryId: null), 'idem-discovery-missing-city');
            $this->fail('Expected BookingStepValidationException.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('city_code', $e->getErrors());
        }
    }

    public function test_discovery_step_accepts_an_admin_added_launch_city(): void
    {
        LaunchCity::query()->create(['code' => 'SUKABUMI', 'label' => 'Sukabumi']);

        $draft = BookingDraft::create([]);

        // No published cemetery exists in SUKABUMI, so `cemetery_id` is left
        // blank here and the save is still rejected — but on `cemetery_id`,
        // never on `city_code`. That is what proves the CITY half of
        // validation accepted the admin-added city rather than the whole
        // payload merely failing for an unrelated reason.
        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayload(cityCode: 'SUKABUMI', cemeteryId: null), 'idem-discovery-admin-city');
            $this->fail('Expected BookingStepValidationException — no cemetery_id was supplied.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayNotHasKey('city_code', $e->getErrors(), 'An admin-added launch city must be accepted.');
            $this->assertArrayHasKey('cemetery_id', $e->getErrors());
        }
    }

    public function test_discovery_step_rejects_an_unknown_city_code(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayload(cityCode: 'SURABAYA', cemeteryId: null), 'idem-discovery-unknown-city');
    }

    public function test_discovery_step_accepts_a_published_cemetery_matching_the_selected_city(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = BookingDraft::create([]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayload(cemeteryId: $cemetery->id), 'idem-discovery-cemetery-ok');

        $this->assertSame($cemetery->id, $saved->cemetery_id);
    }

    public function test_discovery_step_rejects_a_cemetery_outside_the_chosen_city_from_the_same_payload(): void
    {
        $bogorCemetery = Cemetery::query()
            ->where('city', LaunchCityCode::BOGOR)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->firstOrFail();

        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::DISCOVERY,
            $this->discoveryPayload(cityCode: LaunchCityCode::JAKARTA, cemeteryId: $bogorCemetery->id),
            'idem-discovery-2',
        );
    }

    public function test_discovery_step_rejects_a_draft_or_unpublished_cemetery(): void
    {
        $draftCemetery = Cemetery::query()->where('publication_status', CemeteryPublicationStatus::DRAFT)->first();
        $this->assertNotNull($draftCemetery, 'Fixture assumption: at least one seeded cemetery is draft.');

        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::DISCOVERY,
            $this->discoveryPayload(cityCode: $draftCemetery->city, cemeteryId: $draftCemetery->id),
            'idem-discovery-draft-cemetery',
        );
    }

    public function test_discovery_step_requires_a_package_when_the_cemetery_has_active_packages(): void
    {
        $cemeteryWithPackages = Cemetery::query()
            ->whereHas('packages', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $draft = BookingDraft::create([]);

        try {
            (new SaveBookingDraftStep)(
                $draft,
                BookingWizardStep::DISCOVERY,
                $this->discoveryPayload(cityCode: $cemeteryWithPackages->city, cemeteryId: $cemeteryWithPackages->id),
                'idem-discovery-pkg-required',
            );
            $this->fail('Expected BookingStepValidationException — this cemetery has active packages.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('cemetery_package_id', $e->getErrors());
        }
    }

    public function test_discovery_step_does_not_require_a_package_when_the_cemetery_has_none(): void
    {
        $cemeteryWithoutPackages = Cemetery::query()
            ->whereDoesntHave('packages')
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->firstOrFail();

        $draft = BookingDraft::create([]);

        $saved = (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::DISCOVERY,
            $this->discoveryPayload(cityCode: $cemeteryWithoutPackages->city, cemeteryId: $cemeteryWithoutPackages->id),
            'idem-discovery-pkg-not-required',
        );

        $this->assertSame($cemeteryWithoutPackages->id, $saved->cemetery_id);
    }

    public function test_discovery_step_rejects_an_unknown_service_type(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::DISCOVERY,
            $this->discoveryPayload(cemeteryId: $cemetery->id, serviceType: 'CREMATION'),
            'idem-discovery-svc-type-unknown',
        );
    }

    public function test_discovery_step_rejects_a_services_selection_missing_a_basic_code(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = BookingDraft::create([]);

        try {
            (new SaveBookingDraftStep)(
                $draft,
                BookingWizardStep::DISCOVERY,
                $this->discoveryPayload(cemeteryId: $cemetery->id, selectedServices: [
                    ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ]),
                'idem-discovery-svc-missing-basic',
            );
            $this->fail('Expected BookingStepValidationException — DOCUMENT_PROCESSING is a mandatory basic service.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('selected_services', $e->getErrors());
        }
    }

    public function test_discovery_step_has_no_upstream_sequencing_requirement(): void
    {
        // DISCOVERY is now the FIRST real step (like old LOCATION) — no
        // completed_steps precondition, unlike CUSTOMER_AND_DECEASED_DATA/PAYMENT.
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = BookingDraft::create([]);
        $this->assertSame([], $draft->completed_steps);

        $saved = (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::DISCOVERY,
            $this->discoveryPayload(cemeteryId: $cemetery->id),
            'idem-discovery-3',
        );

        $this->assertContains(BookingWizardStep::DISCOVERY, $saved->completed_steps);
    }

    public function test_re_saving_an_already_completed_discovery_step_is_still_allowed(): void
    {
        // Back navigation (AC11) must keep working: DISCOVERY has no
        // predecessor, so sequencing never blocks a correction here either.
        $jakartaCemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $bogorCemetery = Cemetery::query()
            ->where('city', LaunchCityCode::BOGOR)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = BookingDraft::create([
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $jakartaCemetery->id,
            'completed_steps' => [BookingWizardStep::DISCOVERY],
        ]);

        $saved = (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::DISCOVERY,
            $this->discoveryPayload(cityCode: LaunchCityCode::BOGOR, cemeteryId: $bogorCemetery->id),
            'idem-discovery-resave',
        );

        $this->assertSame(LaunchCityCode::BOGOR, $saved->city_code);
        $this->assertSame($bogorCemetery->id, $saved->cemetery_id);
    }

    // =====================================================================
    // Cross-cutting
    // =====================================================================

    public function test_an_out_of_range_step_number_is_rejected(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(\InvalidArgumentException::class);

        (new SaveBookingDraftStep)($draft, 99, [], 'idem-11');
    }

    public function test_the_read_only_confirmation_step_has_no_save_action(): void
    {
        $draft = BookingDraft::create(['completed_steps' => [1, 2, 3]]);

        $this->expectException(\InvalidArgumentException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::CONFIRMATION, [], 'idem-13');
    }

    public function test_the_read_only_confirmation_step_leaves_the_draft_untouched(): void
    {
        $draft = BookingDraft::create(['completed_steps' => [1, 2, 3]]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::CONFIRMATION, [], 'idem-14');
            $this->fail('Expected InvalidArgumentException for the read-only confirmation step.');
        } catch (\InvalidArgumentException) {
            // Expected.
        }

        $reloaded = BookingDraft::query()->findOrFail($draft->id);

        $this->assertSame(1, $reloaded->version, 'A rejected step must never bump the version.');
        $this->assertSame([1, 2, 3], $reloaded->completed_steps);
        $this->assertNotSame(BookingWizardStep::LAST_IMPLEMENTED + 1, $reloaded->current_step);
    }

    // =====================================================================
    // Step sequencing — `public-booking-wizard` AC13's "unskippable" half
    // =====================================================================

    public function test_customer_and_deceased_data_cannot_be_saved_on_a_fresh_draft_that_never_completed_discovery(): void
    {
        $draft = BookingDraft::create([]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, [], 'idem-seq-1');
            $this->fail('Expected BookingStepValidationException — DISCOVERY was never completed.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('step', $e->getErrors());
        }

        $reloaded = BookingDraft::query()->findOrFail($draft->id);

        $this->assertNull($reloaded->customer_full_name, 'A skipped step must persist nothing.');
        $this->assertSame(1, $reloaded->version);
        $this->assertSame([], $reloaded->completed_steps);
    }

    public function test_payment_cannot_be_saved_before_customer_and_deceased_data(): void
    {
        $draft = BookingDraft::create(['completed_steps' => [BookingWizardStep::DISCOVERY]]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::PAYMENT, [], 'idem-seq-3');
    }

    public function test_discovery_never_needs_a_predecessor(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = BookingDraft::create([]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, $this->discoveryPayload(cemeteryId: $cemetery->id), 'idem-seq-4');

        $this->assertSame(LaunchCityCode::JAKARTA, $saved->city_code);
    }
}
