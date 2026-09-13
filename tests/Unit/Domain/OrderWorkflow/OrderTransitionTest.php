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
        foreach ([
            OrderStatus::SELESAI,
            OrderStatus::DITOLAK,
            OrderStatus::DITOLAK_SETELAH_BAYAR,
            OrderStatus::DIBATALKAN,
            OrderStatus::KEDALUWARSA,
        ] as $terminal) {
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

    /**
     * Both refusals demand a reason, and nothing else does.
     *
     * `DITOLAK_SETELAH_BAYAR` joined this on 13 Sep 2026 (Stage R1) — it is
     * the refusal of an order whose money has already arrived, and the reason
     * is the only answer available to the customer asking why.
     */
    public function test_only_the_two_refusals_demand_a_reason(): void
    {
        $refusals = [OrderStatus::DITOLAK, OrderStatus::DITOLAK_SETELAH_BAYAR];

        foreach ($refusals as $refusal) {
            self::assertTrue($refusal->requiresReason(), "{$refusal->value} must demand a reason");
        }

        foreach (OrderStatus::cases() as $status) {
            if (! in_array($status, $refusals, true)) {
                self::assertFalse($status->requiresReason(), "{$status->value} should not demand a reason");
            }
        }
    }

    /**
     * The pay-first branch, asserted as a shape rather than edge by edge: one
     * way in, exactly two ways out, and the refusal absorbing.
     */
    public function test_the_pay_first_branch_offers_exactly_acceptance_or_refusal(): void
    {
        self::assertTrue(OrderTransition::isAllowed(
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI,
        ));

        self::assertSame(
            ['DIKONFIRMASI', 'DITOLAK_SETELAH_BAYAR'],
            OrderTransition::allowedFrom(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI),
        );

        // Money has arrived. Ending the order by cancellation or lapse would
        // leave the customer's payment unaccounted for, so neither is an edge.
        foreach ([OrderStatus::DIBATALKAN, OrderStatus::KEDALUWARSA] as $to) {
            self::assertFalse(
                OrderTransition::isAllowed(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI, $to),
                "DIBAYAR_MENUNGGU_KONFIRMASI -> {$to->value} would abandon money already received",
            );
        }

        self::assertSame(['DIPROSES'], OrderTransition::allowedFrom(OrderStatus::DIKONFIRMASI));
    }

    /**
     * The canonical rule this whole branch was shaped to avoid breaking:
     * `docs/domain/order-lifecycle.md` §3's "Nothing terminal is reachable
     * after `DIBAYAR`". The pay-first flow adds a refusal, and this asserts
     * that the refusal is NOT reachable from `DIBAYAR` — it leaves the new
     * pre-confirmation state instead, which is the entire reason that state
     * exists.
     */
    public function test_the_new_refusal_did_not_give_dibayar_a_terminal_edge(): void
    {
        self::assertSame(['DIPROSES'], OrderTransition::allowedFrom(OrderStatus::DIBAYAR));

        foreach ([OrderStatus::DIBAYAR, OrderStatus::DIKONFIRMASI, OrderStatus::DIPROSES, OrderStatus::SELESAI] as $from) {
            self::assertFalse(
                OrderTransition::isAllowed($from, OrderStatus::DITOLAK_SETELAH_BAYAR),
                "{$from->value} -> DITOLAK_SETELAH_BAYAR must not be allowed",
            );
        }
    }

    /**
     * The hourly quote-expiry sweep must never be able to reach an order
     * whose money has already arrived.
     *
     * `QuoteExpiryScheduler` drives orders to `KEDALUWARSA`, and
     * `RecordOrderStatusChange` then releases the plot. Doing that to a
     * paid-but-unconfirmed order would expire a booking the customer has
     * already paid for and hand their plot to somebody else, with the money
     * still sitting in the account.
     *
     * Asserted as a GRAPH property rather than by reading the scheduler's
     * own list, because that is the layer which holds regardless of what any
     * future sweep decides to select: no paid-or-later status has a
     * `KEDALUWARSA` edge at all, so `ExpireOrder` would throw
     * `IllegalOrderTransitionException` even if a scheduler picked one up.
     * The scheduler's `EXPIRABLE_STATUSES` is the first line and this is the
     * second; the test guards the one that cannot be edited around by
     * accident.
     */
    public function test_no_order_whose_money_has_arrived_can_be_swept_into_expiry(): void
    {
        foreach (OrderStatus::cases() as $status) {
            if (! $status->isPaidOrLater()) {
                continue;
            }

            self::assertFalse(
                OrderTransition::isAllowed($status, OrderStatus::KEDALUWARSA),
                "{$status->value} -> KEDALUWARSA would expire an order whose money has already arrived",
            );
        }
    }

    /**
     * DOM-08's protection must apply to every state in which money has
     * arrived, or a paid customer's plot hold becomes releasable without the
     * paid-order override on the new flow — silently, and only on the new
     * flow.
     */
    public function test_every_state_where_money_has_arrived_counts_as_paid_or_later(): void
    {
        foreach ([
            OrderStatus::DIBAYAR,
            OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI,
            OrderStatus::DIKONFIRMASI,
            OrderStatus::DITOLAK_SETELAH_BAYAR,
            OrderStatus::DIPROSES,
            OrderStatus::SELESAI,
        ] as $paid) {
            self::assertTrue($paid->isPaidOrLater(), "{$paid->value} must count as paid-or-later");
        }

        foreach ([
            OrderStatus::MASUK,
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
            OrderStatus::DITOLAK,
            OrderStatus::DIBATALKAN,
            OrderStatus::KEDALUWARSA,
        ] as $unpaid) {
            self::assertFalse($unpaid->isPaidOrLater(), "{$unpaid->value} must not count as paid-or-later");
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
