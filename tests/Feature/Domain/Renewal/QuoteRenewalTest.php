<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\QuoteRenewal;
use App\Platform\FinancialLedger\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `QuoteRenewal` — AC6 (tariff amount + source + effective time) and
 * AC7 (no invented late fine).
 *
 * ---------------------------------------------------------------------------
 * Coordinator's ruling (2026-08-12 handoff, applied here verbatim)
 * ---------------------------------------------------------------------------
 * `cemetery.price_min` → the quoted amount; `cemetery.price_source` → the
 * tariff source; `cemetery.price_effective_at` → the effective time. Throw if
 * `price_min` is null. No renewal-tariff table/service exists; this is the
 * documented fallback per AGENTS.md §1.
 *
 * ---------------------------------------------------------------------------
 * Two properties this file exists to pin
 * ---------------------------------------------------------------------------
 * 1. The Action WRITES NOTHING. Step 4 is an anonymous GET and calls this
 *    Action on every render; a persisting quote let any visitor create rows
 *    and squat the AC11 business key.
 * 2. The amount is a MINOR-unit conversion of a MAJOR-unit decimal column, via
 *    `Money::fromDecimal()`. The regression this guards against quoted
 *    Rp 40.000 for a Rp 4.000.000 tariff.
 */
final class QuoteRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_late_fine_is_produced_without_a_written_basis(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        $draft = app(QuoteRenewal::class)($grave);

        $this->assertNull($draft->lateFineMinor);
        $this->assertNull($draft->lateFineBasis);
        $this->assertFalse($draft->hasLateFine());
        $this->assertNull($draft->lateFineAsMoney());
    }

    public function test_a_quote_always_carries_its_tariff_source_and_effective_time(): void
    {
        $draft = app(QuoteRenewal::class)(GraveRecord::factory()->create());

        $this->assertNotSame('', $draft->tariffSource);
        $this->assertNotNull($draft->tariffEffectiveAt);
    }

    /**
     * The hundredfold-understatement regression, pinned against the real
     * seeded price data rather than a hand-built fixture.
     *
     * `cemeteries.price_min` is `decimal:2` in MAJOR units — the string
     * `'4000000.00'` means four million rupiah. `amount_minor` is in MINOR
     * units. A `(int)` cast of that string yields `4000000`, which read as
     * minor units is Rp 40.000: the fee screen would quote the family one
     * hundredth of the real tariff. `Money::fromDecimal()` is the conversion
     * seam; this test fails if anyone reintroduces the cast.
     */
    public function test_the_quoted_amount_is_the_cemetery_price_converted_to_minor_units(): void
    {
        $grave = GraveRecord::factory()->create();
        $cemetery = $grave->cemetery;

        $draft = app(QuoteRenewal::class)($grave);

        $expectedMinor = Money::fromDecimal((string) $cemetery->price_min);

        $this->assertSame($expectedMinor, $draft->amountMinor);
        $this->assertNotSame(
            (int) $cemetery->price_min,
            $draft->amountMinor,
            'A decimal major-unit price must not be read as minor units.'
        );
        $this->assertSame(
            (int) round(((float) $cemetery->price_min) * 100),
            $draft->amountMinor
        );
    }

    public function test_a_quote_derives_its_attribution_from_the_cemetery_price_data(): void
    {
        $grave = GraveRecord::factory()->create();
        $cemetery = $grave->cemetery;

        $draft = app(QuoteRenewal::class)($grave);

        $this->assertSame($cemetery->price_source, $draft->tariffSource);
        $this->assertEquals($cemetery->price_effective_at, $draft->tariffEffectiveAt);
        $this->assertSame('IDR', $draft->currency);
    }

    /**
     * Step 4 renders through this Action on every GET. If it persists, a
     * crawler creates renewals.
     */
    public function test_quoting_persists_nothing(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        app(QuoteRenewal::class)($grave);
        app(QuoteRenewal::class)($grave);
        app(QuoteRenewal::class)($grave);

        $this->assertDatabaseCount('renewals', 0);
        $this->assertDatabaseCount('renewal_quotes', 0);
    }

    public function test_a_quote_without_a_tariff_amount_throws(): void
    {
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWith(['price_min' => null])->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no attributable tariff source');

        app(QuoteRenewal::class)($grave);
    }

    /**
     * `renewal_quotes.tariff_source` is NOT NULL, and the plan's own rule is
     * that a quote with no attributable source is not a quote. A price with no
     * `price_source` must therefore refuse, not quote anonymously.
     */
    public function test_a_price_without_a_source_attribution_throws(): void
    {
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWith([
                'price_min' => '1500000.00',
                'price_source' => null,
            ])->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no source attribution');

        app(QuoteRenewal::class)($grave);
    }

    /**
     * `renewal_quotes.tariff_effective_at` is NOT NULL on PostgreSQL 18. A
     * priced-but-undated cemetery must refuse here, not reach the insert and
     * fail as an unhandled integrity error the screen cannot render.
     */
    public function test_a_price_without_an_effective_time_throws(): void
    {
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWith([
                'price_min' => '1500000.00',
                'price_source' => 'Perda TPU 2026',
                'price_effective_at' => null,
            ])->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no effective time');

        app(QuoteRenewal::class)($grave);
    }

    /**
     * `grave_records.due_date` is nullable and the public search does not
     * filter on it, so a published grave with no due date is reachable from
     * step 3. A renewal is a settlement of a specific period — `target_due_period`
     * is the grave's due date — so a grave with none has no period to quote.
     * Accepting one previously crashed with a fatal `Error` when the NOT NULL
     * insert violated and the catch handler dereferenced the null date.
     */
    public function test_a_grave_without_a_due_date_throws(): void
    {
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWith([
                'price_min' => '1500000.00',
                'price_source' => 'Perda TPU 2026',
                'price_effective_at' => now(),
            ])->id,
            'due_date' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no due date');

        app(QuoteRenewal::class)($grave);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function cemeteryWith(array $attributes): Cemetery
    {
        return Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Test Price',
            'slug' => 'tpu-test-price-'.uniqid(),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            ...$attributes,
        ]);
    }
}
