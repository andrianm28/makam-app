<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Domain\Renewal\Actions\QuoteRenewal;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `OpenRenewal` — the only online write path (AC11 at the seam).
 *
 * The AC11 guard is the database constraint `renewals_grave_period_unique`;
 * application-level pre-checking is a race, not a guard. This Action's job
 * is to attempt the write and translate any constraint violation into the
 * domain exception callers can render as an informative state rather than a
 * 500.
 *
 * Two tests from the plan's Step 1 and Step 2.
 */
final class OpenRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_a_second_renewal_for_one_period_raises_a_domain_exception(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        app(OpenRenewal::class)($grave);

        $this->expectException(DuplicateRenewalPeriodException::class);

        app(OpenRenewal::class)($grave);
    }

    public function test_a_rejected_duplicate_leaves_exactly_one_renewal_and_one_quote(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        app(OpenRenewal::class)($grave);

        try {
            app(OpenRenewal::class)($grave);
        } catch (DuplicateRenewalPeriodException) {
            // expected
        }

        $this->assertSame(1, Renewal::query()->count());
        $this->assertSame(1, RenewalQuote::query()->count());
    }

    /**
     * `AGENTS.md` §Domain and financial invariants: "Never create payment
     * before an accepted quote." Step 5's guard enforces that by requiring
     * `isAcceptedAndUnexpired()`, so the quote this Action persists on the
     * family's acceptance must actually carry the acceptance — otherwise the
     * guard denies every real journey and the passing path is dead again.
     */
    public function test_the_persisted_quote_is_accepted_so_the_payment_guard_can_pass(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        $renewal = app(OpenRenewal::class)($grave);
        $quote = $renewal->quotes()->sole();

        $this->assertNotNull($quote->accepted_at);
        $this->assertTrue($quote->isAcceptedAndUnexpired());
    }

    /**
     * The quote is persisted from the same draft the fee screen rendered, so
     * the amount the family accepted is the amount that reaches the database.
     */
    public function test_the_persisted_amount_matches_the_calculated_quote(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        $draft = app(QuoteRenewal::class)($grave);
        $renewal = app(OpenRenewal::class)($grave);

        $this->assertSame($draft->amountMinor, $renewal->quotes()->sole()->amount_minor);
        $this->assertSame($draft->tariffSource, $renewal->quotes()->sole()->tariff_source);
    }
}
