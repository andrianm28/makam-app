<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Validates and persists one wizard step's payload onto an existing
 * `BookingDraft`, server-side and authoritative regardless of what the
 * client already checked — `booking-and-order-orchestration` AC3.
 * Idempotent and versioned — AC2; see Task 8 for the idempotency-replay
 * and version-conflict tests this Action's contract must satisfy.
 *
 * Only steps 1-3 are implemented by Task 6; step 4 (service selection) is
 * added by Task 7 in the same `match` below — this Action is one module
 * with one responsibility ("persist a validated step onto a draft"), not
 * five near-duplicate per-step classes, so later tasks extend this file
 * rather than creating siblings.
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

        // Step 5 is READ-ONLY — a summary of steps 1-4, with no write action
        // and no payload of its own. It is inside the implemented boundary
        // (5 === LAST_IMPLEMENTED) so the guard above cannot catch it, and
        // without this one it would fall through both `match`es' `default`
        // arms: no validation, no attributes, but a version bump and
        // `current_step = 6` — a step the Blade view has no branch for,
        // permanently stranding the draft with no way forward or back.
        // Rejected here in the same boundary-check style rather than given a
        // silent no-op arm, because no valid caller exists.
        if ($step === BookingWizardStep::SUMMARY) {
            throw new InvalidArgumentException(
                'Step ['.BookingWizardStep::SUMMARY.'] (Ringkasan Pesanan) is read-only and has no save action.'
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
     * Only the IMMEDIATELY preceding step is required, not the whole prefix:
     * every step's own save appends to `completed_steps`, so reaching step N
     * legitimately already implies 1..N-1 were each saved in turn, and
     * checking only N-1 keeps this rule a single, cheap, obviously-correct
     * boundary condition (the same shape as the two `InvalidArgumentException`
     * boundary guards above). Step 1 has no predecessor and is always
     * allowed. Re-saving an already-completed step (back-navigation, AC11) is
     * always allowed for the same reason — its predecessor is by then
     * complete.
     *
     * @return array<string, list<string>>
     */
    private static function validateStepSequencing(int $step, BookingDraft $draft): array
    {
        if ($step === BookingWizardStep::LOCATION) {
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
}
