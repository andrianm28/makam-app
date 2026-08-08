<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityCode;
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
    public function __invoke(BookingDraft $draft, int $step, array $payload, string $idempotencyKey): BookingDraft
    {
        BookingWizardStep::assertKnown($step);

        if ($step > BookingWizardStep::LAST_IMPLEMENTED) {
            throw new InvalidArgumentException(
                "Step [{$step}] is not implemented yet. Last implemented step: ".BookingWizardStep::LAST_IMPLEMENTED.'.'
            );
        }

        // Idempotency replay: the same key for the same draft means this
        // exact save already happened — return the current state without
        // re-validating or re-bumping the version. See Task 8 for the
        // dedicated replay/conflict test suite this guards.
        if ($draft->last_idempotency_key === $idempotencyKey) {
            return $draft;
        }

        $errors = match ($step) {
            BookingWizardStep::LOCATION => self::validateLocation($payload),
            BookingWizardStep::CEMETERY => self::validateCemetery($payload, $draft),
            BookingWizardStep::SERVICE_TYPE => self::validateServiceType($payload),
            default => [],
        };

        if ($errors !== []) {
            throw new BookingStepValidationException($errors);
        }

        return DB::transaction(function () use ($draft, $step, $payload, $idempotencyKey): BookingDraft {
            $attributes = match ($step) {
                BookingWizardStep::LOCATION => ['city_code' => $payload['city_code']],
                BookingWizardStep::CEMETERY => [
                    'cemetery_id' => $payload['cemetery_id'],
                    'cemetery_package_id' => $payload['cemetery_package_id'] ?? null,
                ],
                BookingWizardStep::SERVICE_TYPE => ['service_type' => $payload['service_type']],
                default => [],
            };

            $completedSteps = $draft->completed_steps;
            if (! in_array($step, $completedSteps, true)) {
                $completedSteps[] = $step;
                sort($completedSteps);
            }

            $draft->fill([
                ...$attributes,
                'completed_steps' => $completedSteps,
                'current_step' => min($step + 1, BookingWizardStep::LAST_IMPLEMENTED + 1),
                'version' => $draft->version + 1,
                'last_idempotency_key' => $idempotencyKey,
            ]);
            $draft->save();

            Audit::record(
                action: 'BOOKING_DRAFT_STEP_SAVED',
                subject: new AuditSubject('booking_draft', $draft->id, $draft->version),
                outcome: AuditOutcome::Allowed,
                actorRef: $draft->user_id,
                actorRole: $draft->user_id !== null ? 'customer' : 'guest',
                source: AuditSource::Api,
            );

            return $draft->refresh();
        });
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
}
