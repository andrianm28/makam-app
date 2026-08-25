<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Payment\PaymentReversalType;
use App\Platform\Payment\PaymentVerificationStatus;
use App\Platform\Payment\SessionState;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `PaymentReversalType` is `payment_reversals`' own, separate closed list
 * — Wave 1d Append-Correction: exactly the two cases the plan names audit
 * actions for (`Refund`, `Chargeback`), deliberately no third "Reversal"
 * case. This test pins the exact list and that it shares no case value
 * with `PaymentVerificationStatus`/`SessionState`.
 */
final class PaymentReversalTypeTest extends TestCase
{
    public function test_the_closed_list_is_exactly_the_two_planned_types_in_order(): void
    {
        $this->assertSame(
            ['REFUND', 'CHARGEBACK'],
            PaymentReversalType::values(),
        );
    }

    public function test_every_case_round_trips_through_its_string_value(): void
    {
        foreach (PaymentReversalType::cases() as $case) {
            $this->assertSame($case, PaymentReversalType::from($case->value));
        }
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        $this->assertFalse(PaymentReversalType::isKnown('REVERSAL'));

        $this->expectException(InvalidArgumentException::class);

        PaymentReversalType::assertKnown('REVERSAL');
    }

    public function test_it_shares_no_case_value_with_payment_verification_status_or_session_state(): void
    {
        $overlapWithVerification = array_intersect(PaymentReversalType::values(), PaymentVerificationStatus::values());
        $overlapWithSession = array_intersect(PaymentReversalType::values(), SessionState::values());

        $this->assertSame([], $overlapWithVerification, 'payment_reversals must not reuse a PaymentVerificationStatus value.');
        $this->assertSame([], $overlapWithSession, 'payment_reversals must not reuse a SessionState value.');
    }
}
