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
    case DIPROSES = 'DIPROSES';
    case SELESAI = 'SELESAI';
    case DITOLAK = 'DITOLAK';
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
            self::DIPROSES,
            self::SELESAI,
        ];
    }

    public function requiresReason(): bool
    {
        return $this === self::DITOLAK;
    }
}
