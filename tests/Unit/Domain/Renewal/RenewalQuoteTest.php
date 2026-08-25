<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Renewal;

use App\Domain\Renewal\Models\RenewalQuote;
use App\Platform\FinancialLedger\Money;
use Tests\TestCase;

/**
 * `RenewalQuote`'s two pure model methods — `amountAsMoney()` and
 * `isAcceptedAndUnexpired()`. No `RefreshDatabase`: neither method touches a
 * table, so these are plain in-memory attribute assertions, the same shape
 * `GraveRecordAccessModeTest` uses for a closed list.
 *
 * ---------------------------------------------------------------------------
 * Why this file exists — fix round 1, F4
 * ---------------------------------------------------------------------------
 * `isAcceptedAndUnexpired()`'s permissive null-`expires_at` branch shipped
 * with zero coverage in the original commit. Task 5's payment-opening guard
 * (`Actions\GuardRenewalPaymentOpening`, not built yet) depends on this
 * predicate to decide whether a payment session may open, so an untested
 * branch here was a live risk for a later, financially-consequential task —
 * see `RenewalQuote`'s own doc block. All four combinations of
 * "accepted or not" × "never expires, not yet expired, or expired" are
 * covered below.
 */
final class RenewalQuoteTest extends TestCase
{
    public function test_a_quote_that_was_never_accepted_is_not_accepted_and_unexpired(): void
    {
        $quote = new RenewalQuote([
            'amount_minor' => 1_000_000,
            'accepted_at' => null,
            'expires_at' => null,
        ]);

        $this->assertFalse($quote->isAcceptedAndUnexpired());
    }

    /**
     * The locked design decision this test exists to pin down: a `null`
     * `expires_at` means "no expiry policy recorded", never "already
     * expired". `RenewalQuote`'s own doc block states this explicitly.
     */
    public function test_an_accepted_quote_with_no_expiry_recorded_is_accepted_and_unexpired(): void
    {
        $quote = new RenewalQuote([
            'amount_minor' => 1_000_000,
            'accepted_at' => now(),
            'expires_at' => null,
        ]);

        $this->assertTrue($quote->isAcceptedAndUnexpired());
    }

    public function test_an_accepted_quote_with_a_future_expiry_is_accepted_and_unexpired(): void
    {
        $quote = new RenewalQuote([
            'amount_minor' => 1_000_000,
            'accepted_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->assertTrue($quote->isAcceptedAndUnexpired());
    }

    public function test_an_accepted_quote_with_a_past_expiry_is_not_accepted_and_unexpired(): void
    {
        $quote = new RenewalQuote([
            'amount_minor' => 1_000_000,
            'accepted_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($quote->isAcceptedAndUnexpired());
    }

    /**
     * Fix round 1, F5 — `amountAsMoney()` must hand the stored value to
     * `Money` without an intervening `(int)` cast that would defeat
     * `Money::__construct()`'s own type assertion. The `'integer'` cast on
     * `amount_minor` already guarantees a genuine `int` reaches this method
     * on the ordinary path, so this test asserts the value round-trips
     * correctly through `Money` rather than re-proving `Money`'s own
     * constructor contract, which `MoneyTest` already covers.
     */
    public function test_amount_as_money_round_trips_the_stored_minor_units(): void
    {
        $quote = new RenewalQuote(['amount_minor' => 1_250_000]);

        $money = $quote->amountAsMoney();

        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame(1_250_000, $money->toMinorInt());
    }
}
