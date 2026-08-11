<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\Actions\RecordServiceDefinitionPriceVersion;
use App\Domain\ServiceCatalog\Exceptions\PriceVersionIsAppendOnlyException;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves the `price_versions` versioning mechanism `service-catalog.md`
 * "Catalog rules" requires ("Price is versioned and snapshot into
 * quote/order") against a real `service_definitions` row. Uses synthetic
 * test-only Rupiah amounts, never real catalogue pricing.
 *
 * `2026_07_26_180400_create_price_versions_table.php`'s own doc block
 * originally explained why NO price was seeded at all. That stopped being
 * true once
 * `2026_07_26_220000_seed_service_definition_dummy_operational_data.php`
 * landed as an explicitly-authorized follow-up: every one of the 12
 * `service_definitions` rows now starts with exactly one DUMMY/placeholder
 * `price_versions` row out of the box (see that migration's own doc block
 * — "not real catalogue pricing" still applies to those seeded amounts too,
 * same as this file's own synthetic ones). Because `RefreshDatabase` runs
 * every migration — including that one — before each test method here,
 * several tests below now explicitly clear a service's pre-existing seeded
 * price first, so they can still isolate "recording a version from a clean
 * slate" the way they always have, independent of that seeded baseline.
 *
 * Every call below passes an explicit `reason:` — `$reason` became a
 * REQUIRED, non-blank parameter of `RecordServiceDefinitionPriceVersion`
 * on 10 Aug 2026 (Task 2R baseline repair), closing a regression this
 * lane's own Task 1 left behind: `SensitiveActions::ACTIONS` already
 * required one at runtime, but the signature still defaulted it to
 * `null`.
 */
final class PriceVersioningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Removes whatever `price_versions` row(s) the dev-data migration
     * already recorded for `$service`, so a test can exercise "recording
     * this service's very first price version" from a genuinely empty
     * history, the same scenario these tests exercised before that
     * migration existed.
     *
     * This is a BUILDER-level mass delete, which fires no model events, so
     * it is unaffected by the append-only guard `PriceVersion::booted()`
     * gained on 09 Aug 2026 (F26) — deliberately, and the same reason the
     * dev-data seed migration writes through `DB::table()`. The guard's
     * scope is the Eloquent instance path; see that model's own doc block.
     */
    private function clearExistingPriceVersions(ServiceDefinition $service): void
    {
        PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->where('priceable_id', $service->id)
            ->delete();
    }

    public function test_recording_a_first_price_version_creates_version_one_as_current(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $this->clearExistingPriceVersions($service);

        $priceVersion = (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: '1500000.00',
            actorReference: 3,
            reason: 'Initial price version recorded for grave digging service test fixture.',
            source: 'Rate card operator uji.',
        );

        $this->assertSame(1, $priceVersion->version_number);
        $this->assertSame('1500000.00', $priceVersion->amount);
        $this->assertSame('IDR', $priceVersion->currency);
        $this->assertTrue($priceVersion->isCurrent());
        $this->assertSame($priceVersion->id, $service->currentPriceVersion()?->id);
    }

    public function test_recording_a_second_price_version_supersedes_the_first_and_becomes_current(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $this->clearExistingPriceVersions($service);

        $first = (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: '1500000.00',
            actorReference: 3,
            reason: 'Recording the first price version ahead of a scheduled supersession test.',
        );

        $second = (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: '1750000.00',
            actorReference: 3,
            reason: 'Recording the second price version to supersede the first.',
        );

        $this->assertSame(1, $first->version_number);
        $this->assertSame(2, $second->version_number);
        $this->assertTrue($second->isCurrent());

        $this->assertFalse($first->refresh()->isCurrent());
        $this->assertNotNull($first->refresh()->superseded_at);

        $current = $service->currentPriceVersion();
        $this->assertNotNull($current);
        $this->assertSame($second->id, $current->id);
        $this->assertSame('1750000.00', $current->amount);
    }

    public function test_two_different_services_version_their_prices_independently(): void
    {
        $graveDigging = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $ambulance = ServiceDefinition::findByCode(ServiceCode::AMBULANCE);
        $this->clearExistingPriceVersions($graveDigging);
        $this->clearExistingPriceVersions($ambulance);

        $recordPrice = new RecordServiceDefinitionPriceVersion;
        $recordPrice(
            serviceDefinition: $graveDigging,
            amount: '1500000.00',
            actorReference: 3,
            reason: 'Recording grave digging price to prove per-service version isolation.',
        );
        $ambulancePrice = $recordPrice(
            serviceDefinition: $ambulance,
            amount: '800000.00',
            actorReference: 3,
            reason: 'Recording ambulance price to prove per-service version isolation.',
        );

        $this->assertSame(1, $ambulancePrice->version_number);
        $this->assertSame($ambulance->id, $ambulancePrice->priceable_id);
    }

    /**
     * Replaces this file's previous
     * `test_a_service_with_no_recorded_price_has_no_current_price_version`
     * (which used to assert LIVE_STREAMING had no recorded price at all).
     * That assertion is no longer true for ANY of the 12 known codes once
     * the dev-data migration runs — see this class's own doc block. This
     * test documents the new reality directly instead of asserting a
     * now-false negative.
     */
    public function test_every_known_service_has_a_current_dummy_price_version_out_of_the_box(): void
    {
        foreach (ServiceCode::KNOWN_CODES as $code) {
            $service = ServiceDefinition::findByCode($code);
            $current = $service->currentPriceVersion();

            $this->assertNotNull($current, "[{$code}] should have a current price version from the dev-data migration.");
            $this->assertTrue($current->isCurrent(), "[{$code}] current price version should be unsuperseded.");
            $this->assertGreaterThan(0.0, (float) $current->amount, "[{$code}] price amount should be positive.");
        }
    }

    public function test_a_non_positive_amount_is_rejected(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::CATERING);

        $this->expectException(InvalidArgumentException::class);

        (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: '0',
            actorReference: 3,
            reason: 'Attempting to record a non-positive amount to prove the guard rejects it.',
        );
    }

    public function test_a_non_numeric_amount_is_rejected(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::CATERING);

        $this->expectException(InvalidArgumentException::class);

        (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: 'not-a-number',
            actorReference: 3,
            reason: 'Attempting to record a non-numeric amount to prove the guard rejects it.',
        );
    }

    // ------------------------------------------------------------------
    // F20 + F26 + F7 — added 09 Aug 2026 by the ServiceCatalog Superpowers
    // retrofit fix wave (Rounds 2). AC3's snapshot property, the
    // append-only contract it rests on, and the money-shape guard in front
    // of the `decimal(12,2)` column.
    // ------------------------------------------------------------------

    /**
     * F20. AC3's actual snapshot PROPERTY, which no test asserted: the
     * supersede CHAIN was proven, but `$first->amount` was never re-read
     * after supersession. This is the one property a quote referencing a
     * price version depends on.
     */
    public function test_a_superseded_price_version_still_holds_its_original_amount(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $this->clearExistingPriceVersions($service);

        $recordPrice = new RecordServiceDefinitionPriceVersion;

        $first = $recordPrice(
            serviceDefinition: $service,
            amount: '1500000.00',
            actorReference: 3,
            reason: 'Recording version 1 to prove a superseded version keeps its original amount.',
        );
        $recordPrice(
            serviceDefinition: $service,
            amount: '1750000.00',
            actorReference: 3,
            reason: 'Recording version 2 to supersede version 1 for the snapshot-amount test.',
        );
        $recordPrice(
            serviceDefinition: $service,
            amount: '2000000.00',
            actorReference: 3,
            reason: 'Recording version 3 to supersede version 2 for the snapshot-amount test.',
        );

        $this->assertSame('1500000.00', $first->refresh()->amount);
        $this->assertNotNull($first->superseded_at);
        $this->assertDatabaseHas('price_versions', ['id' => $first->id, 'version_number' => 1]);
    }

    /**
     * F26. `price_versions`'s own class doc block and
     * `2026_07_26_180400_create_price_versions_table.php:55-56` both claimed
     * "append-only ... never updated afterward except to stamp
     * `superseded_at`" / "never delete or renumber a version after the
     * fact", while the model defined no `booted()` of any kind — so
     * `$old->forceFill(['amount' => '1.00'])->save()` and `$old->delete()`
     * both simply worked, which is why F20's property above was not merely
     * untested but unenforceable.
     */
    public function test_a_recorded_price_version_can_never_be_rewritten_or_deleted(): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $this->clearExistingPriceVersions($service);

        $recordPrice = new RecordServiceDefinitionPriceVersion;
        $first = $recordPrice(
            serviceDefinition: $service,
            amount: '1500000.00',
            actorReference: 3,
            reason: 'Recording version 1 to prove append-only immutability once superseded.',
        );
        $second = $recordPrice(
            serviceDefinition: $service,
            amount: '1750000.00',
            actorReference: 3,
            reason: 'Recording version 2 to prove append-only immutability of the current version.',
        );

        // The legal supersede stamp already happened above — proof the
        // guard is not blanket-blocking the one permitted update.
        $this->assertNotNull($first->refresh()->superseded_at);
        $this->assertTrue($second->refresh()->isCurrent());

        $firstId = $first->id;
        $secondId = $second->id;

        // Each attempt loads its own fresh instance, so a rejected write can
        // never leave dirty attributes behind that make the NEXT attempt
        // throw for the wrong reason.
        $attempts = [
            'rewrite a superseded amount' => fn () => PriceVersion::query()->findOrFail($firstId)
                ->forceFill(['amount' => '1.00'])->save(),
            'renumber a superseded version' => fn () => PriceVersion::query()->findOrFail($firstId)
                ->forceFill(['version_number' => 99])->save(),
            're-stamp an already-superseded row' => fn () => PriceVersion::query()->findOrFail($firstId)
                ->forceFill(['superseded_at' => now()])->save(),
            'un-supersede a superseded row' => fn () => PriceVersion::query()->findOrFail($firstId)
                ->forceFill(['superseded_at' => null])->save(),
            'rewrite the current amount' => fn () => PriceVersion::query()->findOrFail($secondId)
                ->forceFill(['amount' => '9.99'])->save(),
            'rewrite a row through the model even with no change at all' => fn () => PriceVersion::query()
                ->findOrFail($secondId)->save(),
            'delete a superseded version' => fn () => PriceVersion::query()->findOrFail($firstId)->delete(),
            'delete the current version' => fn () => PriceVersion::query()->findOrFail($secondId)->delete(),
        ];

        foreach ($attempts as $description => $attempt) {
            try {
                $attempt();
                $this->fail("Attempting to {$description} should have thrown.");
            } catch (PriceVersionIsAppendOnlyException) {
                // expected
            }
        }

        $this->assertDatabaseHas('price_versions', ['id' => $first->id, 'amount' => '1500000.00', 'version_number' => 1]);
        $this->assertDatabaseHas('price_versions', ['id' => $second->id, 'amount' => '1750000.00', 'version_number' => 2]);
        $this->assertSame('1500000.00', $first->fresh()->amount);
        $this->assertSame('1750000.00', $second->fresh()->amount);
        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertNull($second->fresh()->superseded_at);
    }

    /**
     * F7. `is_numeric($amount) || (float) $amount <= 0` was the only guard
     * in front of a `decimal(12,2)` column. Each value below changed a MONEY
     * value with no error and no trace (the audit `metadata` carries only a
     * sentence, never the amount):
     *
     * - `'100.999'` was accepted and silently rounded by the database to
     *   `101.00` — a stored amount differing from what the caller passed;
     * - `' 5000'` and `'1e9'` are `is_numeric()`-true but are not the
     *   plain decimal string the column's domain describes;
     * - `'99999999999.99'` (11 integer digits) overflowed `decimal(12,2)`
     *   and surfaced as a raw `QueryException`, not a domain error.
     *
     * @return list<array{string}>
     */
    public static function rejectedAmountProvider(): array
    {
        return [
            'scale greater than 2 (silently rounded by the DB)' => ['100.999'],
            'leading whitespace' => [' 5000'],
            'exponent notation' => ['1e9'],
            'eleven integer digits (decimal(12,2) overflow)' => ['99999999999.99'],
            'zero' => ['0'],
            'zero with a scale' => ['0.00'],
            'negative' => ['-1'],
            'not a number at all' => ['abc'],
            'blank' => [''],
        ];
    }

    #[DataProvider('rejectedAmountProvider')]
    public function test_an_amount_outside_the_decimal_12_2_domain_is_rejected(string $amount): void
    {
        $service = ServiceDefinition::findByCode(ServiceCode::CATERING);

        $this->expectException(InvalidArgumentException::class);

        (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: $amount,
            actorReference: 3,
            reason: 'Attempting to record an amount outside the decimal(12,2) domain to prove the guard rejects it.',
        );
    }

    /**
     * @return list<array{string, string}>
     */
    public static function acceptedAmountProvider(): array
    {
        return [
            'two fractional digits' => ['350000.00', '350000.00'],
            'no fractional part' => ['350000', '350000.00'],
            'one fractional digit' => ['350000.5', '350000.50'],
            'ten integer digits (the column maximum)' => ['9999999999.99', '9999999999.99'],
        ];
    }

    #[DataProvider('acceptedAmountProvider')]
    public function test_an_amount_inside_the_decimal_12_2_domain_is_accepted_and_stored_exactly(
        string $amount,
        string $expectedStored,
    ): void {
        $service = ServiceDefinition::findByCode(ServiceCode::CATERING);
        $this->clearExistingPriceVersions($service);

        $priceVersion = (new RecordServiceDefinitionPriceVersion)(
            serviceDefinition: $service,
            amount: $amount,
            actorReference: 3,
            reason: 'Recording an amount inside the decimal(12,2) domain to prove it is stored exactly as given.',
        );

        $this->assertSame($expectedStored, $priceVersion->refresh()->amount);
        $this->assertDatabaseHas('price_versions', ['id' => $priceVersion->id, 'version_number' => 1]);
    }
}
