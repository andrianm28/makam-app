<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingContactChannel;
use App\Domain\Booking\BookingRelationshipCode;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UXB-02: `SaveBookingDraftStep::validateDeceasedData()` used to validate
 * `CUSTOMER_AND_DECEASED_DATA` (Step 2 of the merged wizard) without ever
 * looking at `$draft->service_type` — persisted one step earlier, by
 * DISCOVERY — so a Pre-Need booking, which may not have full deceased data
 * yet, could never pass this step. This suite is the dedicated regression
 * coverage for the fix: Pre-Need becomes fully optional on deceased data,
 * and At-Need keeps full name/relationship mandatory while its two dates
 * become optional-but-validated-if-present, matching the wizard's own
 * "Isi sebisa Anda" copy (`resources/views/livewire/public/booking/
 * wizard.blade.php`).
 *
 * `SaveBookingDraftStepSteps678Test` remains the general field-by-field
 * suite for this step (customer half, gender, document-path refusal,
 * consent, etc.) — this file only covers the service-type-conditional
 * branching that batch touched.
 */
final class SaveBookingDraftStepServiceTypeValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A draft that has completed DISCOVERY with a given `service_type`
     * already persisted — the server-side precondition for saving
     * CUSTOMER_AND_DECEASED_DATA, and the only source `validateDeceasedData()`
     * now reads `service_type` from.
     */
    private function draftReadyForCustomerAndDeceasedData(string $serviceType): BookingDraft
    {
        return BookingDraft::create([
            'completed_steps' => [BookingWizardStep::DISCOVERY],
            'service_type' => $serviceType,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customerPayload(array $overrides = []): array
    {
        return [
            ...[
                'customer_full_name' => 'Budi Santoso',
                'customer_mobile' => '081234567890',
                'customer_email' => 'budi@example.test',
                'customer_address' => 'Jl. Melati No. 12, Jakarta Selatan',
                'customer_relationship' => BookingRelationshipCode::ANAK,
                'customer_contact_channel' => BookingContactChannel::WHATSAPP,
                'privacy_notice_accepted' => true,
            ],
            ...$overrides,
        ];
    }

    // =====================================================================
    // PRE_NEED — deceased data becomes fully optional
    // =====================================================================

    /**
     * The exact bug this batch fixes: a Pre-Need draft with NO deceased
     * data at all — no name, no dates, no relationship, no gender — must
     * be able to pass this step, because the family may not yet know who
     * the eventual occupant even is.
     */
    public function test_pre_need_step_accepts_no_deceased_data_at_all(): void
    {
        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForCustomerAndDeceasedData(BookingServiceType::PRE_NEED),
            BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            $this->customerPayload(),
            'idem-preneed-empty-deceased'
        );

        $this->assertNull($saved->deceased_full_name);
        $this->assertNull($saved->deceased_date_of_birth);
        $this->assertNull($saved->deceased_date_of_death);
        $this->assertNull($saved->deceased_relationship);
        $this->assertNull($saved->deceased_gender);
        $this->assertContains(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $saved->completed_steps);
        $this->assertSame(BookingWizardStep::PAYMENT, $saved->current_step);
    }

    /**
     * Persistence must match validation: an omitted key must persist as
     * NULL, never as an empty string reaching a nullable varchar column.
     */
    public function test_pre_need_step_persists_null_not_empty_strings_for_omitted_deceased_fields(): void
    {
        $draft = $this->draftReadyForCustomerAndDeceasedData(BookingServiceType::PRE_NEED);

        (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            $this->customerPayload(),
            'idem-preneed-persist-null'
        );

        $persisted = BookingDraft::query()->findOrFail($draft->id);

        $this->assertNull($persisted->deceased_full_name);
        $this->assertNull($persisted->deceased_date_of_birth);
        $this->assertNull($persisted->deceased_date_of_death);
        $this->assertNull($persisted->deceased_relationship);
    }

    /**
     * Optional does not mean unvalidated: a Pre-Need draft that DOES supply
     * a deceased full name must still have it format-checked.
     */
    public function test_pre_need_step_still_validates_a_deceased_full_name_when_one_is_supplied(): void
    {
        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)(
            $this->draftReadyForCustomerAndDeceasedData(BookingServiceType::PRE_NEED),
            BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            $this->customerPayload(['deceased_full_name' => 'Si']),
            'idem-preneed-name-too-short'
        );
    }

    /**
     * A Pre-Need draft that supplies a full, valid deceased data set must
     * still have it persisted — "optional" is not "ignored".
     */
    public function test_pre_need_step_persists_deceased_data_when_fully_supplied(): void
    {
        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForCustomerAndDeceasedData(BookingServiceType::PRE_NEED),
            BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            $this->customerPayload([
                'deceased_full_name' => 'Siti Rahayu',
                'deceased_date_of_birth' => '1950-03-04',
                'deceased_date_of_death' => '2026-01-15',
                'deceased_relationship' => BookingRelationshipCode::ORANG_TUA,
            ]),
            'idem-preneed-full-supplied'
        );

        $this->assertSame('Siti Rahayu', $saved->deceased_full_name);
        $this->assertSame('1950-03-04', $saved->deceased_date_of_birth->toDateString());
        $this->assertSame('2026-01-15', $saved->deceased_date_of_death->toDateString());
        $this->assertSame(BookingRelationshipCode::ORANG_TUA, $saved->deceased_relationship);
    }

    // =====================================================================
    // AT_NEED (NEW_GRAVE, OVERLAPPING_GRAVE, URGENT_TODAY) — name and
    // relationship stay mandatory; dates become optional-but-validated
    // =====================================================================

    public function test_at_need_step_rejects_a_missing_deceased_full_name(): void
    {
        try {
            (new SaveBookingDraftStep)(
                $this->draftReadyForCustomerAndDeceasedData(BookingServiceType::NEW_GRAVE),
                BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
                $this->customerPayload(),
                'idem-atneed-name-missing'
            );
            $this->fail('Expected a validation exception for a missing deceased full name.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('deceased_full_name', $e->getErrors());
        }
    }

    public function test_at_need_step_rejects_a_missing_deceased_relationship(): void
    {
        try {
            (new SaveBookingDraftStep)(
                $this->draftReadyForCustomerAndDeceasedData(BookingServiceType::URGENT_TODAY),
                BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
                [...$this->customerPayload(), 'deceased_full_name' => 'Siti Rahayu'],
                'idem-atneed-rel-missing'
            );
            $this->fail('Expected a validation exception for a missing deceased relationship.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('deceased_relationship', $e->getErrors());
        }
    }

    /**
     * The core UXB-02 behavior change for At-Need: both dates are now
     * optional. A draft supplying only the mandatory name and relationship
     * must be accepted, with both date columns left NULL.
     */
    public function test_at_need_step_accepts_no_dates_when_name_and_relationship_are_present(): void
    {
        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForCustomerAndDeceasedData(BookingServiceType::NEW_GRAVE),
            BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            [
                ...$this->customerPayload(),
                'deceased_full_name' => 'Siti Rahayu',
                'deceased_relationship' => BookingRelationshipCode::ORANG_TUA,
            ],
            'idem-atneed-dates-omitted'
        );

        $this->assertSame('Siti Rahayu', $saved->deceased_full_name);
        $this->assertSame(BookingRelationshipCode::ORANG_TUA, $saved->deceased_relationship);
        $this->assertNull($saved->deceased_date_of_birth);
        $this->assertNull($saved->deceased_date_of_death);
        $this->assertContains(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $saved->completed_steps);
    }

    /**
     * Optional-but-validated-if-present: an At-Need draft that DOES supply
     * a date of birth must still have it format-checked.
     */
    public function test_at_need_step_still_rejects_an_unparseable_date_of_birth_when_one_is_supplied(): void
    {
        try {
            (new SaveBookingDraftStep)(
                $this->draftReadyForCustomerAndDeceasedData(BookingServiceType::OVERLAPPING_GRAVE),
                BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
                [
                    ...$this->customerPayload(),
                    'deceased_full_name' => 'Siti Rahayu',
                    'deceased_relationship' => BookingRelationshipCode::ORANG_TUA,
                    'deceased_date_of_birth' => 'not-a-date',
                ],
                'idem-atneed-dob-unparseable'
            );
            $this->fail('Expected a validation exception for an unparseable date of birth.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('deceased_date_of_birth', $e->getErrors());
        }
    }

    /**
     * The birth-before-death cross-check still applies when BOTH dates are
     * supplied for an At-Need draft.
     */
    public function test_at_need_step_still_rejects_a_date_of_birth_after_the_date_of_death_when_both_are_supplied(): void
    {
        try {
            (new SaveBookingDraftStep)(
                $this->draftReadyForCustomerAndDeceasedData(BookingServiceType::NEW_GRAVE),
                BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
                [
                    ...$this->customerPayload(),
                    'deceased_full_name' => 'Siti Rahayu',
                    'deceased_relationship' => BookingRelationshipCode::ORANG_TUA,
                    'deceased_date_of_birth' => '2020-05-05',
                    'deceased_date_of_death' => '2010-05-05',
                ],
                'idem-atneed-dob-after-dod'
            );
            $this->fail('Expected a validation exception for a date of birth after the date of death.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('deceased_date_of_birth', $e->getErrors());
        }
    }
}
