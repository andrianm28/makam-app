<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;

/**
 * The allowed commercial edges. Mirrors `docs/domain/order-lifecycle.md` §2's
 * transition matrix — including its `DIVERIFIKASI -> PENAWARAN_TERKIRIM` row,
 * which that matrix marks as its one CONDITIONAL edge. That conditionality
 * lives in `Actions\IssueQuoteFromReservedPlot`, not here: this matrix only
 * makes the edge possible, per this class's own two-layer discipline (the
 * same split the TPU/TPS operator dashboard roadmap's Phase F documents,
 * `docs/superpowers/plans/2026-08-29-booking-flow-shortening.md`).
 *
 * Beyond the canonical matrix, three placements it does not cover and this
 * module had to settle (grill-spec Round 1):
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
 *
 * ---------------------------------------------------------------------------
 * 13 Sep 2026 — the pay-in-full-upfront flow, and why the third bullet above
 * is still true word for word
 * ---------------------------------------------------------------------------
 * `docs/superpowers/plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`
 * reverses the order of money and confirmation: the customer pays in full,
 * THEN an admin decides. That creates a case the matrix above had no answer
 * for — an admin refusing an order whose money has already arrived.
 *
 * The obvious edit would have been `DIBAYAR => [..., 'DITOLAK']`, and it is
 * exactly what the third bullet forbids. It was not made. Instead the
 * paid-but-undecided order lands on its OWN state,
 * `DIBAYAR_MENUNGGU_KONFIRMASI`, and the refusal edge leaves THAT state, not
 * `DIBAYAR`:
 *
 *     MENUNGGU_PEMBAYARAN -> DIBAYAR_MENUNGGU_KONFIRMASI
 *                              |-> DIKONFIRMASI -> DIPROSES -> ...
 *                              `-> DITOLAK_SETELAH_BAYAR (terminal)
 *
 * `DIBAYAR`'s own row is UNCHANGED — still `['DIPROSES']`, still no terminal
 * edge, still corrected only by a compensating financial action. `DIBAYAR`
 * keeps its exact old meaning: the settled state of the
 * operator-confirms-first flow. Nothing that was true of an order at
 * `DIBAYAR` yesterday is less true today, and no canonical rule was revoked
 * to make room for the new flow.
 *
 * What the new branch costs instead is a NEW obligation, enforced one layer
 * up: `Actions\RecordOrderStatusChange` refuses to write
 * `DITOLAK_SETELAH_BAYAR` unless a `refund_obligations` row for the order
 * already exists in the same transaction. The matrix makes the edge
 * possible; that guard decides when it may be taken — the same two-layer
 * split this class already applies to the conditional
 * `DIVERIFIKASI -> PENAWARAN_TERKIRIM` edge.
 *
 * `DIBATALKAN`/`KEDALUWARSA` are deliberately absent from
 * `DIBAYAR_MENUNGGU_KONFIRMASI`'s row. Money has arrived; the only ways out
 * are an acceptance or a refusal that records the debt. A cancellation or a
 * lapse would end the order while leaving the customer's money unaccounted
 * for, which is the precise failure the refusal door exists to prevent.
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
        'MENUNGGU_PEMBAYARAN' => ['MENUNGGU_VERIFIKASI_PEMBAYARAN', 'DIBAYAR', 'DIBAYAR_MENUNGGU_KONFIRMASI', 'KEDALUWARSA', 'DIBATALKAN'],
        'MENUNGGU_VERIFIKASI_PEMBAYARAN' => ['DIBAYAR', 'DIBATALKAN'],
        'DIBAYAR' => ['DIPROSES'],
        'DIBAYAR_MENUNGGU_KONFIRMASI' => ['DIKONFIRMASI', 'DITOLAK_SETELAH_BAYAR'],
        'DIKONFIRMASI' => ['DIPROSES'],
        'DIPROSES' => ['SELESAI'],
        'SELESAI' => [],
        'DITOLAK' => [],
        'DITOLAK_SETELAH_BAYAR' => [],
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
