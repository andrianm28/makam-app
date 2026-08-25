<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\BookingContactChannel;
use App\Domain\Booking\BookingGender;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingRelationshipCode;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingDraftVersionConflictException;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Validates and persists one wizard step's payload onto an existing
 * `BookingDraft`, server-side and authoritative regardless of what the
 * client already checked — `booking-and-order-orchestration` AC3.
 * Idempotent and versioned — AC2.
 *
 * Steps 1-4 (location, cemetery, service type, services) were built in
 * the prior batch; this batch completes Steps 6-8 (customer data,
 * deceased data + documents, payment). Step 5 (summary) and Step 9
 * (confirmation) are read-only and have no save action.
 *
 * Never `SensitiveActions`-listed — a booking step save is routine
 * customer input, not a privileged action.
 */
final readonly class SaveBookingDraftStep
{
    public function __invoke(BookingDraft $draft, int $step, array $payload, string $idempotencyKey, ?int $expectedVersion = null): BookingDraft
    {
        // `assertKnown` already rejects anything outside 1-9, and
        // `LAST_IMPLEMENTED` is now 9 (CONFIRMATION), so the old
        // "not implemented yet" branch that sat here became unreachable the
        // moment this lane completed the wizard. It was removed rather than
        // left in place: a guard that cannot fire reads as protection that
        // does not exist, and its test was deleted for the same reason.
        // Out-of-range steps are rejected by `assertKnown` alone.
        BookingWizardStep::assertKnown($step);

        // Step 5 (SUMMARY) and Step 9 (CONFIRMATION) are READ-ONLY — no write
        // action and no payload. Without this guard, either falls through both
        // `match`es' `default` arms: no validation, no attributes, but a version
        // bump and `current_step = step + 1` — permanently stranding the draft
        // with no way forward or back. Rejected here in the same boundary-check
        // style rather than given a silent no-op arm, because no valid caller
        // exists for either read-only step.
        if ($step === BookingWizardStep::SUMMARY) {
            throw new InvalidArgumentException(
                'Step ['.BookingWizardStep::SUMMARY.'] (Ringkasan Pesanan) is read-only and has no save action.'
            );
        }

        if ($step === BookingWizardStep::CONFIRMATION) {
            throw new InvalidArgumentException(
                'Step ['.BookingWizardStep::CONFIRMATION.'] (Konfirmasi) is read-only and has no save action.'
            );
        }

        // Idempotency replay: the same key for the same draft means this
        // exact save already happened — return the current state without
        // re-validating or re-bumping the version. See Task 8 for the
        // dedicated replay/conflict test suite this guards.
        if ($draft->last_idempotency_key === $idempotencyKey) {
            return $draft;
        }

        if ($expectedVersion !== null && $draft->version !== $expectedVersion) {
            throw new BookingDraftVersionConflictException($expectedVersion, $draft->version);
        }

        $sequencingErrors = self::validateStepSequencing($step, $draft);

        if ($sequencingErrors !== []) {
            throw new BookingStepValidationException($sequencingErrors);
        }

        $errors = match ($step) {
            BookingWizardStep::LOCATION => self::validateLocation($payload),
            BookingWizardStep::CEMETERY => self::validateCemetery($payload, $draft),
            BookingWizardStep::SERVICE_TYPE => self::validateServiceType($payload),
            BookingWizardStep::SERVICES => self::validateServices($payload),
            BookingWizardStep::CUSTOMER_DATA => self::validateCustomerData($payload),
            BookingWizardStep::DECEASED_DATA => self::validateDeceasedData($payload),
            BookingWizardStep::PAYMENT => self::validatePayment($payload, app(ModeResolver::class)->paymentMode()),
            default => [],
        };

        if ($errors !== []) {
            throw new BookingStepValidationException($errors);
        }

        return DB::transaction(function () use ($draft, $step, $payload, $idempotencyKey): BookingDraft {
            // Persist onto an authoritative reload so the caller's instance
            // is never mutated in place — every save returns an independent
            // snapshot, which is what the optimistic-version contract
            // (`$expectedVersion` against `version`) is built on.
            $current = BookingDraft::query()->findOrFail($draft->id);

            $attributes = match ($step) {
                BookingWizardStep::LOCATION => ['city_code' => $payload['city_code']],
                BookingWizardStep::CEMETERY => [
                    'cemetery_id' => $payload['cemetery_id'],
                    'cemetery_package_id' => $payload['cemetery_package_id'] ?? null,
                ],
                BookingWizardStep::SERVICE_TYPE => ['service_type' => $payload['service_type']],
                BookingWizardStep::SERVICES => ['selected_services' => $payload['selected_services']],
                // Persist the TRIMMED value, because that is the value that
                // was validated. Storing the raw payload while length-checking
                // `trim()`'d text let padding slip past the bound and reach a
                // varchar(191) column as an over-long string — a driver error
                // on a public form instead of a clean validation message.
                BookingWizardStep::CUSTOMER_DATA => [
                    'customer_full_name' => self::trimmed($payload['customer_full_name']),
                    'customer_mobile' => self::trimmed($payload['customer_mobile']),
                    'customer_email' => self::trimmed($payload['customer_email']),
                    'customer_address' => self::trimmed($payload['customer_address']),
                    'customer_relationship' => $payload['customer_relationship'],
                    'customer_contact_channel' => $payload['customer_contact_channel'],
                    // Stamped from the server clock, reached only because
                    // validation above observed a genuine `true`. This is the
                    // record that consent happened, so its time must come
                    // from us, not from whoever sent the request.
                    'privacy_notice_accepted_at' => Carbon::now()->toDateTimeString(),
                ],
                BookingWizardStep::DECEASED_DATA => [
                    'deceased_full_name' => self::trimmed($payload['deceased_full_name']),
                    'deceased_date_of_birth' => $payload['deceased_date_of_birth'],
                    'deceased_date_of_death' => $payload['deceased_date_of_death'],
                    'deceased_relationship' => $payload['deceased_relationship'],
                    // One representation of "not stated", not two: an empty
                    // string and null both mean unknown, and a column holding
                    // both forces every reader to test for each.
                    'deceased_gender' => self::nullIfBlank($payload['deceased_gender'] ?? null),
                    // Document paths are deliberately NOT written from the
                    // payload — see `validateDeceasedData()`.
                ],
                BookingWizardStep::PAYMENT => [
                    'payment_method' => $payload['payment_method'],
                    'payment_reference' => self::nullIfBlank($payload['payment_reference'] ?? null),
                ],
                default => [],
            };

            $completedSteps = $current->completed_steps;
            if (! in_array($step, $completedSteps, true)) {
                $completedSteps[] = $step;
                sort($completedSteps);
            }

            $current->fill([
                ...$attributes,
                'completed_steps' => $completedSteps,
                'current_step' => min($step + 1, BookingWizardStep::LAST_IMPLEMENTED + 1),
                'version' => $current->version + 1,
                'last_idempotency_key' => $idempotencyKey,
            ]);
            $current->save();

            // `platform-outbox` AC1 — same transaction as the save above.
            // Provisional event name; see `StartBookingDraft`'s own note and
            // finding N-17 for why `event-catalog.md` has no entry to use.
            Outbox::record(
                eventName: 'booking.draft_step_saved.v1',
                eventVersion: 1,
                aggregateType: 'booking_draft',
                aggregateId: $current->id,
                data: [
                    'draft_id' => $current->id,
                    'step' => $step,
                    'version' => $current->version,
                    'completed_steps' => $current->completed_steps,
                    // AC2 is references-only: the step's own `$payload`
                    // (city, cemetery, service selections) is CONTENT and is
                    // deliberately not forwarded. A consumer reads the draft
                    // by `draft_id`. This also keeps the event immune to a
                    // future step adding a restricted field, rather than
                    // relying on `PayloadClassification`'s key-name denylist
                    // — which its own doc block says is "not a substitute for
                    // producers themselves following references-only."
                ],
                classification: OutboxClassification::Internal,
                // `version` bumps exactly once per accepted save, so this is
                // unique per real save yet deterministic. Step alone is not
                // unique: back-navigation legitimately re-saves a step (AC11)
                // and each re-save is a distinct event, not a replay. A true
                // replay never reaches here — it returns early above, before
                // this transaction opens.
                idempotencyKey: "booking_draft:{$current->id}:step:{$step}:v{$current->version}",
            );

            Audit::record(
                action: 'BOOKING_DRAFT_STEP_SAVED',
                subject: new AuditSubject('booking_draft', $current->id, $current->version),
                outcome: AuditOutcome::Allowed,
                actorRef: $current->user_id,
                actorRole: $current->user_id !== null ? 'customer' : 'guest',
                source: AuditSource::Api,
            );

            return $current->refresh();
        });
    }

    /**
     * `booking-wizard-fields.md` §Global behavior: "User cannot skip required
     * upstream decisions", and `public-booking-wizard` AC13's "unskippable"
     * half. Enforced SERVER-SIDE against the draft's own persisted
     * `completed_steps`, never against client state — the Livewire
     * component's `$currentStep`/`$completedSteps` are `#[Locked]`, but a
     * hand-crafted request straight to this Action must be rejected on the
     * draft's own record regardless of what any client claims.
     *
     * Step 5 (SUMMARY) is read-only and never appears in `completed_steps`.
     * For steps 6-8, we require that ALL steps 1-4 are complete, not just
     * the immediately preceding step (which for Step 6 would be Step 5, which
     * is never saved). This is the "all upstream decisions" rule applied to
     * the non-read-only steps that bracket the read-only Step 5.
     *
     * @return array<string, list<string>>
     */
    private static function validateStepSequencing(int $step, BookingDraft $draft): array
    {
        if ($step === BookingWizardStep::LOCATION) {
            return [];
        }

        if ($step >= BookingWizardStep::CUSTOMER_DATA) {
            // Every writable step BELOW this one must already be done, not
            // merely steps 1-4. An earlier version required only 1-4 for all
            // of 6, 7 and 8, which let a caller jump straight to step 8 and
            // land on Confirmation with an em dash for every customer and
            // deceased field — `public-booking-wizard` AC13's "unskippable"
            // rule broken for exactly the steps that carry the PII.
            //
            // Step 5 (SUMMARY) is excluded because it is read-only and
            // therefore never appears in `completed_steps`.
            $required = array_values(array_filter(
                [
                    BookingWizardStep::LOCATION,
                    BookingWizardStep::CEMETERY,
                    BookingWizardStep::SERVICE_TYPE,
                    BookingWizardStep::SERVICES,
                    BookingWizardStep::CUSTOMER_DATA,
                    BookingWizardStep::DECEASED_DATA,
                ],
                static fn (int $candidate): bool => $candidate < $step,
            ));

            $missing = array_diff($required, $draft->completed_steps);
            if ($missing !== []) {
                return ['step' => ['Selesaikan semua langkah sebelumnya terlebih dahulu.']];
            }

            return [];
        }

        if (in_array($step - 1, $draft->completed_steps, true)) {
            return [];
        }

        return ['step' => ['Selesaikan langkah sebelumnya terlebih dahulu.']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateLocation(array $payload): array
    {
        $city = $payload['city_code'] ?? null;

        if ($city === null || $city === '') {
            return ['city_code' => ['Pilih kota terlebih dahulu.']];
        }

        if (! LaunchCityQuery::isKnown($city)) {
            return ['city_code' => ['Kota yang dipilih tidak dikenali.']];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateCemetery(array $payload, BookingDraft $draft): array
    {
        $errors = [];

        $cemeteryId = $payload['cemetery_id'] ?? null;

        if ($cemeteryId === null || $cemeteryId === '') {
            return ['cemetery_id' => ['Pilih TPU/TPS terlebih dahulu.']];
        }

        $cemetery = CemeteryPublicQuery::findPublishedById((string) $cemeteryId);

        if ($cemetery === null) {
            return ['cemetery_id' => ['TPU/TPS yang dipilih tidak tersedia.']];
        }

        if ($draft->city_code !== null && $cemetery->city !== $draft->city_code) {
            $errors['cemetery_id'] = ['TPU/TPS yang dipilih berada di luar kota yang dipilih pada langkah 1.'];

            return $errors;
        }

        $activePackages = CemeteryPublicQuery::activePackages($cemetery);

        if ($activePackages->isNotEmpty()) {
            $packageId = $payload['cemetery_package_id'] ?? null;

            if ($packageId === null || $packageId === '') {
                $errors['cemetery_package_id'] = ['Pilih paket/kelas untuk TPU/TPS ini.'];
            } elseif (! $activePackages->contains('id', (int) $packageId)) {
                $errors['cemetery_package_id'] = ['Paket/kelas yang dipilih tidak tersedia untuk TPU/TPS ini.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateServiceType(array $payload): array
    {
        $serviceType = $payload['service_type'] ?? null;

        if ($serviceType === null || $serviceType === '') {
            return ['service_type' => ['Pilih jenis layanan terlebih dahulu.']];
        }

        if (! BookingServiceType::isKnown($serviceType)) {
            return ['service_type' => ['Jenis layanan yang dipilih tidak dikenali.']];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateServices(array $payload): array
    {
        $selections = $payload['selected_services'] ?? [];

        if (! is_array($selections) || $selections === []) {
            return ['selected_services' => ['Pilih minimal layanan dasar.']];
        }

        $selectedCodes = [];

        foreach ($selections as $selection) {
            $code = $selection['code'] ?? null;
            $quantity = $selection['quantity'] ?? null;

            if (! is_string($code) || ! ServiceCode::isKnown($code)) {
                return ['selected_services' => ["Layanan [{$code}] tidak dikenali."]];
            }

            if (! is_int($quantity) || $quantity < 1) {
                return ['selected_services' => ["Jumlah untuk layanan [{$code}] harus lebih dari nol."]];
            }

            $selectedCodes[] = $code;
        }

        // One row per service — quantity is what expresses "more than one",
        // never a repeated code. A duplicate would otherwise double-count in
        // `BookingDraftQuery::summary()`'s total and render the same line
        // twice on Step 5.
        if (count($selectedCodes) !== count(array_unique($selectedCodes))) {
            return ['selected_services' => ['Layanan tidak boleh dipilih lebih dari satu kali.']];
        }

        $missingBasics = array_diff(ServiceCode::BASIC_CODES, $selectedCodes);

        if ($missingBasics !== []) {
            return ['selected_services' => [
                'Layanan dasar wajib disertakan: '.implode(', ', $missingBasics).'.',
            ]];
        }

        return [];
    }

    /**
     * The trimmed form of an already-validated string field. Non-strings are
     * returned untouched — validation has already rejected them, so this
     * never has to guess what a caller meant.
     */
    private static function trimmed(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * `null` for a value that is absent or only whitespace, so an optional
     * field has exactly one "not provided" representation in the column.
     */
    private static function nullIfBlank(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateCustomerData(array $payload): array
    {
        $errors = [];

        $fullName = $payload['customer_full_name'] ?? null;
        if (! is_string($fullName) || trim($fullName) === '' || mb_strlen($fullName) < 3) {
            $errors['customer_full_name'] = ['Nama lengkap harus diisi minimal 3 karakter.'];
        } elseif (mb_strlen($fullName) > 191) {
            $errors['customer_full_name'] = ['Nama lengkap terlalu panjang.'];
        }

        $mobile = $payload['customer_mobile'] ?? null;
        if (! is_string($mobile) || trim($mobile) === '') {
            $errors['customer_mobile'] = ['Nomor HP harus diisi.'];
        } elseif (! preg_match('/^(\+62|62|0)[0-9]{9,13}$/', trim($mobile))) {
            $errors['customer_mobile'] = ['Nomor HP tidak valid. Gunakan format Indonesia (08xx atau +62xx).'];
        }

        $email = $payload['customer_email'] ?? null;
        if (! is_string($email) || trim($email) === '') {
            $errors['customer_email'] = ['Email harus diisi.'];
        } elseif (! filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            $errors['customer_email'] = ['Format email tidak valid.'];
        } elseif (mb_strlen(trim($email)) > 191) {
            // `customer_email` is varchar(191). Without this bound a longer
            // (still syntactically valid) address reaches Postgres and raises
            // a driver error on a public form instead of a field-keyed message.
            $errors['customer_email'] = ['Email terlalu panjang.'];
        }

        $address = $payload['customer_address'] ?? null;
        if (! is_string($address) || trim($address) === '' || mb_strlen($address) < 10) {
            $errors['customer_address'] = ['Alamat lengkap harus diisi minimal 10 karakter.'];
        }

        $relationship = $payload['customer_relationship'] ?? null;
        if (! is_string($relationship) || $relationship === '') {
            $errors['customer_relationship'] = ['Hubungan dengan almarhum harus dipilih.'];
        } elseif (! BookingRelationshipCode::isKnown($relationship)) {
            $errors['customer_relationship'] = ['Hubungan tidak valid.'];
        }

        $channel = $payload['customer_contact_channel'] ?? null;
        if (! is_string($channel) || $channel === '') {
            $errors['customer_contact_channel'] = ['Saluran kontak yang disukai harus dipilih.'];
        } elseif (! BookingContactChannel::isKnown($channel)) {
            $errors['customer_contact_channel'] = ['Saluran kontak tidak valid.'];
        }

        // Consent is a BOOLEAN act by the user, never a timestamp supplied by
        // the caller. The caller asserts only "the box was ticked"; the
        // timestamp is stamped server-side, below, and only once that
        // assertion is true. Accepting a caller-supplied
        // `privacy_notice_accepted_at` would let any client backdate consent,
        // and — as this component previously did — record consent that the
        // user never actually gave.
        if (($payload['privacy_notice_accepted'] ?? null) !== true) {
            $errors['privacy_notice_accepted'] = ['Anda harus menyetujui pemberitahuan privasi untuk melanjutkan.'];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateDeceasedData(array $payload): array
    {
        $errors = [];

        $fullName = $payload['deceased_full_name'] ?? null;
        if (! is_string($fullName) || trim($fullName) === '' || mb_strlen($fullName) < 3) {
            $errors['deceased_full_name'] = ['Nama almarhum harus diisi minimal 3 karakter.'];
        } elseif (mb_strlen($fullName) > 191) {
            $errors['deceased_full_name'] = ['Nama almarhum terlalu panjang.'];
        }

        $dob = $payload['deceased_date_of_birth'] ?? null;
        $dod = $payload['deceased_date_of_death'] ?? null;

        // `is_string` FIRST, and `\Throwable` rather than `\Exception`:
        // `Carbon::parse()` given an array raises a TypeError, which is an
        // Error and not an Exception, so it escaped the old `catch` and
        // surfaced as a 500 on an unauthenticated public form. A malformed
        // value is a validation error, never a crash.
        //
        // These two are deliberately REDUNDANT: either alone handles the
        // array case, so the tests pin the outcome rather than the type
        // check. Both stay. The `is_string` check states the intent at the
        // point of use, and `catch (\Throwable)` is the backstop for every
        // input shape nobody thought to enumerate — including whatever a
        // future narrowing of this check would let through.
        $parsedDob = null;
        if ($dob === null || $dob === '') {
            $errors['deceased_date_of_birth'] = ['Tanggal lahir almarhum harus diisi.'];
        } elseif (! is_string($dob)) {
            $errors['deceased_date_of_birth'] = ['Format tanggal lahir tidak valid.'];
        } else {
            try {
                $parsedDob = Carbon::parse($dob);
            } catch (\Throwable) {
                $errors['deceased_date_of_birth'] = ['Format tanggal lahir tidak valid.'];
            }
        }

        $parsedDod = null;
        if ($dod === null || $dod === '') {
            $errors['deceased_date_of_death'] = ['Tanggal meninggal harus diisi.'];
        } elseif (! is_string($dod)) {
            $errors['deceased_date_of_death'] = ['Format tanggal meninggal tidak valid.'];
        } else {
            try {
                $parsedDod = Carbon::parse($dod);
            } catch (\Throwable) {
                $errors['deceased_date_of_death'] = ['Format tanggal meninggal tidak valid.'];
            }
        }

        if ($parsedDob !== null && $parsedDod !== null) {
            if ($parsedDob->greaterThanOrEqualTo($parsedDod)) {
                $errors['deceased_date_of_birth'] = ['Tanggal lahir harus sebelum tanggal meninggal.'];
            }
        }

        if ($parsedDod !== null && $parsedDod->greaterThan(Carbon::today())) {
            $errors['deceased_date_of_death'] = ['Tanggal meninggal tidak boleh di masa depan.'];
        }

        $relationship = $payload['deceased_relationship'] ?? null;
        if (! is_string($relationship) || $relationship === '') {
            $errors['deceased_relationship'] = ['Hubungan dengan pemesan harus dipilih.'];
        } elseif (! BookingRelationshipCode::isKnown($relationship)) {
            $errors['deceased_relationship'] = ['Hubungan tidak valid.'];
        }

        // Gender is OPTIONAL — a deliberate, documented deviation from this
        // lane's plan, which listed it as required. The family may simply not
        // want to state it, and a funeral booking must not be blocked on it.
        // `is_string` guards the same TypeError route as the dates above.
        // Blankness is measured AFTER trimming, as it is for every other
        // field here. Testing the raw value made "   " neither blank (so
        // validation demanded a known code) nor a code (so it failed) —
        // while persistence would have normalised the same input to null.
        // Validation and persistence must agree on what "not stated" means.
        $gender = $payload['deceased_gender'] ?? null;
        if ($gender !== null) {
            if (! is_string($gender)) {
                $errors['deceased_gender'] = ['Jenis kelamin tidak valid.'];
            } elseif (trim($gender) !== '' && ! BookingGender::isKnown(trim($gender))) {
                $errors['deceased_gender'] = ['Jenis kelamin tidak valid.'];
            }
        }

        // Identity documents (KTP, KK, death certificate) belong to
        // `App\Platform\DocumentVault`, which owns upload, malware
        // quarantine, scan verdicts and signed-URL access. Upload is out of
        // scope for this lane, so there is no legitimate caller with a path
        // to supply — and an unvalidated free-text path would let a caller
        // point a draft at an arbitrary storage location, including another
        // customer's quarantined identity document. Refused outright rather
        // than stored unchecked; the column stays for the lane that wires
        // DocumentVault in properly.
        foreach (['document_ktp_path', 'document_kk_path', 'document_death_certificate_path'] as $documentField) {
            if (($payload[$documentField] ?? null) !== null) {
                $errors[$documentField] = ['Unggahan dokumen belum tersedia pada langkah ini.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validatePayment(array $payload, PaymentMode $mode): array
    {
        $errors = [];

        $method = $payload['payment_method'] ?? null;
        if (! is_string($method) || $method === '') {
            $errors['payment_method'] = ['Metode pembayaran harus dipilih.'];
        } elseif (! BookingPaymentMethod::isKnown($method)) {
            $errors['payment_method'] = ['Metode pembayaran tidak valid.'];
        } elseif ($method === BookingPaymentMethod::ONLINE && $mode !== PaymentMode::Online) {
            // G-PAY-01 enforced HERE, server-side, on the authoritative gate
            // state — not merely by which control the Blade view chose to
            // render. Hiding the online button while this Action still
            // accepted `ONLINE` left the closed gate bypassable by anyone
            // calling `saveStep8('ONLINE')` directly, which Livewire exposes
            // as a plain client-callable method.
            $errors['payment_method'] = ['Pembayaran online belum tersedia. Gunakan koordinasi pembayaran manual.'];
        }

        $reference = $payload['payment_reference'] ?? null;
        if ($method === BookingPaymentMethod::MANUAL) {
            if (! is_string($reference) || trim($reference) === '') {
                $errors['payment_reference'] = ['Referensi pembayaran harus diisi untuk metode manual.'];
            } elseif (mb_strlen($reference) > 191) {
                $errors['payment_reference'] = ['Referensi pembayaran terlalu panjang.'];
            }
        }

        return $errors;
    }
}
