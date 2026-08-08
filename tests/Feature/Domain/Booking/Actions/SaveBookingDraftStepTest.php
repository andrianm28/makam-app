<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaveBookingDraftStepTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // Step 1 — location
    // =====================================================================

    public function test_step_1_accepts_a_known_launch_city(): void
    {
        $draft = BookingDraft::create([]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, [
            'city_code' => LaunchCityCode::JAKARTA,
        ], 'idem-1');

        $this->assertSame('JAKARTA', $saved->city_code);
        $this->assertContains(BookingWizardStep::LOCATION, $saved->completed_steps);
        $this->assertSame(BookingWizardStep::CEMETERY, $saved->current_step);
        $this->assertSame(2, $saved->version);
    }

    public function test_step_1_rejects_a_missing_city_code_with_a_field_keyed_error(): void
    {
        $draft = BookingDraft::create([]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, [], 'idem-2');
            $this->fail('Expected BookingStepValidationException.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('city_code', $e->getErrors());
        }
    }

    public function test_step_1_rejects_an_unknown_city_code(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => 'SURABAYA'], 'idem-3');
    }

    // =====================================================================
    // Step 2 — cemetery + package
    // =====================================================================

    public function test_step_2_accepts_a_published_cemetery_matching_the_selected_city(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = BookingDraft::create(['city_code' => LaunchCityCode::JAKARTA]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, [
            'cemetery_id' => $cemetery->id,
        ], 'idem-4');

        $this->assertSame($cemetery->id, $saved->cemetery_id);
        $this->assertContains(BookingWizardStep::CEMETERY, $saved->completed_steps);
        $this->assertSame(BookingWizardStep::SERVICE_TYPE, $saved->current_step);
    }

    public function test_step_2_rejects_a_cemetery_in_a_different_city_than_step_1(): void
    {
        $bogorCemetery = Cemetery::query()->where('city', LaunchCityCode::BOGOR)->where('publication_status', CemeteryPublicationStatus::PUBLISHED)->firstOrFail();

        $draft = BookingDraft::create(['city_code' => LaunchCityCode::JAKARTA]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $bogorCemetery->id], 'idem-5');
    }

    public function test_step_2_rejects_a_draft_or_unpublished_cemetery(): void
    {
        $draftCemetery = Cemetery::query()->where('publication_status', CemeteryPublicationStatus::DRAFT)->first();
        $this->assertNotNull($draftCemetery, 'Fixture assumption: at least one seeded cemetery is draft.');

        $draft = BookingDraft::create(['city_code' => $draftCemetery->city]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $draftCemetery->id], 'idem-6');
    }

    public function test_step_2_requires_a_package_when_the_cemetery_has_active_packages(): void
    {
        $cemeteryWithPackages = Cemetery::query()
            ->whereHas('packages', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $draft = BookingDraft::create(['city_code' => $cemeteryWithPackages->city]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, [
                'cemetery_id' => $cemeteryWithPackages->id,
            ], 'idem-7');
            $this->fail('Expected BookingStepValidationException — this cemetery has active packages.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('cemetery_package_id', $e->getErrors());
        }
    }

    public function test_step_2_does_not_require_a_package_when_the_cemetery_has_none(): void
    {
        $cemeteryWithoutPackages = Cemetery::query()
            ->whereDoesntHave('packages')
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->firstOrFail();

        $draft = BookingDraft::create(['city_code' => $cemeteryWithoutPackages->city]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, [
            'cemetery_id' => $cemeteryWithoutPackages->id,
        ], 'idem-8');

        $this->assertSame($cemeteryWithoutPackages->id, $saved->cemetery_id);
    }

    // =====================================================================
    // Step 3 — service type
    // =====================================================================

    public function test_step_3_accepts_a_known_service_type(): void
    {
        $draft = BookingDraft::create(['city_code' => LaunchCityCode::JAKARTA]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, [
            'service_type' => 'NEW_GRAVE',
        ], 'idem-9');

        $this->assertSame('NEW_GRAVE', $saved->service_type);
        $this->assertSame(BookingWizardStep::SERVICES, $saved->current_step);
    }

    public function test_step_3_rejects_an_unknown_service_type(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => 'CREMATION'], 'idem-10');
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

    public function test_a_step_beyond_this_batchs_boundary_is_rejected(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(\InvalidArgumentException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::CUSTOMER_DATA, [], 'idem-12');
    }
}
