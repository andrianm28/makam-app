<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MarketplaceOrders\Actions;

use App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Filament\Admin\Resources\MarketplaceOrders\MarketplaceOrderResource;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\Audit\AuditSource;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The header action that marks a marketplace order paid from the admin
 * panel — the ONLY panel surface for `mark_marketplace_order_paid`.
 *
 * ---------------------------------------------------------------------------
 * Two enforcement layers, and neither is redundant
 * ---------------------------------------------------------------------------
 * `->authorize(fn (): bool => self::authorized())` is the render/mount
 * gate (Filament's own source: "Actions do not have automatic policy-based
 * authorization. Authorization defaults to `null` (allowed for all
 * users)"; `CanBeAuthorized::isAuthorized()` folds into
 * `CanBeDisabled::isDisabled()`, so an unauthorized action is neither
 * drawn nor mountable). The `->action()` closure re-runs the SAME
 * authorizer plus `ReauthenticationGuard::assertFresh()` before the
 * Domain Action runs, because a Livewire component method is addressable
 * directly over the wire — "the button was not rendered" is not a security
 * property. Both use `OrderTransitionAuthorizerContract` with the
 * transition name `mark_marketplace_order_paid` (Task 1's lane: finance /
 * admin only — operators are refused, which the resource test asserts).
 *
 * ---------------------------------------------------------------------------
 * Reauthentication before a money move
 * ---------------------------------------------------------------------------
 * AGENTS.md: "Require recent re-authentication for financial ... actions."
 * A stale actor is bounced to the password re-authentication page
 * (`PasswordReauthentication::ROUTE_NAME`) with `url.intended` set so they
 * return to this page — never left with a partially executed transition.
 *
 * ---------------------------------------------------------------------------
 * The Domain Action call
 * ---------------------------------------------------------------------------
 * `MarkMarketplaceOrderPaid::__invoke(MarketplaceOrder $order, int
 * $amountMinor, bool $fulfilmentEvidenceAccepted, ?CarbonImmutable
 * $disputeWindowEndsAt, ?string $actorRef, string $actorRole, ?string
 * $correlationId, AuditSource $source, ?CarbonImmutable $now)` — named args
 * pin `amountMinor` to the order's frozen `total_minor` (the action asserts
 * exact equality before any write), the panel states fulfilment evidence
 * accepted and names `AuditSource::Panel` (a human, not a webhook). Note
 * this path can still throw `MarketplacePayableMissingException` when the
 * order's placement never opened its vendor payable — a data-integrity
 * anomaly this panel surfaces as an error notification rather than
 * swallowing.
 */
final class MarkMarketplaceOrderPaidAction
{
    public static function make(MarketplaceOrder $order): Action
    {
        return Action::make('mark_paid')
            ->label('Tandai Dibayar')
            ->color('warning')
            ->icon(Heroicon::OutlinedBanknotes)
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi pembayaran')
            ->modalDescription('Pesanan ini ditandai dibayar penuh dan dicatat di audit.')
            ->authorize(fn (): bool => self::authorized())
            ->action(function () use ($order): void {
                $actor = app(ActorContext::class);
                $actorRef = $actor->identityReference;
                $actorRole = MarketplaceOrderResource::auditRoleFor($actor);

                try {
                    app(OrderTransitionAuthorizerContract::class)->authorizeTransition($actor, 'mark_marketplace_order_paid');
                    app(ReauthenticationGuard::class)->assertFresh($actor);
                } catch (ReauthenticationRequiredException) {
                    Notification::make()->warning()->title('Perlu verifikasi ulang')->send();
                    session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'money_action');
                    session()->put('url.intended', route('filament.admin.resources.pesanan-marketplace.view', ['record' => $order->getKey()]));
                    redirect()->route(PasswordReauthentication::ROUTE_NAME);

                    return;
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                try {
                    app(MarkMarketplaceOrderPaid::class)(
                        $order,
                        (int) $order->total_minor,
                        fulfilmentEvidenceAccepted: true,
                        actorRef: $actorRef,
                        actorRole: $actorRole,
                        correlationId: app(CorrelationContext::class)->current()?->value,
                        source: AuditSource::Panel,
                    );
                    Notification::make()->success()->title('Pesanan ditandai dibayar.')->send();
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title('Gagal menandai pembayaran')->body($exception->getMessage())->send();
                }
            });
    }

    private static function authorized(): bool
    {
        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(app(ActorContext::class), 'mark_marketplace_order_paid');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
