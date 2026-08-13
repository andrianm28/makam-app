<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Support\Design\StatusIntent;
use PHPUnit\Framework\TestCase;

final class OrderTransitionTest extends TestCase
{
    public function test_the_canonical_happy_path_is_allowed_end_to_end(): void
    {
        $chain = [
            OrderStatus::MASUK,
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::DIBAYAR,
            OrderStatus::DIPROSES,
            OrderStatus::SELESAI,
        ];

        for ($i = 0; $i < count($chain) - 1; $i++) {
            self::assertTrue(
                OrderTransition::isAllowed($chain[$i], $chain[$i + 1]),
                "{$chain[$i]->value} -> {$chain[$i + 1]->value} should be allowed"
            );
        }
    }

    public function test_no_backward_transition_exists_anywhere_in_the_graph(): void
    {
        $order = OrderStatus::forwardOrder();

        foreach ($order as $fromIndex => $from) {
            foreach ($order as $toIndex => $to) {
                if ($toIndex < $fromIndex && OrderTransition::isAllowed($from, $to)) {
                    self::fail("Backward transition {$from->value} -> {$to->value} is allowed");
                }
            }
        }

        self::assertTrue(true);
    }

    public function test_manual_verification_sits_between_awaiting_payment_and_paid(): void
    {
        self::assertTrue(OrderTransition::isAllowed(
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN
        ));
        self::assertTrue(OrderTransition::isAllowed(
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
            OrderStatus::DIBAYAR
        ));
    }

    public function test_a_rejected_verification_cannot_send_the_order_backward(): void
    {
        self::assertFalse(OrderTransition::isAllowed(
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
            OrderStatus::MENUNGGU_PEMBAYARAN
        ));
    }

    public function test_nothing_terminal_is_reachable_after_paid(): void
    {
        foreach ([OrderStatus::DIBAYAR, OrderStatus::DIPROSES, OrderStatus::SELESAI] as $from) {
            foreach ([OrderStatus::DITOLAK, OrderStatus::DIBATALKAN, OrderStatus::KEDALUWARSA] as $to) {
                self::assertFalse(
                    OrderTransition::isAllowed($from, $to),
                    "{$from->value} -> {$to->value} must not be allowed; use a compensating financial action"
                );
            }
        }
    }

    public function test_rejection_is_only_reachable_from_the_pre_quote_states(): void
    {
        foreach ([OrderStatus::MASUK, OrderStatus::DIVERIFIKASI, OrderStatus::MENUNGGU_KETERSEDIAAN] as $from) {
            self::assertTrue(OrderTransition::isAllowed($from, OrderStatus::DITOLAK));
        }

        foreach ([OrderStatus::PENAWARAN_TERKIRIM, OrderStatus::DISETUJUI_PEMESAN, OrderStatus::MENUNGGU_PEMBAYARAN] as $from) {
            self::assertFalse(OrderTransition::isAllowed($from, OrderStatus::DITOLAK));
        }
    }

    public function test_expiry_is_reachable_only_where_a_window_can_lapse(): void
    {
        foreach ([OrderStatus::PENAWARAN_TERKIRIM, OrderStatus::DISETUJUI_PEMESAN, OrderStatus::MENUNGGU_PEMBAYARAN] as $from) {
            self::assertTrue(OrderTransition::isAllowed($from, OrderStatus::KEDALUWARSA));
        }

        self::assertFalse(
            OrderTransition::isAllowed(OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN, OrderStatus::KEDALUWARSA),
            'Submitted evidence must be decided, never left to lapse'
        );
    }

    public function test_terminal_states_are_absorbing(): void
    {
        foreach ([OrderStatus::SELESAI, OrderStatus::DITOLAK, OrderStatus::DIBATALKAN, OrderStatus::KEDALUWARSA] as $terminal) {
            self::assertTrue(OrderTransition::isTerminal($terminal));

            foreach (OrderStatus::cases() as $to) {
                self::assertFalse(
                    OrderTransition::isAllowed($terminal, $to),
                    "Terminal {$terminal->value} must have no outgoing edge, found -> {$to->value}"
                );
            }
        }
    }

    public function test_assert_allowed_throws_on_an_illegal_edge(): void
    {
        $this->expectException(IllegalOrderTransitionException::class);

        OrderTransition::assertAllowed(OrderStatus::MASUK, OrderStatus::DIBAYAR);
    }

    public function test_only_rejection_demands_a_reason(): void
    {
        self::assertTrue(OrderStatus::DITOLAK->requiresReason());

        foreach (OrderStatus::cases() as $status) {
            if ($status !== OrderStatus::DITOLAK) {
                self::assertFalse($status->requiresReason(), "{$status->value} should not demand a reason");
            }
        }
    }

    public function test_every_status_is_renderable_through_status_intent(): void
    {
        $known = StatusIntent::knownStatuses(StatusIntent::FAMILY_ORDER_LIFECYCLE);

        foreach (OrderStatus::cases() as $status) {
            self::assertContains(
                $status->value,
                $known,
                "{$status->value} has no StatusIntent entry in the order-lifecycle family"
            );

            self::assertNotSame('', StatusIntent::intent(
                $status->value,
                StatusIntent::FAMILY_ORDER_LIFECYCLE
            ));
        }
    }

    public function test_paid_and_completed_stay_distinct_intents(): void
    {
        // design-system.md 3.7: DIBAYAR != SELESAI. Guards against a future
        // refactor merging them into one "done" badge, which AC11 forbids.
        self::assertNotSame(
            StatusIntent::intent(OrderStatus::DIBAYAR->value, StatusIntent::FAMILY_ORDER_LIFECYCLE)
                .'|'.StatusIntent::icon(OrderStatus::DIBAYAR->value, StatusIntent::FAMILY_ORDER_LIFECYCLE),
            StatusIntent::intent(OrderStatus::SELESAI->value, StatusIntent::FAMILY_ORDER_LIFECYCLE)
                .'|'.StatusIntent::icon(OrderStatus::SELESAI->value, StatusIntent::FAMILY_ORDER_LIFECYCLE),
        );
    }
}
