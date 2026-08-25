<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Payment\SessionState;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `SessionState` is a CLOSED list — the plan's §File Structure names it
 * `CREATED|AWAITING_PAYMENT|PAID|FAILED|EXPIRED|REFUNDED`, and adds the
 * scope caveat this test exists to pin: "payment-session scope, NOT order
 * scope". No Indonesian order/invoice state (`DIBAYAR`,
 * `MENUNGGU_VERIFIKASI_PEMBAYARAN`) belongs on this enum — those live on the
 * order aggregate, which this module never touches
 * (`AGENTS.md` §Domain and financial invariants: "Service payment and
 * fulfillment are separate states").
 */
final class SessionStateTest extends TestCase
{
    public function test_the_closed_list_is_exactly_the_six_planned_states_in_order(): void
    {
        $this->assertSame(
            ['CREATED', 'AWAITING_PAYMENT', 'PAID', 'FAILED', 'EXPIRED', 'REFUNDED'],
            SessionState::values(),
        );
    }

    public function test_every_case_round_trips_through_its_string_value(): void
    {
        foreach (SessionState::cases() as $case) {
            $this->assertSame($case, SessionState::from($case->value));
        }
    }

    public function test_an_order_scoped_state_is_not_a_session_state(): void
    {
        $this->assertFalse(SessionState::isKnown('DIBAYAR'));
        $this->assertFalse(SessionState::isKnown('MENUNGGU_VERIFIKASI_PEMBAYARAN'));

        $this->expectException(InvalidArgumentException::class);

        SessionState::assertKnown('DIBAYAR');
    }
}
