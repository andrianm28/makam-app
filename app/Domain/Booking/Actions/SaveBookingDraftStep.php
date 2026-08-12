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
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
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
        BookingWizardStep::assertKnown($step);

        if ($step > BookingWizardStep::LAST_IMPLEMENTED) {
            throw new InvalidArgumentException(
                "Step [{$step}] is not implemented yet. Last implemented step: ".BookingWizardStep::LAST_IMPLEMENTED.'.'
            );
        }

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
            BookingWizardStep::PAYMENT => self::validatePayment($payload),
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
                BookingWizardStep::CUSTOMER_DATA => [
                    'customer_full_name' => $payload['customer_full_name'],
                    'customer_mobile' => $payload['customer_mobile'],
                    'customer_email' => $payload['customer_email'],
                    'customer_address' => $payload['customer_address'],
                    'customer_relationship' => $payload['customer_relationship'],
                    'customer_contact_channel' => $payload['customer_contact_channel'],
                    'privacy_notice_accepted_at' => isset($payload['privacy_notice_accepted_at'])
                        ? Carbon::parse($payload['privacy_notice_accepted_at'])->toDateTimeString()
                        : null,
                ],
                BookingWizardStep::DECEASED_DATA => [
                    'deceased_full_name' => $payload['deceased_full_name'],
                    'deceased_date_of_birth' => $payload['deceased_date_of_birth'],
                    'deceased_date_of_death' => $payload['deceased_date_of_death'],
                    'deceased_relationship' => $payload['deceased_relationship'],
                    'deceased_gender' => $payload['deceased_gender'] ?? null,
                    'document_ktp_path' => $payload['document_ktp_path'] ?? null,
                    'document_kk_path' => $payload['document_kk_path'] ?? null,
                    'document_death_certificate_path' => $payload['document_death_certificate_path'] ?? null,
                ],
                BookingWizardStep::PAYMENT => [
                    'payment_method' => $payload['payment_method'],
                    'payment_reference' => $payload['payment_reference'] ?? null,
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
            $required = [BookingWizardStep::LOCATION, BookingWizardStep::CEMETERY, BookingWizardStep::SERVICE_TYPE, BookingWizardStep::SERVICES];
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

        if (! LaunchCityCode::isKnown($city)) {
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

        $privacyAccepted = $payload['privacy_notice_accepted_at'] ?? null;
        if ($privacyAccepted === null || $privacyAccepted === '' || (is_string($privacyAccepted) && trim($privacyAccepted) === '')) {
            $errors['privacy_notice_accepted_at'] = ['Persetujuan privasi harus diberikan.'];
        } else {
            try {
                Carbon::parse($privacyAccepted);
            } catch (\Exception) {
                $errors['privacy_notice_accepted_at'] = ['Timestamp persetujuan privasi tidak valid.'];
            }
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

        $parsedDob = null;
        if ($dob === null || $dob === '') {
            $errors['deceased_date_of_birth'] = ['Tanggal lahir almarhum harus diisi.'];
        } else {
            try {
                $parsedDob = Carbon::parse($dob);
            } catch (\Exception) {
                $errors['deceased_date_of_birth'] = ['Format tanggal lahir tidak valid.'];
            }
        }

        $parsedDod = null;
        if ($dod === null || $dod === '') {
            $errors['deceased_date_of_death'] = ['Tanggal meninggal harus diisi.'];
        } else {
            try {
                $parsedDod = Carbon::parse($dod);
            } catch (\Exception) {
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

        $gender = $payload['deceased_gender'] ?? null;
        if ($gender !== null && $gender !== '' && ! BookingGender::isKnown($gender)) {
            $errors['deceased_gender'] = ['Jenis kelamin tidak valid.'];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validatePayment(array $payload): array
    {
        $errors = [];

        $method = $payload['payment_method'] ?? null;
        if (! is_string($method) || $method === '') {
            $errors['payment_method'] = ['Metode pembayaran harus dipilih.'];
        } elseif (! BookingPaymentMethod::isKnown($method)) {
            $errors['payment_method'] = ['Metode pembayaran tidak valid.'];
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
