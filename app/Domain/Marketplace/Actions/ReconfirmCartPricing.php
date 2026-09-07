<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\MarketplaceAuditActions;
use App\Domain\Marketplace\Models\Cart;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * ARCH-13 remediation
 * (`docs/superpowers/plans/2026-09-07-batchm5a-domain-action-extraction.md`).
 * The customer's explicit accept of a listing's new price (design-system
 * §6.2/§6.3's "Harga berubah" alert): refreshes each cart line's FROZEN
 * `unit_price_minor`/`price_version` pair back to its listing's current
 * values. Previously `Cart::reconfirmPricing()` (a Livewire component) made
 * this write directly — no Domain Action, no audit trail — for a
 * money-affecting mutation of columns every other write path in this
 * module treats as frozen-at-add-time.
 *
 * `Audit::wrap()` provides the transaction; no separate explicit one is
 * opened, matching `IssueQuote`/`RecordOrderStatusChange`'s own reasoning
 * (AC4: the mutation and its audit record can never be committed
 * separately).
 */
final readonly class ReconfirmCartPricing
{
    public function handle(Cart $cart, ?string $actorRef, string $actorRole): void
    {
        foreach ($cart->items()->with('listing')->get() as $item) {
            if ((int) $item->price_version === (int) $item->listing->price_version
                && (int) $item->unit_price_minor === (int) $item->listing->price_minor) {
                continue;
            }

            Audit::wrap(
                mutation: function () use ($item): void {
                    $item->update([
                        'unit_price_minor' => $item->listing->price_minor,
                        'price_version' => $item->listing->price_version,
                    ]);
                },
                action: MarketplaceAuditActions::CART_PRICING_RECONFIRMED,
                subject: new AuditSubject('cart_item', $item->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: AuditSource::Api,
                correlationId: app(CorrelationContext::class)->current()?->value,
            );
        }
    }
}
