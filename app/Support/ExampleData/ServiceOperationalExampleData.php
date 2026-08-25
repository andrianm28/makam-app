<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place the SERVICE catalogue's EXAMPLE operational data
 * (fulfilment owner, scheduling/confirmation flags, dummy prices) is
 * produced for the seeded `service_definitions` rows. The dummy-data
 * migration materializes from here, so a catalogue change is a single
 * edit — never interlocking literal copies of the same rows.
 *
 * ---------------------------------------------------------------------------
 * SEMANTIC vs PROCEDURAL — read this before changing anything below
 * ---------------------------------------------------------------------------
 * - `operationalDefaults()` is DOMAIN SEMANTICS, not example data: WHICH
 *   actor fulfils each service, whether it needs scheduling, and whether
 *   it needs manual confirmation are rules from the canonical service
 *   catalogue's meaning (funeral services are vendor-fulfilled; document
 *   processing is platform-fulfilled) — the algorithm itself. These values
 *   intentionally stay literal: a deterministic formula for them would
 *   hide a catalogue decision inside arithmetic.
 * - `dummyPrices()` is PROCEDURAL EXAMPLE DATA: flat placeholder amounts
 *   derived deterministically from the catalogue-code position, always
 *   labelled with a clearly-fictional source note ("Estimasi internal
 *   (data contoh)") so they can never be mistaken for real operator or
 *   vendor pricing — same discipline as `VendorExampleData`/
 *   `CemeteryExampleData`. No downstream code should treat them as
 *   authoritative business data.
 */
final class ServiceOperationalExampleData
{
    /**
     * Domain semantics, not data: WHICH actor fulfils each service, whether
     * it needs scheduling, and whether it needs manual confirmation. These
     * rules come from the canonical service catalogue's meaning (funeral
     * services are vendor-fulfilled; document processing is platform-
     * fulfilled) and are the algorithm, not example rows.
     *
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function operationalDefaults(): array
    {
        return [
            ServiceCode::DOCUMENT_PROCESSING => [FulfillmentOwner::PLATFORM, false, true],
            ServiceCode::GRAVE_DIGGING => [FulfillmentOwner::CEMETERY_OPERATOR, false, true],
            ServiceCode::AMBULANCE => [FulfillmentOwner::VENDOR, true, false],
            ServiceCode::FUNERAL_HOME => [FulfillmentOwner::VENDOR, false, false],
            ServiceCode::HEARSE => [FulfillmentOwner::VENDOR, true, false],
            ServiceCode::TENT_AND_CHAIRS => [FulfillmentOwner::VENDOR, true, false],
            ServiceCode::SOUND_SYSTEM => [FulfillmentOwner::VENDOR, true, false],
            ServiceCode::FLOWERS => [FulfillmentOwner::VENDOR, false, false],
            ServiceCode::GRAVESTONE => [FulfillmentOwner::VENDOR, false, true],
            ServiceCode::DOCUMENTATION => [FulfillmentOwner::VENDOR, false, false],
            ServiceCode::CATERING => [FulfillmentOwner::VENDOR, true, false],
            ServiceCode::LIVE_STREAMING => [FulfillmentOwner::VENDOR, true, false],
        ];
    }

    /**
     * Dummy flat package amounts, derived deterministically from the code.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dummyPrices(): array
    {
        $prices = [];
        foreach (array_values(ServiceCode::KNOWN_CODES) as $i => $code) {
            // Deterministic: base 350_000 + index * 200_000, in minor units.
            $amountMinor = (350_000 + $i * 200_000) * 100;
            $prices[$code] = [
                sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100),
                'Estimasi internal (data contoh)',
            ];
        }

        return $prices;
    }

    public static function seed(): void
    {
        $now = now();

        foreach (self::operationalDefaults() as $code => [$owner, $schedule, $manual]) {
            DB::table('service_definitions')->where('code', $code)->update([
                'fulfillment_owner' => $owner,
                'requires_schedule' => $schedule,
                'requires_manual_confirmation' => $manual,
                'updated_at' => $now,
            ]);
        }

        foreach (self::dummyPrices() as $code => [$amount, $source]) {
            $service = DB::table('service_definitions')->where('code', $code)->first();
            if ($service === null) {
                continue;
            }

            // Exact column shape of `2026_07_26_180400_create_price_versions_table.php`
            // (verified: version_number unsignedInteger, effective_from
            // timestamp, recorded_by string — NOT created_at/updated_at/UUID id).
            DB::table('price_versions')->insert([
                'priceable_type' => ServiceDefinition::class,
                'priceable_id' => $service->id,
                'version_number' => 1,
                'amount' => $amount,
                'currency' => 'IDR',
                'source' => $source,
                'effective_from' => $now,
                'superseded_at' => null,
                'recorded_by' => 'dev-seed-migration',
            ]);
        }
    }
}
