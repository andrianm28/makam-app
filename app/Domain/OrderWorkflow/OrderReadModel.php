<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Models\Order;
use App\Support\Design\StatusIntent;

/**
 * Step 9 read model — exposes order state for the public-facing order detail
 * view and for internal admin/case-manager workflows.
 *
 * AC13: order reference, status (StatusIntent-resolved), invoice state,
 * channel-delivery state, next action, and support reference.
 *
 * AC12: the admin/case-manager fallback path out of MENUNGGU_KETERSEDIAAN
 * stays reachable while the order is in that state. This is guaranteed by:
 * (a) MENUNGGU_KETERSEDIAAN having a non-terminal status in the transition
 *     graph (admin can move it to PENAWARAN_TERKIRIM via RecordOrderStatusChange),
 *     and (b) this read model exposing manualFallbackAvailable = true and a
 *     non-null nextAction for every non-terminal status.
 *
 * AC7: fallback modes are server-resolved and read from FeatureGate, never
 * from request input.
 *
 * ---------------------------------------------------------------------------
 * The (b) guarantee is a standing obligation on every future status
 * ---------------------------------------------------------------------------
 * "a non-null nextAction for every non-terminal status" is not a statement
 * about the statuses that existed when this class was written — it is a
 * promise about all of them, and `resolveNextAction()`'s `default => null`
 * arm breaks it silently for any non-terminal status nobody remembered to
 * add. That is exactly what happened when the three pay-first statuses
 * landed on 13 Sep 2026: two of them were non-terminal, fell to `default`,
 * and this doc block's claim became false without a single test failing.
 *
 * `OrderReadModelTest` now iterates `OrderStatus::cases()` rather than a
 * hand-written list, so adding a case to the enum without an arm here fails
 * the suite. Do not convert those tests back to a literal list: a test that
 * enumerates by hand cannot fail on the one change it exists to catch.
 */
final readonly class OrderReadModel
{
    public function __construct(
        public string $orderReference,
        public string $statusIntent,
        public string $invoiceState,
        public string $channelDeliveryState,
        public ?string $nextAction,
        public ?string $supportReference,
        public bool $manualFallbackAvailable,
        public string $correlationReference,
    ) {}

    public static function forOrder(Order $order): self
    {
        $status = OrderStatus::from($order->status);
        $isTerminal = OrderTransition::isTerminal($status);

        return new self(
            orderReference: $order->reference,
            statusIntent: StatusIntent::intent($order->status, StatusIntent::FAMILY_ORDER_LIFECYCLE),
            invoiceState: $isTerminal ? 'not_applicable' : 'pending',
            channelDeliveryState: self::resolveChannelDeliveryState($status),
            nextAction: self::resolveNextAction($status),
            supportReference: $isTerminal ? null : 'SUPPORT-'.strtoupper(substr(md5((string) $order->getKey()), 0, 8)),
            manualFallbackAvailable: ! $isTerminal,
            correlationReference: (string) $order->getKey(),
        );
    }

    /**
     * What this field answers is "has the customer's money arrived", which is
     * why `DIBAYAR` reads `confirmed` and everything before it reads
     * `pending`.
     *
     * The three pay-first statuses were added on 13 Sep 2026. All three
     * describe an order whose money HAS arrived, so all three read
     * `confirmed`; leaving them on the `default` arm told a customer who had
     * paid in full that their payment was still pending.
     *
     * `DITOLAK_SETELAH_BAYAR` is included deliberately, and it is the one
     * that looks wrong at first glance. The order was refused — but this
     * field is not the order's verdict, it is the money's. Money did arrive,
     * and a refund obligation now owes it back; reading `pending` there would
     * assert the payment never happened, which is the more damaging of the
     * two possible misreadings. The refusal itself is carried by
     * `statusIntent` (danger) and the status label.
     */
    private static function resolveChannelDeliveryState(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::DIBAYAR,
            OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI,
            OrderStatus::DIKONFIRMASI,
            OrderStatus::DITOLAK_SETELAH_BAYAR,
            // `DIPROSES` was ALREADY on the `default => 'pending'` arm before
            // the pay-first flow existed — an order being actively fulfilled
            // read as one whose payment had never arrived. Found by the
            // `cases()`-driven test added alongside this fix, on its first
            // run, which is the argument for that test shape in one line.
            OrderStatus::DIPROSES => 'confirmed',
            OrderStatus::SELESAI => 'fulfilled',
            default => 'pending',
        };
    }

    private static function resolveNextAction(OrderStatus $status): ?string
    {
        if (OrderTransition::isTerminal($status)) {
            return null;
        }

        return match ($status) {
            OrderStatus::MASUK => 'Menunggu diverifikasi oleh tim kami',
            OrderStatus::DIVERIFIKASI => 'Menunggu ketersediaan makam dikonfirmasi',
            OrderStatus::MENUNGGU_KETERSEDIAAN => 'Menunggu konfirmasi ketersediaan dari operator — atau hubungi support untuk bantuan manual',
            OrderStatus::PENAWARAN_TERKIRIM => 'Menunggu persetujuan penawaran dari pelanggan',
            OrderStatus::DISETUJUI_PEMESAN => 'Menunggu pelanggan memulai pembayaran',
            OrderStatus::MENUNGGU_PEMBAYARAN => 'Menunggu pembayaran diterima',
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN => 'Menunggu verifikasi pembayaran oleh tim kami',
            OrderStatus::DIBAYAR => 'Pembayaran diterima — sedang diproses',
            // The pay-first flow (13 Sep 2026). Both are non-terminal, so
            // both reach this match and MUST return a string: the
            // `default => null` arm below would break the AC12 guarantee
            // stated in this class's doc block, and would show a customer who
            // has just paid in full a detail page with no next-action text at
            // all.
            OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI => 'Pembayaran diterima — menunggu konfirmasi dari tim kami',
            OrderStatus::DIKONFIRMASI => 'Pemesanan dikonfirmasi — menunggu proses pemakaman dimulai',
            OrderStatus::DIPROSES => 'Pemesanan sedang diproses',
            default => null,
        };
    }
}
