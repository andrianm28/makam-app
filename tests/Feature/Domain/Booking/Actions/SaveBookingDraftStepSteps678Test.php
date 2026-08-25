<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingContactChannel;
use App\Domain\Booking\BookingGender;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingRelationshipCode;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-side validation for the three steps this batch completes —
 * Step 6 (Data Pemesan), Step 7 (Data Almarhum and Documents) and Step 8
 * (Pembayaran) — plus the read-only/boundary guards that now sit around
 * them. `SaveBookingDraftStepTest` covers Steps 1-4 and sequencing for
 * them; this file is the same contract one batch later.
 *
 * Three of the sections here are security assertions, not field-validation
 * bookkeeping, and are the reason this file exists as a separate suite:
 *
 *   1. Privacy consent is a BOOLEAN act by the user and the timestamp is
 *      stamped from the SERVER clock. A caller can neither assert consent
 *      with truthy junk (`'true'`, `1`, `'1'`) nor backdate the record by
 *      sending its own `privacy_notice_accepted_at`.
 *   2. Document paths are REFUSED outright, never stored unchecked — an
 *      accepted free-text path would let a caller point a draft at another
 *      customer's quarantined identity document.
 *   3. `G-PAY-01` is enforced HERE, server-side, against the authoritative
 *      gate state. A closed gate must reject `ONLINE` even though the
 *      Blade view would not have rendered the control that produces it.
 *
 * Every fixture seeds `completed_steps` with all the WRITABLE steps below
 * the one under test, because `validateStepSequencing()` requires exactly
 * that for any step >= 6: step 6 needs 1-4, step 7 needs 1-4 and 6, step 8
 * needs 1-4, 6 and 7. Step 5 (SUMMARY) is read-only and never appears in
 * `completed_steps`, so it is never required. Dedicated sequencing coverage
 * lives in the last section.
 */
final class SaveBookingDraftStepSteps678Test extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // Fixtures and helpers
    // =====================================================================

    /**
     * A draft that has legitimately completed steps 1-4, which is the
     * server-side precondition for saving step 6.
     */
    private function draftReadyForStepSix(): BookingDraft
    {
        return BookingDraft::create([
            'completed_steps' => [
                BookingWizardStep::LOCATION,
                BookingWizardStep::CEMETERY,
                BookingWizardStep::SERVICE_TYPE,
                BookingWizardStep::SERVICES,
            ],
        ]);
    }

    /**
     * Step 7 additionally requires step 6 — a caller must not reach the
     * deceased/document step without having supplied the customer data.
     */
    private function draftReadyForStepSeven(): BookingDraft
    {
        return BookingDraft::create([
            'completed_steps' => [
                BookingWizardStep::LOCATION,
                BookingWizardStep::CEMETERY,
                BookingWizardStep::SERVICE_TYPE,
                BookingWizardStep::SERVICES,
                BookingWizardStep::CUSTOMER_DATA,
            ],
        ]);
    }

    /**
     * Step 8 requires every writable step below it — 1-4, 6 and 7. Jumping
     * straight to payment would otherwise land the draft on Confirmation
     * with an em dash for every customer and deceased field.
     */
    private function draftReadyForStepEight(): BookingDraft
    {
        return BookingDraft::create([
            'completed_steps' => [
                BookingWizardStep::LOCATION,
                BookingWizardStep::CEMETERY,
                BookingWizardStep::SERVICE_TYPE,
                BookingWizardStep::SERVICES,
                BookingWizardStep::CUSTOMER_DATA,
                BookingWizardStep::DECEASED_DATA,
            ],
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

    /**
     * @return array<string, mixed>
     */
    private function customerPayloadWithout(string $key): array
    {
        $payload = $this->customerPayload();
        unset($payload[$key]);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function deceasedPayload(array $overrides = []): array
    {
        return [
            ...[
                'deceased_full_name' => 'Siti Rahayu',
                'deceased_date_of_birth' => '1950-03-04',
                'deceased_date_of_death' => '2026-01-15',
                'deceased_relationship' => BookingRelationshipCode::ORANG_TUA,
                'deceased_gender' => BookingGender::PEREMPUAN,
            ],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deceasedPayloadWithout(string $key): array
    {
        $payload = $this->deceasedPayload();
        unset($payload[$key]);

        return $payload;
    }

    /**
     * Drives `G-PAY-01` from an in-memory registry source, the same
     * mechanism `PaymentGuardFailClosedTest` and `ModeResolverTest` use.
     * The Action resolves `ModeResolver` out of the container per
     * invocation, so a container instance binding is what the Action
     * actually reads — never a value the test hands it directly.
     */
    private function bindPaymentGate(bool $open): void
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot([
                    'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: $this->open),
                ]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));
    }

    /**
     * Asserts the save is refused AND that the refusal names `$expectedKey`,
     * so a test can never pass merely because some other field happened to
     * be invalid.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private function assertRejectedWithKey(string $expectedKey, BookingDraft $draft, int $step, array $payload, string $idempotencyKey): array
    {
        try {
            (new SaveBookingDraftStep)($draft, $step, $payload, $idempotencyKey);
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey(
                $expectedKey,
                $e->getErrors(),
                "Expected a validation error keyed [{$expectedKey}], got: ".implode(', ', array_keys($e->getErrors())).'.'
            );

            return $e->getErrors();
        }

        $this->fail("Expected BookingStepValidationException keyed [{$expectedKey}], but the save succeeded.");
    }

    // =====================================================================
    // Step 6 — customer data, happy path
    // =====================================================================

    public function test_step_6_accepts_a_fully_valid_customer_payload(): void
    {
        $draft = $this->draftReadyForStepSix();

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::CUSTOMER_DATA, $this->customerPayload(), 'idem-s6-happy');

        $this->assertSame('Budi Santoso', $saved->customer_full_name);
        $this->assertSame('081234567890', $saved->customer_mobile);
        $this->assertSame('budi@example.test', $saved->customer_email);
        $this->assertSame('Jl. Melati No. 12, Jakarta Selatan', $saved->customer_address);
        $this->assertSame(BookingRelationshipCode::ANAK, $saved->customer_relationship);
        $this->assertSame(BookingContactChannel::WHATSAPP, $saved->customer_contact_channel);
        $this->assertContains(BookingWizardStep::CUSTOMER_DATA, $saved->completed_steps);
        $this->assertSame(BookingWizardStep::DECEASED_DATA, $saved->current_step);
    }

    // =====================================================================
    // Step 6 — customer_full_name
    // =====================================================================

    public function test_step_6_rejects_a_missing_full_name(): void
    {
        $this->assertRejectedWithKey(
            'customer_full_name',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayloadWithout('customer_full_name'),
            'idem-s6-name-missing'
        );
    }

    public function test_step_6_rejects_a_blank_full_name(): void
    {
        $this->assertRejectedWithKey(
            'customer_full_name',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_full_name' => '   ']),
            'idem-s6-name-blank'
        );
    }

    public function test_step_6_rejects_a_full_name_under_three_characters(): void
    {
        $this->assertRejectedWithKey(
            'customer_full_name',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_full_name' => 'Bu']),
            'idem-s6-name-short'
        );
    }

    public function test_step_6_rejects_a_full_name_over_191_characters(): void
    {
        $this->assertRejectedWithKey(
            'customer_full_name',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_full_name' => str_repeat('a', 192)]),
            'idem-s6-name-long'
        );
    }

    public function test_step_6_accepts_a_full_name_at_the_191_character_boundary(): void
    {
        $name = str_repeat('a', 191);

        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_full_name' => $name]),
            'idem-s6-name-boundary'
        );

        $this->assertSame($name, $saved->customer_full_name);
    }

    // =====================================================================
    // Step 6 — customer_mobile (Indonesian format)
    // =====================================================================

    public function test_step_6_rejects_a_missing_mobile(): void
    {
        $this->assertRejectedWithKey(
            'customer_mobile',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayloadWithout('customer_mobile'),
            'idem-s6-mobile-missing'
        );
    }

    public function test_step_6_rejects_a_blank_mobile(): void
    {
        $this->assertRejectedWithKey(
            'customer_mobile',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_mobile' => '   ']),
            'idem-s6-mobile-blank'
        );
    }

    public function test_step_6_accepts_the_three_indonesian_mobile_prefixes(): void
    {
        $accepted = [
            'local zero prefix' => '081234567890',
            'international plus prefix' => '+628123456789',
            'international bare prefix' => '628123456789',
        ];

        foreach ($accepted as $label => $mobile) {
            $saved = (new SaveBookingDraftStep)(
                $this->draftReadyForStepSix(),
                BookingWizardStep::CUSTOMER_DATA,
                $this->customerPayload(['customer_mobile' => $mobile]),
                'idem-s6-mobile-ok-'.md5($mobile)
            );

            $this->assertSame($mobile, $saved->customer_mobile, "Expected [{$mobile}] ({$label}) to be accepted.");
        }
    }

    public function test_step_6_rejects_mobiles_outside_the_indonesian_pattern(): void
    {
        $rejected = [
            'too short' => '0812345',
            'too long' => '081234567890123456',
            'contains letters' => '08123abc456',
            'foreign format' => '+14155552671',
            'no recognised prefix' => '12345678901',
        ];

        foreach ($rejected as $label => $mobile) {
            $errors = $this->assertRejectedWithKey(
                'customer_mobile',
                $this->draftReadyForStepSix(),
                BookingWizardStep::CUSTOMER_DATA,
                $this->customerPayload(['customer_mobile' => $mobile]),
                'idem-s6-mobile-bad-'.md5($mobile)
            );

            $this->assertArrayHasKey('customer_mobile', $errors, "Expected [{$mobile}] ({$label}) to be rejected.");
        }
    }

    // =====================================================================
    // Step 6 — customer_email
    // =====================================================================

    public function test_step_6_rejects_a_missing_email(): void
    {
        $this->assertRejectedWithKey(
            'customer_email',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayloadWithout('customer_email'),
            'idem-s6-email-missing'
        );
    }

    public function test_step_6_rejects_a_malformed_email(): void
    {
        foreach (['not-an-email', 'budi@', '@example.test', 'budi example.test'] as $email) {
            $this->assertRejectedWithKey(
                'customer_email',
                $this->draftReadyForStepSix(),
                BookingWizardStep::CUSTOMER_DATA,
                $this->customerPayload(['customer_email' => $email]),
                'idem-s6-email-bad-'.md5($email)
            );
        }
    }

    /**
     * `customer_email` is varchar(191). A syntactically valid but longer
     * address must come back as a field-keyed message, not as a driver
     * error raised from a public form.
     */
    public function test_step_6_rejects_a_well_formed_email_over_191_characters(): void
    {
        $email = str_repeat('e', 180).'@example.test';
        $this->assertGreaterThan(191, mb_strlen($email));

        $this->assertRejectedWithKey(
            'customer_email',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_email' => $email]),
            'idem-s6-email-long'
        );
    }

    public function test_step_6_accepts_a_well_formed_email(): void
    {
        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_email' => 'siti.rahayu+booking@example.test']),
            'idem-s6-email-ok'
        );

        $this->assertSame('siti.rahayu+booking@example.test', $saved->customer_email);
    }

    // =====================================================================
    // Step 6 — customer_address
    // =====================================================================

    public function test_step_6_rejects_a_missing_address(): void
    {
        $this->assertRejectedWithKey(
            'customer_address',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayloadWithout('customer_address'),
            'idem-s6-address-missing'
        );
    }

    public function test_step_6_rejects_an_address_under_ten_characters(): void
    {
        $this->assertRejectedWithKey(
            'customer_address',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_address' => 'Jl. Kecil']),
            'idem-s6-address-short'
        );
    }

    public function test_step_6_accepts_an_address_at_the_ten_character_boundary(): void
    {
        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_address' => 'Jl. Besar1']),
            'idem-s6-address-boundary'
        );

        $this->assertSame('Jl. Besar1', $saved->customer_address);
    }

    // =====================================================================
    // Step 6 — customer_relationship
    // =====================================================================

    public function test_step_6_rejects_a_missing_relationship(): void
    {
        $this->assertRejectedWithKey(
            'customer_relationship',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayloadWithout('customer_relationship'),
            'idem-s6-rel-missing'
        );
    }

    public function test_step_6_rejects_a_relationship_outside_the_closed_list(): void
    {
        $this->assertRejectedWithKey(
            'customer_relationship',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_relationship' => 'TETANGGA']),
            'idem-s6-rel-unknown'
        );
    }

    public function test_step_6_accepts_every_known_relationship_code(): void
    {
        foreach (BookingRelationshipCode::KNOWN_CODES as $code) {
            $saved = (new SaveBookingDraftStep)(
                $this->draftReadyForStepSix(),
                BookingWizardStep::CUSTOMER_DATA,
                $this->customerPayload(['customer_relationship' => $code]),
                'idem-s6-rel-ok-'.$code
            );

            $this->assertSame($code, $saved->customer_relationship);
        }
    }

    // =====================================================================
    // Step 6 — customer_contact_channel
    // =====================================================================

    public function test_step_6_rejects_a_missing_contact_channel(): void
    {
        $this->assertRejectedWithKey(
            'customer_contact_channel',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayloadWithout('customer_contact_channel'),
            'idem-s6-channel-missing'
        );
    }

    public function test_step_6_rejects_a_contact_channel_outside_the_closed_list(): void
    {
        $this->assertRejectedWithKey(
            'customer_contact_channel',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['customer_contact_channel' => 'SMS']),
            'idem-s6-channel-unknown'
        );
    }

    public function test_step_6_accepts_every_known_contact_channel(): void
    {
        foreach (BookingContactChannel::KNOWN_CODES as $code) {
            $saved = (new SaveBookingDraftStep)(
                $this->draftReadyForStepSix(),
                BookingWizardStep::CUSTOMER_DATA,
                $this->customerPayload(['customer_contact_channel' => $code]),
                'idem-s6-channel-ok-'.$code
            );

            $this->assertSame($code, $saved->customer_contact_channel);
        }
    }

    // =====================================================================
    // Step 6 — privacy consent (security-critical)
    //
    // `privacy_notice_accepted` is the caller's BOOLEAN assertion that the
    // box was ticked. `privacy_notice_accepted_at` is the server's record
    // that it happened. The two are deliberately different names because
    // the caller may supply the first and must never supply the second.
    // =====================================================================

    public function test_step_6_rejects_a_payload_with_no_privacy_consent_key(): void
    {
        $this->assertRejectedWithKey(
            'privacy_notice_accepted',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayloadWithout('privacy_notice_accepted'),
            'idem-s6-consent-missing'
        );
    }

    public function test_step_6_rejects_privacy_consent_of_false(): void
    {
        $this->assertRejectedWithKey(
            'privacy_notice_accepted',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(['privacy_notice_accepted' => false]),
            'idem-s6-consent-false'
        );
    }

    /**
     * The check is a strict `!== true`. A loose truthy check would silently
     * accept whatever a client happened to serialise — a checkbox posted as
     * the string `'1'`, a JSON `1`, the literal text `'true'` — and record
     * consent on evidence that is not a genuine boolean acceptance.
     */
    public function test_step_6_rejects_truthy_junk_in_place_of_a_real_boolean_consent(): void
    {
        $junk = [
            'string true' => 'true',
            'integer one' => 1,
            'string one' => '1',
        ];

        foreach ($junk as $label => $value) {
            $errors = $this->assertRejectedWithKey(
                'privacy_notice_accepted',
                $this->draftReadyForStepSix(),
                BookingWizardStep::CUSTOMER_DATA,
                $this->customerPayload(['privacy_notice_accepted' => $value]),
                'idem-s6-consent-junk-'.md5($label)
            );

            $this->assertArrayHasKey('privacy_notice_accepted', $errors, "Expected {$label} to be rejected as consent.");
        }

        $this->assertCount(3, $junk);
    }

    public function test_step_6_rejects_a_payload_that_asserts_consent_only_through_a_timestamp(): void
    {
        // The consent column is not the consent assertion. Supplying only
        // the timestamp must not stand in for ticking the box.
        $payload = $this->customerPayloadWithout('privacy_notice_accepted');
        $payload['privacy_notice_accepted_at'] = Carbon::now()->toIso8601String();

        $this->assertRejectedWithKey(
            'privacy_notice_accepted',
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $payload,
            'idem-s6-consent-timestamp-only'
        );
    }

    public function test_step_6_stamps_the_consent_timestamp_from_the_server_clock(): void
    {
        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSix(),
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(),
            'idem-s6-consent-stamped'
        );

        $this->assertNotNull($saved->privacy_notice_accepted_at, 'Accepted consent must be recorded with a timestamp.');
        $this->assertLessThanOrEqual(
            5,
            abs($saved->privacy_notice_accepted_at->diffInSeconds(Carbon::now())),
            'The consent timestamp must come from the server clock at save time.'
        );
    }

    /**
     * The anti-backdating guarantee. A caller that sends its own
     * `privacy_notice_accepted_at` must not be able to move the recorded
     * moment of consent — the persisted value is the server's now, always.
     */
    public function test_step_6_ignores_a_caller_supplied_consent_timestamp(): void
    {
        $draft = $this->draftReadyForStepSix();

        (new SaveBookingDraftStep)($draft, BookingWizardStep::CUSTOMER_DATA, $this->customerPayload([
            'privacy_notice_accepted' => true,
            'privacy_notice_accepted_at' => '1999-01-01T00:00:00+00:00',
        ]), 'idem-s6-consent-backdate');

        $persisted = BookingDraft::query()->findOrFail($draft->id);

        $this->assertNotNull($persisted->privacy_notice_accepted_at);
        $this->assertNotSame(
            1999,
            $persisted->privacy_notice_accepted_at->year,
            'A caller-supplied consent timestamp must never be honoured — consent cannot be backdated.'
        );
        $this->assertLessThanOrEqual(
            5,
            abs($persisted->privacy_notice_accepted_at->diffInSeconds(Carbon::now())),
            'The persisted consent timestamp must be the server clock, not the caller payload.'
        );
    }

    // =====================================================================
    // Step 7 — deceased data, happy path
    // =====================================================================

    public function test_step_7_accepts_a_fully_valid_deceased_payload(): void
    {
        $draft = $this->draftReadyForStepSeven();

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::DECEASED_DATA, $this->deceasedPayload(), 'idem-s7-happy');

        $this->assertSame('Siti Rahayu', $saved->deceased_full_name);
        $this->assertSame('1950-03-04', $saved->deceased_date_of_birth->toDateString());
        $this->assertSame('2026-01-15', $saved->deceased_date_of_death->toDateString());
        $this->assertSame(BookingRelationshipCode::ORANG_TUA, $saved->deceased_relationship);
        $this->assertSame(BookingGender::PEREMPUAN, $saved->deceased_gender);
        $this->assertContains(BookingWizardStep::DECEASED_DATA, $saved->completed_steps);
        $this->assertSame(BookingWizardStep::PAYMENT, $saved->current_step);
    }

    // =====================================================================
    // Step 7 — deceased_full_name
    // =====================================================================

    public function test_step_7_rejects_a_missing_deceased_name(): void
    {
        $this->assertRejectedWithKey(
            'deceased_full_name',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayloadWithout('deceased_full_name'),
            'idem-s7-name-missing'
        );
    }

    public function test_step_7_rejects_a_deceased_name_under_three_characters(): void
    {
        $this->assertRejectedWithKey(
            'deceased_full_name',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_full_name' => 'Si']),
            'idem-s7-name-short'
        );
    }

    public function test_step_7_rejects_a_deceased_name_over_191_characters(): void
    {
        $this->assertRejectedWithKey(
            'deceased_full_name',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_full_name' => str_repeat('b', 192)]),
            'idem-s7-name-long'
        );
    }

    // =====================================================================
    // Step 7 — dates of birth and death
    // =====================================================================

    public function test_step_7_rejects_a_missing_date_of_birth(): void
    {
        $this->assertRejectedWithKey(
            'deceased_date_of_birth',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayloadWithout('deceased_date_of_birth'),
            'idem-s7-dob-missing'
        );
    }

    public function test_step_7_rejects_a_missing_date_of_death(): void
    {
        $this->assertRejectedWithKey(
            'deceased_date_of_death',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayloadWithout('deceased_date_of_death'),
            'idem-s7-dod-missing'
        );
    }

    public function test_step_7_rejects_an_unparseable_date_of_birth(): void
    {
        $this->assertRejectedWithKey(
            'deceased_date_of_birth',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_date_of_birth' => 'not-a-date']),
            'idem-s7-dob-unparseable'
        );
    }

    public function test_step_7_rejects_an_unparseable_date_of_death(): void
    {
        $this->assertRejectedWithKey(
            'deceased_date_of_death',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_date_of_death' => '31-31-2020']),
            'idem-s7-dod-unparseable'
        );
    }

    /**
     * A non-string date is a VALIDATION error, never a crash. `Carbon::parse()`
     * given an array raises a `TypeError` — an `Error`, not an `Exception` —
     * so an unguarded parse escaped the `catch` entirely and surfaced as a 500
     * on an unauthenticated public form. Arrays are exactly what a crafted
     * `?deceased_date_of_birth[]=x` query string produces.
     */
    public function test_step_7_rejects_an_array_valued_date_of_birth_without_crashing(): void
    {
        $this->assertRejectedWithKey(
            'deceased_date_of_birth',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_date_of_birth' => ['1950-03-04']]),
            'idem-s7-dob-array'
        );
    }

    public function test_step_7_rejects_an_array_valued_date_of_death_without_crashing(): void
    {
        $this->assertRejectedWithKey(
            'deceased_date_of_death',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_date_of_death' => ['2026-01-15']]),
            'idem-s7-dod-array'
        );
    }

    public function test_step_7_rejects_a_date_of_birth_after_the_date_of_death(): void
    {
        $this->assertRejectedWithKey(
            'deceased_date_of_birth',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload([
                'deceased_date_of_birth' => '2020-05-05',
                'deceased_date_of_death' => '2010-05-05',
            ]),
            'idem-s7-dob-after-dod'
        );
    }

    public function test_step_7_rejects_a_date_of_birth_equal_to_the_date_of_death(): void
    {
        $this->assertRejectedWithKey(
            'deceased_date_of_birth',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload([
                'deceased_date_of_birth' => '2010-05-05',
                'deceased_date_of_death' => '2010-05-05',
            ]),
            'idem-s7-dob-equals-dod'
        );
    }

    public function test_step_7_rejects_a_date_of_death_in_the_future(): void
    {
        $this->assertRejectedWithKey(
            'deceased_date_of_death',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload([
                'deceased_date_of_birth' => '1950-03-04',
                'deceased_date_of_death' => Carbon::today()->addDay()->toDateString(),
            ]),
            'idem-s7-dod-future'
        );
    }

    public function test_step_7_accepts_a_date_of_death_of_today(): void
    {
        $today = Carbon::today()->toDateString();

        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload([
                'deceased_date_of_birth' => '1950-03-04',
                'deceased_date_of_death' => $today,
            ]),
            'idem-s7-dod-today'
        );

        $this->assertSame($today, $saved->deceased_date_of_death->toDateString());
    }

    public function test_step_7_accepts_a_birth_date_strictly_before_the_death_date(): void
    {
        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload([
                'deceased_date_of_birth' => '2010-05-04',
                'deceased_date_of_death' => '2010-05-05',
            ]),
            'idem-s7-dates-ordered'
        );

        $this->assertSame('2010-05-04', $saved->deceased_date_of_birth->toDateString());
        $this->assertSame('2010-05-05', $saved->deceased_date_of_death->toDateString());
    }

    // =====================================================================
    // Step 7 — deceased_relationship and deceased_gender
    // =====================================================================

    public function test_step_7_rejects_a_missing_relationship(): void
    {
        $this->assertRejectedWithKey(
            'deceased_relationship',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayloadWithout('deceased_relationship'),
            'idem-s7-rel-missing'
        );
    }

    public function test_step_7_rejects_a_relationship_outside_the_closed_list(): void
    {
        $this->assertRejectedWithKey(
            'deceased_relationship',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_relationship' => 'REKAN_KERJA']),
            'idem-s7-rel-unknown'
        );
    }

    public function test_step_7_accepts_every_known_relationship_code(): void
    {
        foreach (BookingRelationshipCode::KNOWN_CODES as $code) {
            $saved = (new SaveBookingDraftStep)(
                $this->draftReadyForStepSeven(),
                BookingWizardStep::DECEASED_DATA,
                $this->deceasedPayload(['deceased_relationship' => $code]),
                'idem-s7-rel-ok-'.$code
            );

            $this->assertSame($code, $saved->deceased_relationship);
        }
    }

    public function test_step_7_treats_gender_as_optional(): void
    {
        $omitted = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayloadWithout('deceased_gender'),
            'idem-s7-gender-omitted'
        );
        $this->assertNull($omitted->deceased_gender);

        $explicitNull = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_gender' => null]),
            'idem-s7-gender-null'
        );
        $this->assertNull($explicitNull->deceased_gender);

        // An empty string normalises to NULL rather than persisting as ''.
        // "Not stated" has exactly one representation in this column, so no
        // reader has to test for both.
        $emptyString = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_gender' => '']),
            'idem-s7-gender-empty'
        );
        $this->assertNull($emptyString->deceased_gender);

        $whitespaceOnly = (new SaveBookingDraftStep)(
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_gender' => '   ']),
            'idem-s7-gender-whitespace'
        );
        $this->assertNull($whitespaceOnly->deceased_gender);
    }

    public function test_step_7_rejects_a_gender_outside_the_closed_list(): void
    {
        $this->assertRejectedWithKey(
            'deceased_gender',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_gender' => 'PRIA']),
            'idem-s7-gender-unknown'
        );
    }

    /**
     * `BookingGender::isKnown()` is typed `string`. An array-valued gender
     * would raise a `TypeError` before the closed-list check ran — same 500
     * on a public form as the dates above.
     */
    public function test_step_7_rejects_an_array_valued_gender_without_crashing(): void
    {
        $this->assertRejectedWithKey(
            'deceased_gender',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(['deceased_gender' => [BookingGender::PEREMPUAN]]),
            'idem-s7-gender-array'
        );
    }

    public function test_step_7_accepts_every_known_gender_code(): void
    {
        foreach (BookingGender::KNOWN_CODES as $code) {
            $saved = (new SaveBookingDraftStep)(
                $this->draftReadyForStepSeven(),
                BookingWizardStep::DECEASED_DATA,
                $this->deceasedPayload(['deceased_gender' => $code]),
                'idem-s7-gender-ok-'.$code
            );

            $this->assertSame($code, $saved->deceased_gender);
        }
    }

    // =====================================================================
    // Step 7 — document paths are REFUSED (path-injection guard)
    //
    // Upload belongs to `App\Platform\DocumentVault` and is out of scope
    // for this lane, so there is NO legitimate caller with a path to
    // supply. A stored free-text path would let a caller aim a draft at an
    // arbitrary storage location — including another customer's
    // quarantined identity document. Both a traversal string and a
    // plausible-looking vault key must be refused: the guard is "no
    // caller-supplied path at all", not "no obviously hostile path".
    // =====================================================================

    public function test_step_7_refuses_a_caller_supplied_ktp_path(): void
    {
        foreach (['../../etc/passwd', 'quarantine/abc.jpg'] as $path) {
            $this->assertRejectedWithKey(
                'document_ktp_path',
                $this->draftReadyForStepSeven(),
                BookingWizardStep::DECEASED_DATA,
                $this->deceasedPayload(['document_ktp_path' => $path]),
                'idem-s7-ktp-'.md5($path)
            );
        }
    }

    public function test_step_7_refuses_a_caller_supplied_kk_path(): void
    {
        foreach (['../../etc/passwd', 'quarantine/abc.jpg'] as $path) {
            $this->assertRejectedWithKey(
                'document_kk_path',
                $this->draftReadyForStepSeven(),
                BookingWizardStep::DECEASED_DATA,
                $this->deceasedPayload(['document_kk_path' => $path]),
                'idem-s7-kk-'.md5($path)
            );
        }
    }

    public function test_step_7_refuses_a_caller_supplied_death_certificate_path(): void
    {
        foreach (['../../etc/passwd', 'quarantine/abc.jpg'] as $path) {
            $this->assertRejectedWithKey(
                'document_death_certificate_path',
                $this->draftReadyForStepSeven(),
                BookingWizardStep::DECEASED_DATA,
                $this->deceasedPayload(['document_death_certificate_path' => $path]),
                'idem-s7-akta-'.md5($path)
            );
        }
    }

    public function test_step_7_refuses_a_payload_carrying_all_three_document_paths(): void
    {
        $errors = $this->assertRejectedWithKey(
            'document_ktp_path',
            $this->draftReadyForStepSeven(),
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload([
                'document_ktp_path' => 'quarantine/ktp.jpg',
                'document_kk_path' => 'quarantine/kk.jpg',
                'document_death_certificate_path' => 'quarantine/akta.pdf',
            ]),
            'idem-s7-docs-all'
        );

        $this->assertArrayHasKey('document_kk_path', $errors);
        $this->assertArrayHasKey('document_death_certificate_path', $errors);
    }

    public function test_step_7_leaves_all_document_columns_null_after_a_successful_save(): void
    {
        $draft = $this->draftReadyForStepSeven();

        (new SaveBookingDraftStep)($draft, BookingWizardStep::DECEASED_DATA, $this->deceasedPayload(), 'idem-s7-docs-null');

        $persisted = BookingDraft::query()->findOrFail($draft->id);

        $this->assertNull($persisted->document_ktp_path);
        $this->assertNull($persisted->document_kk_path);
        $this->assertNull($persisted->document_death_certificate_path);
    }

    // =====================================================================
    // Step 8 — payment method and reference
    // =====================================================================

    public function test_step_8_rejects_a_missing_payment_method(): void
    {
        $this->bindPaymentGate(open: false);

        $this->assertRejectedWithKey(
            'payment_method',
            $this->draftReadyForStepEight(),
            BookingWizardStep::PAYMENT,
            [],
            'idem-s8-method-missing'
        );
    }

    public function test_step_8_rejects_a_blank_payment_method(): void
    {
        $this->bindPaymentGate(open: false);

        $this->assertRejectedWithKey(
            'payment_method',
            $this->draftReadyForStepEight(),
            BookingWizardStep::PAYMENT,
            ['payment_method' => ''],
            'idem-s8-method-blank'
        );
    }

    public function test_step_8_rejects_a_payment_method_outside_the_closed_list(): void
    {
        $this->bindPaymentGate(open: false);

        $this->assertRejectedWithKey(
            'payment_method',
            $this->draftReadyForStepEight(),
            BookingWizardStep::PAYMENT,
            ['payment_method' => 'CRYPTO'],
            'idem-s8-method-unknown'
        );
    }

    // =====================================================================
    // Step 8 — G-PAY-01 (gate-bypass guard)
    //
    // The single most important assertion in this file. Hiding the online
    // control in Blade is presentation; `saveStep8('ONLINE')` is a plain
    // client-callable Livewire method, so the closed gate has to be
    // enforced right here, on the authoritative gate state.
    // =====================================================================

    public function test_step_8_rejects_online_payment_while_the_gate_is_closed(): void
    {
        $this->bindPaymentGate(open: false);

        $draft = $this->draftReadyForStepEight();

        $this->assertRejectedWithKey(
            'payment_method',
            $draft,
            BookingWizardStep::PAYMENT,
            ['payment_method' => BookingPaymentMethod::ONLINE],
            'idem-s8-online-gate-closed'
        );

        $persisted = BookingDraft::query()->findOrFail($draft->id);

        $this->assertNull($persisted->payment_method, 'A gate-bypass attempt must persist nothing.');
        $this->assertSame(1, $persisted->version, 'A rejected save must never bump the version.');
        $this->assertNotContains(BookingWizardStep::PAYMENT, $persisted->completed_steps);
    }

    public function test_step_8_accepts_online_payment_once_the_gate_is_open(): void
    {
        $this->bindPaymentGate(open: true);

        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForStepEight(),
            BookingWizardStep::PAYMENT,
            ['payment_method' => BookingPaymentMethod::ONLINE],
            'idem-s8-online-gate-open'
        );

        $this->assertSame(BookingPaymentMethod::ONLINE, $saved->payment_method);
        $this->assertContains(BookingWizardStep::PAYMENT, $saved->completed_steps);
    }

    public function test_step_8_accepts_manual_payment_with_a_reference_while_the_gate_is_closed(): void
    {
        $this->bindPaymentGate(open: false);

        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForStepEight(),
            BookingWizardStep::PAYMENT,
            [
                'payment_method' => BookingPaymentMethod::MANUAL,
                'payment_reference' => 'TRF-2026-0001',
            ],
            'idem-s8-manual-gate-closed'
        );

        $this->assertSame(BookingPaymentMethod::MANUAL, $saved->payment_method);
        $this->assertSame('TRF-2026-0001', $saved->payment_reference);
        $this->assertContains(BookingWizardStep::PAYMENT, $saved->completed_steps);
        $this->assertSame(BookingWizardStep::CONFIRMATION, $saved->current_step);
    }

    /**
     * §6.9: "Step 8 is never removed." Manual coordination is the closed-gate
     * fallback, not a closed-gate-only path — it must stay available when the
     * gate opens.
     */
    public function test_step_8_keeps_manual_payment_available_once_the_gate_is_open(): void
    {
        $this->bindPaymentGate(open: true);

        $saved = (new SaveBookingDraftStep)(
            $this->draftReadyForStepEight(),
            BookingWizardStep::PAYMENT,
            [
                'payment_method' => BookingPaymentMethod::MANUAL,
                'payment_reference' => 'TRF-2026-0002',
            ],
            'idem-s8-manual-gate-open'
        );

        $this->assertSame(BookingPaymentMethod::MANUAL, $saved->payment_method);
        $this->assertSame('TRF-2026-0002', $saved->payment_reference);
    }

    // =====================================================================
    // Step 8 — payment_reference is mandatory for manual coordination
    // =====================================================================

    public function test_step_8_rejects_manual_payment_without_a_reference(): void
    {
        $this->bindPaymentGate(open: false);

        $this->assertRejectedWithKey(
            'payment_reference',
            $this->draftReadyForStepEight(),
            BookingWizardStep::PAYMENT,
            ['payment_method' => BookingPaymentMethod::MANUAL],
            'idem-s8-manual-ref-missing'
        );
    }

    public function test_step_8_rejects_manual_payment_with_a_blank_or_whitespace_only_reference(): void
    {
        $this->bindPaymentGate(open: false);

        foreach (['' => 'blank', '   ' => 'spaces', "\t\n " => 'tabs and newlines'] as $reference => $label) {
            $errors = $this->assertRejectedWithKey(
                'payment_reference',
                $this->draftReadyForStepEight(),
                BookingWizardStep::PAYMENT,
                [
                    'payment_method' => BookingPaymentMethod::MANUAL,
                    'payment_reference' => $reference,
                ],
                'idem-s8-manual-ref-'.md5($label)
            );

            $this->assertArrayHasKey('payment_reference', $errors, "Expected a {$label} reference to be rejected.");
        }
    }

    public function test_step_8_rejects_a_manual_payment_reference_over_191_characters(): void
    {
        $this->bindPaymentGate(open: false);

        $this->assertRejectedWithKey(
            'payment_reference',
            $this->draftReadyForStepEight(),
            BookingWizardStep::PAYMENT,
            [
                'payment_method' => BookingPaymentMethod::MANUAL,
                'payment_reference' => str_repeat('r', 192),
            ],
            'idem-s8-manual-ref-long'
        );
    }

    // =====================================================================
    // Read-only steps and the boundary beyond the last implemented step
    //
    // `LAST_IMPLEMENTED` moved from 5 to 9 in this batch, so the previous
    // "beyond the batch boundary" test no longer describes a real boundary
    // and was deleted. Equivalent coverage is re-established here at the NEW
    // boundary: the wizard has exactly nine steps, and `assertKnown()` is
    // now the sole thing standing between a caller and step 10.
    // =====================================================================

    public function test_the_read_only_summary_step_has_no_save_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SaveBookingDraftStep)($this->draftReadyForStepSix(), BookingWizardStep::SUMMARY, [], 'idem-readonly-5');
    }

    public function test_the_read_only_confirmation_step_has_no_save_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SaveBookingDraftStep)($this->draftReadyForStepSix(), BookingWizardStep::CONFIRMATION, [], 'idem-readonly-9');
    }

    public function test_a_step_beyond_the_new_last_implemented_boundary_is_rejected(): void
    {
        $draft = $this->draftReadyForStepSix();

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::LAST_IMPLEMENTED + 1, [], 'idem-boundary-10');
            $this->fail('Expected InvalidArgumentException for a step beyond the implemented boundary.');
        } catch (\InvalidArgumentException) {
            // Expected.
        }

        $persisted = BookingDraft::query()->findOrFail($draft->id);

        $this->assertSame(1, $persisted->version, 'A rejected step must never bump the version.');
        $this->assertSame([1, 2, 3, 4], $persisted->completed_steps);
    }

    // =====================================================================
    // Step sequencing for steps 6-8 — AC13's "unskippable" half
    // =====================================================================

    public function test_step_6_cannot_be_saved_on_a_draft_missing_steps_1_to_4(): void
    {
        $draft = BookingDraft::create([]);

        $this->assertRejectedWithKey(
            'step',
            $draft,
            BookingWizardStep::CUSTOMER_DATA,
            $this->customerPayload(),
            'idem-seq-s6'
        );

        $persisted = BookingDraft::query()->findOrFail($draft->id);

        $this->assertNull($persisted->customer_full_name, 'A skipped step must persist nothing.');
        $this->assertNull($persisted->privacy_notice_accepted_at);
        $this->assertSame(1, $persisted->version);
    }

    /**
     * The skippability hole this rule closes: with only steps 1-4 complete a
     * caller could save step 8 and land on Confirmation with NO customer and
     * NO deceased data at all — every PII field an em dash.
     */
    public function test_step_8_cannot_be_saved_on_a_draft_that_only_completed_steps_1_to_4(): void
    {
        $this->bindPaymentGate(open: false);

        $draft = $this->draftReadyForStepSix();

        $this->assertRejectedWithKey(
            'step',
            $draft,
            BookingWizardStep::PAYMENT,
            [
                'payment_method' => BookingPaymentMethod::MANUAL,
                'payment_reference' => 'TRF-2026-0003',
            ],
            'idem-seq-s8'
        );

        $this->assertNull(BookingDraft::query()->findOrFail($draft->id)->payment_method);
    }

    /**
     * Steps 6 and 7 are the two that carry the PII. Requiring only steps 1-4
     * for all of 6, 7 and 8 would let a caller jump straight to payment and
     * reach Confirmation with no customer and no deceased on record.
     */
    public function test_step_7_cannot_be_saved_before_step_6(): void
    {
        $draft = $this->draftReadyForStepSix();

        $this->assertRejectedWithKey(
            'step',
            $draft,
            BookingWizardStep::DECEASED_DATA,
            $this->deceasedPayload(),
            'idem-seq-s7-no-s6'
        );

        $this->assertNull(BookingDraft::query()->findOrFail($draft->id)->deceased_full_name);
    }

    public function test_step_8_cannot_be_saved_before_step_7(): void
    {
        $this->bindPaymentGate(open: false);

        $draft = $this->draftReadyForStepSeven();

        $this->assertRejectedWithKey(
            'step',
            $draft,
            BookingWizardStep::PAYMENT,
            [
                'payment_method' => BookingPaymentMethod::MANUAL,
                'payment_reference' => 'TRF-2026-0004',
            ],
            'idem-seq-s8-no-s7'
        );

        $this->assertNull(BookingDraft::query()->findOrFail($draft->id)->payment_method);
    }
}
