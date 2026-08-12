<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\QuoteRenewal;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `QuoteRenewal` — AC6 (tariff amount + source + effective time) and
 * AC7 (no invented late fine).
 *
 * The two tests from the plan (Step 1 and Step 2) plus the throw case.
 * `RefreshDatabase` is required because the Action creates both a `Renewal`
 * and a `RenewalQuote` in a transaction.
 *
 * ---------------------------------------------------------------------------
 * Coordinator's ruling (2026-08-12 handoff, applied here verbatim)
 * ---------------------------------------------------------------------------
 * `cemetery.price_min` → `amount_minor` (as integer minor units, IDR);
 * `cemetery.price_source` → `tariff_source`;
 * `cemetery.price_effective_at` → `tariff_effective_at`.
 * Throw if `price_min` is null. No renewal-tariff table/service exists;
 * this is the documented fallback per AGENTS.md §1.
 *
 * ---------------------------------------------------------------------------
 * AC7 late-fine refusal (G-RATE-01 gate read)
 * ---------------------------------------------------------------------------
 * `late_fine_minor` and `late_fine_basis` are always null in this
 * implementation. `G-RATE-01`'s documented closed behavior is literally "No
 * invented fine" — without a written operator basis, there is no fine to
 * record, and a zero would read as "we checked and it is nothing" rather
 * than "no basis was presented."
 */
final class QuoteRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_late_fine_is_produced_without_a_written_basis(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        $quote = app(QuoteRenewal::class)($grave);

        $this->assertNull($quote->late_fine_minor);
        $this->assertNull($quote->late_fine_basis);
    }

    public function test_a_quote_always_carries_its_tariff_source_and_update_time(): void
    {
        $quote = app(QuoteRenewal::class)(GraveRecord::factory()->create());

        $this->assertNotNull($quote->tariff_source);
        $this->assertNotSame('', $quote->tariff_source);
        $this->assertNotNull($quote->tariff_source_updated_at);
    }

    public function test_a_quote_derives_amount_and_attribution_from_the_cemetery_price_data(): void
    {
        $grave = GraveRecord::factory()->create();
        $cemetery = $grave->cemetery;

        $quote = app(QuoteRenewal::class)($grave);

        $this->assertSame((int) $cemetery->price_min, $quote->amount_minor);
        $this->assertSame($cemetery->price_source, $quote->tariff_source);
        $this->assertEquals($cemetery->price_effective_at, $quote->tariff_effective_at);
        $this->assertSame('IDR', $quote->currency);
    }

    public function test_a_quote_is_created_alongside_a_renewal_in_one_transaction(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        $quote = app(QuoteRenewal::class)($grave);

        $this->assertNotNull($quote->renewal_id);
        $this->assertDatabaseHas('renewals', [
            'id' => $quote->renewal_id,
            'grave_record_id' => $grave->id,
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);
        $this->assertDatabaseHas('renewal_quotes', [
            'id' => $quote->id,
            'renewal_id' => $quote->renewal_id,
        ]);
    }

    public function test_a_quote_without_a_tariff_source_throws(): void
    {
        $cemetery = Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Test Null Price',
            'slug' => 'tpu-test-null-price-'.uniqid(),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'price_min' => null,
        ]);
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no attributable tariff source');

        app(QuoteRenewal::class)($grave);
    }
}
