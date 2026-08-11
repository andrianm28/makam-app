<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Payment\PaymentVerificationStatus;
use App\Platform\Payment\SessionState;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `PaymentVerificationStatus` is `payment_verifications`' own, separate
 * closed list — Wave 1c Append-Correction: "its own state machine, do NOT
 * reuse or extend `SessionState`." This test pins the exact list and that
 * it shares no case value with `SessionState`.
 */
final class PaymentVerificationStatusTest extends TestCase
{
    public function test_the_closed_list_is_exactly_the_three_planned_statuses_in_order(): void
    {
        $this->assertSame(
            ['SUBMITTED', 'VERIFIED', 'REJECTED'],
            PaymentVerificationStatus::values(),
        );
    }

    public function test_every_case_round_trips_through_its_string_value(): void
    {
        foreach (PaymentVerificationStatus::cases() as $case) {
            $this->assertSame($case, PaymentVerificationStatus::from($case->value));
        }
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->assertFalse(PaymentVerificationStatus::isKnown('PAID'));

        $this->expectException(InvalidArgumentException::class);

        PaymentVerificationStatus::assertKnown('PAID');
    }

    public function test_it_shares_no_case_value_with_session_state(): void
    {
        $overlap = array_intersect(PaymentVerificationStatus::values(), SessionState::values());

        $this->assertSame([], $overlap, 'payment_verifications must not reuse a SessionState value — the two state machines are deliberately kept separate.');
    }
}
