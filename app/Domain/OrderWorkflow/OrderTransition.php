<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;

/**
 * The allowed commercial edges. Mirrors `docs/domain/order-lifecycle.md` §2's
 * transition matrix, plus the three placements that matrix does not cover and
 * this module had to settle (grill-spec Round 1):
 *
 *   - `MENUNGGU_VERIFIKASI_PEMBAYARAN` is absent from the canonical matrix
 *     entirely. It sits on the MANUAL path only, between MENUNGGU_PEMBAYARAN
 *     and DIBAYAR.
 *   - A REJECTED manual verification is deliberately not an edge here. It is a
 *     `PaymentVerificationStatus` transition, so the order stays put and the
 *     customer submits a new verification — which is how §3's "No transition
 *     backward" and the customer's need to retry are satisfied at once.
 *   - Nothing terminal is reachable after DIBAYAR. Once money is confirmed,
 *     §3's "compensating financial action" (PaymentReversal + reversing
 *     journal batch) is the correction mechanism, not a status edge.
 *   - `DIVERIFIKASI -> PENAWARAN_TERKIRIM` is a second edge added by the
 *     TPU/TPS operator dashboard roadmap's Phase F
 *     (`docs/superpowers/plans/2026-08-29-booking-flow-shortening.md`):
 *     reachable only when a plot reservation already exists, enforced by
 *     `Actions\IssueQuoteFromReservedPlot`, not by this matrix — the
 *     matrix only makes the edge possible, per this class's own
 *     two-layer discipline.
 */
final class OrderTransition
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'MASUK' => ['DIVERIFIKASI', 'DITOLAK', 'DIBATALKAN'],
        'DIVERIFIKASI' => ['MENUNGGU_KETERSEDIAAN', 'PENAWARAN_TERKIRIM', 'DITOLAK', 'DIBATALKAN'],
        'MENUNGGU_KETERSEDIAAN' => ['PENAWARAN_TERKIRIM', 'DITOLAK', 'DIBATALKAN'],
        'PENAWARAN_TERKIRIM' => ['DISETUJUI_PEMESAN', 'KEDALUWARSA', 'DIBATALKAN'],
        'DISETUJUI_PEMESAN' => ['MENUNGGU_PEMBAYARAN', 'KEDALUWARSA', 'DIBATALKAN'],
        'MENUNGGU_PEMBAYARAN' => ['MENUNGGU_VERIFIKASI_PEMBAYARAN', 'DIBAYAR', 'KEDALUWARSA', 'DIBATALKAN'],
        'MENUNGGU_VERIFIKASI_PEMBAYARAN' => ['DIBAYAR', 'DIBATALKAN'],
        'DIBAYAR' => ['DIPROSES'],
        'DIPROSES' => ['SELESAI'],
        'SELESAI' => [],
        'DITOLAK' => [],
        'DIBATALKAN' => [],
        'KEDALUWARSA' => [],
    ];

    public static function isAllowed(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value], true);
    }

    public static function assertAllowed(OrderStatus $from, OrderStatus $to): void
    {
        if (! self::isAllowed($from, $to)) {
            throw IllegalOrderTransitionException::between($from, $to);
        }
    }

    public static function isTerminal(OrderStatus $status): bool
    {
        return self::ALLOWED[$status->value] === [];
    }

    /** @return list<string> */
    public static function allowedFrom(OrderStatus $from): array
    {
        return self::ALLOWED[$from->value];
    }
}
