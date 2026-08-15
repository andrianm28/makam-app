<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MarketplaceOrders;

use App\Domain\Marketplace\PaymentState;
use App\Support\Design\StatusIntent;

/**
 * Filament badge (colour, label) mapping for `marketplace_orders.payment_state`.
 *
 * ---------------------------------------------------------------------------
 * Delegates to `StatusIntent`, the design-system-mandated single place
 * ---------------------------------------------------------------------------
 * design-system.md §3.7 (normative) defines the "Marketplace payment"
 * (`PaymentState`) family and its visual intent mapping, and §9.2 MUST #5
 * repeats: "Components must not switch on enum strings. Resolve status ->
 * intent in ONE place" — every Filament status column MUST call through
 * `StatusIntent`. The family is registered there as
 * `StatusIntent::FAMILY_MARKETPLACE_PAYMENT` (added 14 Aug 2026 with the
 * marketplace-checkout lane), so unlike `FaqArticleStatusBadge` (whose FAQ
 * vocabulary §3.7 deliberately does NOT cover) this badge has no local
 * mapping to maintain: `color()` bridges the resolved intent to the Filament
 * palette key (`StatusIntent::filamentColor()`), `label()` uses the
 * canonical humanised enum label. `PaymentState::KNOWN` and the badge both
 * key off the same real case literals
 * (`BELUM_DIBAYAR`, `MENUNGGU_VERIFIKASI`, `DIBAYAR`, `GAGAL`,
 * `DIKEMBALIKAN`), so a new case added to `PaymentState` without a §3.7
 * table entry degrades to `StatusIntent`'s neutral fallback (gray, raw
 * state as label) — never a crash, and never a guessed colour.
 */
final class MarketplacePaymentStateBadge
{
    public static function color(string $state): string
    {
        return StatusIntent::filamentColor($state, StatusIntent::FAMILY_MARKETPLACE_PAYMENT);
    }

    public static function label(string $state): string
    {
        return StatusIntent::label($state, StatusIntent::FAMILY_MARKETPLACE_PAYMENT);
    }

    /**
     * @return array<string, string> state literal => badge label, for
     *                               `SelectFilter` options.
     */
    public static function options(): array
    {
        return array_combine(
            PaymentState::KNOWN,
            array_map(
                fn (string $state): string => self::label($state),
                PaymentState::KNOWN,
            ),
        );
    }
}
