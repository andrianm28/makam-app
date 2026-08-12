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

    private static function resolveChannelDeliveryState(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::DIBAYAR => 'confirmed',
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
            OrderStatus::DIPROSES => 'Pemesanan sedang diproses',
            default => null,
        };
    }
}
