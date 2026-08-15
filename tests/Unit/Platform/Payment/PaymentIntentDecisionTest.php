<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Payment\PaymentIntentDecision;
use Tests\TestCase;

/**
 * `PaymentIntentDecision` is the closed list of `payment_intents.decision`
 * values. Wave 1b ruling 1b-L3-01 shipped the enum with a single `Denied`
 * case and a Postgres CHECK admitting only `'denied'`; the online-payment
 * gateway task widens both deliberately (the CHECK is generated from
 * `::values()` in fresh migrations, and the additive
 * `2026_08_14_100000_allow_online_payment_decision` migration re-creates it
 * on already-applied databases). `Allowed` is constructible ONLY because a
 * real pass path exists now: `G-PAY-01` open in dev admits online session
 * creation (see `Models\PaymentSession`'s gate-conditional `creating` hook).
 */
final class PaymentIntentDecisionTest extends TestCase
{
    public function test_the_closed_list_is_denied_then_allowed_in_that_order(): void
    {
        $this->assertSame(['denied', 'allowed'], PaymentIntentDecision::values());
    }

    public function test_allowed_is_a_known_decision(): void
    {
        // `::values()` is a list of strings, so the case's string value is
        // what is asserted to be in it.
        $this->assertContains(PaymentIntentDecision::Allowed->value, PaymentIntentDecision::values());
    }

    public function test_every_case_round_trips_through_its_string_value(): void
    {
        foreach (PaymentIntentDecision::cases() as $case) {
            $this->assertSame($case, PaymentIntentDecision::from($case->value));
        }
    }
}
