<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

/**
 * Commercial order status. Values are canonical in
 * `docs/domain/order-lifecycle.md` §1 and rendered through
 * `App\Support\Design\StatusIntent`'s order-lifecycle family — never by
 * matching on these strings in a component.
 *
 * Deliberately NOT the case/work/certificate state: `funeral-case-model.md:35`
 * and `domain-model.md:165` both state those are distinct from commercial
 * status. Requirement 11 depends on the separation.
 */
enum OrderStatus: string
{
    case MASUK = 'MASUK';
    case DIVERIFIKASI = 'DIVERIFIKASI';
    case MENUNGGU_KETERSEDIAAN = 'MENUNGGU_KETERSEDIAAN';
    case PENAWARAN_TERKIRIM = 'PENAWARAN_TERKIRIM';
    case DISETUJUI_PEMESAN = 'DISETUJUI_PEMESAN';
    case MENUNGGU_PEMBAYARAN = 'MENUNGGU_PEMBAYARAN';
    case MENUNGGU_VERIFIKASI_PEMBAYARAN = 'MENUNGGU_VERIFIKASI_PEMBAYARAN';
    case DIBAYAR = 'DIBAYAR';

    /**
     * Money has arrived and the admin has not yet decided — the landing
     * state of the pay-in-full-upfront flow (Stage 2 of
     * `docs/superpowers/plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`).
     *
     * A SEPARATE state from `DIBAYAR`, and that separation is what keeps
     * `docs/domain/order-lifecycle.md` §3's rule intact: *"Nothing terminal
     * is reachable after `DIBAYAR`"*. An order that is paid-but-unconfirmed
     * never enters `DIBAYAR`, so refusing it is not a terminal edge out of
     * `DIBAYAR` and that rule is never bent. `DIBAYAR` remains exactly what
     * it was — the settled state of the old, operator-confirmed-first flow.
     */
    case DIBAYAR_MENUNGGU_KONFIRMASI = 'DIBAYAR_MENUNGGU_KONFIRMASI';

    /** The admin accepted a paid order. The money keeps its meaning. */
    case DIKONFIRMASI = 'DIKONFIRMASI';

    case DIPROSES = 'DIPROSES';
    case SELESAI = 'SELESAI';
    case DITOLAK = 'DITOLAK';

    /**
     * The admin refused an order AFTER the customer's money arrived.
     *
     * Deliberately not plain `DITOLAK`. The owner's stated reason for a
     * separate word: a report must never confuse "refused before any money
     * moved" with "refused while holding the customer's money" — the second
     * carries a debt and a deadline, the first carries nothing. Pairing it
     * with `DITOLAK` rather than `DIBATALKAN` is deliberate too: this is an
     * admin refusal, where `DIBATALKAN` means a customer cancellation.
     *
     * Reachable ONLY through
     * `Actions\RefusePaidOrder`, and `Actions\RecordOrderStatusChange`
     * refuses to write it unless a refund obligation for the order already
     * exists in the same transaction. See that guard for why it is a data
     * invariant rather than a flag.
     */
    case DITOLAK_SETELAH_BAYAR = 'DITOLAK_SETELAH_BAYAR';

    case DIBATALKAN = 'DIBATALKAN';
    case KEDALUWARSA = 'KEDALUWARSA';

    /**
     * The linear progression, used to assert no edge ever points backward.
     * Terminal branches are excluded — they are not positions on the line.
     *
     * @return list<self>
     */
    public static function forwardOrder(): array
    {
        return [
            self::MASUK,
            self::DIVERIFIKASI,
            self::MENUNGGU_KETERSEDIAAN,
            self::PENAWARAN_TERKIRIM,
            self::DISETUJUI_PEMESAN,
            self::MENUNGGU_PEMBAYARAN,
            self::MENUNGGU_VERIFIKASI_PEMBAYARAN,
            self::DIBAYAR,
            self::DIBAYAR_MENUNGGU_KONFIRMASI,
            self::DIKONFIRMASI,
            self::DIPROSES,
            self::SELESAI,
        ];
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::DITOLAK, self::DITOLAK_SETELAH_BAYAR], true);
    }

    /**
     * Batch M3b (DOM-08): `true` once money is confirmed
     * (`DIBAYAR`) or the order has moved past it. `OrderTransition::ALLOWED`
     * shows `DIBAYAR => ['DIPROSES']` and `DIPROSES => ['SELESAI']` as the
     * ONLY edges reachable once an order is `DIBAYAR` — nothing terminal
     * (`DITOLAK`/`DIBATALKAN`/`KEDALUWARSA`) is reachable afterwards — so
     * this closed list was exhaustive, not a guess at "later" statuses.
     *
     * UPDATED 13 Sep 2026 (Stage 2 of the pay-in-full-upfront plan). The
     * pay-first flow adds three states where money has ALSO arrived, and
     * every one of them must answer `true` here or DOM-08's protection
     * silently stops applying to the new flow: a paid customer's plot hold
     * would be releasable without the paid-order override, which is the
     * exact failure DOM-08 closed.
     *
     * `DITOLAK_SETELAH_BAYAR` is on this list even though it is terminal and
     * refused. Money did arrive, and the question this method answers is
     * "has money arrived?", not "is the order still alive". The refund
     * obligation is what returns it; until then the fact stands.
     */
    public function isPaidOrLater(): bool
    {
        return in_array($this, [
            self::DIBAYAR,
            self::DIBAYAR_MENUNGGU_KONFIRMASI,
            self::DIKONFIRMASI,
            self::DIPROSES,
            self::SELESAI,
            self::DITOLAK_SETELAH_BAYAR,
        ], true);
    }
}
