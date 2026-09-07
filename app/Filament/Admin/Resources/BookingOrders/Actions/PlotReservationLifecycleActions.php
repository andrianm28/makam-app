<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Filament\Support\CemeteryOrderActionGate;
use App\Filament\Support\OrderViewUrl;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * The three reservation lifecycle header actions on `ViewBookingOrder` —
 * 'Konfirmasi Reservasi' (held), 'Lepaskan Reservasi' (held/confirmed) and
 * 'Kedaluwarsakan Reservasi' (held), each routed to its owning domain
 * Action (Lane 2's `ConfirmPlotReservation` / `ReleasePlotReservation` /
 * `ExpirePlotReservation`, plan-signature-pinned and resolved at merge).
 *
 * Same two-layer enforcement shape as `ReservePlotAction` and
 * `TransitionOrderAction`: `->visible()` carries the per-edge state gate
 * (which reservation state the edge is legal from), `->authorize()` carries
 * the operational-actor gate — delegated to
 * `App\Filament\Support\CemeteryOrderActionGate`, the same shared gate
 * `ReservePlotAction` uses, because these are the same class of non-money
 * reservation action — and the run path re-checks the actor gate before
 * dispatching, because "the button was not rendered" is not a security
 * property.
 *
 * ---------------------------------------------------------------------------
 * Batch M3b (DOM-08) — the paid-order override
 * ---------------------------------------------------------------------------
 * `release()`/`expire()` are additionally hidden once the order is
 * `DIBAYAR` or later (`OrderStatus::isPaidOrLater()`): releasing/expiring a
 * paid order's reservation would silently return a paid-for plot to
 * available inventory. `releasePaidOrderOverride()` is the DISTINCT action
 * offered instead — visible only then — gated on a mandatory `reason`
 * `Textarea`, `ReauthenticationGuard::assertFresh()` (copying
 * `RecordExternalRenewalPaymentAction`'s redirect-to-challenge shape, reused
 * across BOTH panels via the single Admin `PasswordReauthentication` page —
 * the same cross-panel reuse `BasePlotFloorMapPage` already relies on for
 * the Operator panel), and `ReleasePlotReservation`'s own
 * `overridePaidOrder: true` parameter, which writes a DISTINCT audit action
 * (`PLOT_RESERVATION_RELEASED_PAID_ORDER_OVERRIDE`, on
 * `SensitiveActions::ACTIONS`) instead of the plain, routine
 * `PLOT_RESERVATION_RELEASED` — so the audit trail can tell an ordinary
 * pre-payment release apart from an override on a paid order. This mirrors
 * the domain-layer guard `ReleasePlotReservation`/`ExpirePlotReservation`
 * themselves enforce (structural, closes it for every caller including the
 * Floor/Block Map page's own release/expire wiring) — this class's
 * `->visible()` change is the UI-level half of the same fix.
 */
final class PlotReservationLifecycleActions
{
    public static function confirm(Order $order, PlotReservation $reservation): Action
    {
        return Action::make('confirm_plot_reservation')
            ->label('Konfirmasi Reservasi')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi reservasi plot')
            ->modalDescription('Reservasi ini dicatat di audit.')
            ->visible(fn (): bool => $reservation->state === PlotReservationState::HELD)
            ->authorize(fn (): bool => CemeteryOrderActionGate::allows($order))
            ->action(fn () => self::run($order, $reservation, 'confirm_plot_reservation', 'Reservasi dikonfirmasi.'));
    }

    public static function release(Order $order, PlotReservation $reservation): Action
    {
        return Action::make('release_plot_reservation')
            ->label('Lepaskan Reservasi')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Lepaskan reservasi plot')
            ->modalDescription('Plot akan kembali tersedia.')
            ->visible(
                fn (): bool => in_array($reservation->state, PlotReservationState::ACTIVE_STATES, true)
                    && ! $order->status()->isPaidOrLater()
            )
            ->authorize(fn (): bool => CemeteryOrderActionGate::allows($order))
            ->action(fn () => self::run($order, $reservation, 'release_plot_reservation', 'Reservasi dilepas.'));
    }

    public static function expire(Order $order, PlotReservation $reservation): Action
    {
        return Action::make('expire_plot_reservation')
            ->label('Kedaluwarsakan Reservasi')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Kedaluwarsakan reservasi plot')
            ->modalDescription('Plot akan kembali tersedia.')
            ->visible(
                fn (): bool => $reservation->state === PlotReservationState::HELD
                    && ! $order->status()->isPaidOrLater()
            )
            ->authorize(fn (): bool => CemeteryOrderActionGate::allows($order))
            ->action(fn () => self::run($order, $reservation, 'expire_plot_reservation', 'Reservasi kedaluwarsa.'));
    }

    /**
     * Batch M3b (DOM-08) — the distinct paid-order override action. Visible
     * ONLY once `release()`/`expire()` have hidden themselves (the order is
     * `DIBAYAR` or later) and the reservation is still in an active state
     * (releasing a reservation that is already released/expired/converted
     * is meaningless). Requires a mandatory `reason` and a fresh
     * re-authentication before dispatching — see the class doc block.
     */
    public static function releasePaidOrderOverride(Order $order, PlotReservation $reservation): Action
    {
        return Action::make('release_plot_reservation_paid_order_override')
            ->label('Lepaskan Reservasi Pesanan Berbayar')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Lepaskan reservasi pesanan yang sudah dibayar')
            ->modalDescription(
                'Pesanan ini sudah dibayar. Melepaskan reservasi akan mengembalikan plot ke status '
                .'tersedia meskipun pembayaran telah diterima. Tindakan ini dicatat di audit dengan alasan wajib.'
            )
            ->schema([
                Textarea::make('reason')
                    ->label('Alasan')
                    ->rows(3)
                    ->required(),
            ])
            ->visible(
                fn (): bool => $order->status()->isPaidOrLater()
                    && in_array($reservation->state, PlotReservationState::ACTIVE_STATES, true)
            )
            ->authorize(fn (): bool => CemeteryOrderActionGate::allows($order))
            ->action(function (array $data) use ($order, $reservation): void {
                self::runPaidOrderOverride($order, $reservation, (string) $data['reason']);
            });
    }

    /**
     * The enforcement path: re-checks the actor gate, dispatches to the
     * owning domain Action by transition name, then notifies + redirects.
     * The transition-name vocabulary is shared with the three factory
     * methods above — the same one-vocabulary discipline
     * `TransitionOrderAction` uses.
     *
     * `$order` and `$reservation` are independent parameters on a public
     * static factory shared across two panels — the guard below is not
     * exploitable through either call site today (both derive `$reservation`
     * from `PlotReservation::activeForOrder($order)` on an
     * already-scoped record), but a future caller could pass a mismatched
     * pair, authorizing against a cemetery the actor holds while mutating a
     * reservation belonging to one they do not.
     */
    private static function run(Order $order, PlotReservation $reservation, string $transition, string $successTitle): void
    {
        if ((string) $reservation->order_id !== (string) $order->getKey()) {
            Notification::make()
                ->danger()
                ->title('Reservasi tidak sesuai dengan pesanan ini.')
                ->send();

            return;
        }

        if (! CemeteryOrderActionGate::allows($order)) {
            Notification::make()
                ->danger()
                ->title('Anda tidak berwenang melakukan tindakan ini.')
                ->send();

            return;
        }

        $actor = app(ActorContext::class);
        $actorReference = (string) $actor->identityReference;
        $actorRole = BookingOrderResource::auditRoleFor($actor);

        try {
            match ($transition) {
                'confirm_plot_reservation' => app(ConfirmPlotReservation::class)($reservation, $actorReference, $actorRole),
                'release_plot_reservation' => app(ReleasePlotReservation::class)($reservation, $actorReference, $actorRole),
                'expire_plot_reservation' => app(ExpirePlotReservation::class)($reservation, $actorReference, $actorRole),
            };

            Notification::make()->success()->title($successTitle)->send();
            redirect()->to(OrderViewUrl::for($order));
        } catch (\Throwable $exception) {
            Notification::make()->danger()->title('Pembaruan gagal')->body($exception->getMessage())->send();
        }
    }

    /**
     * Batch M3b (DOM-08) — the override enforcement path. Same
     * mismatched-pair and actor-gate re-checks as `run()`, PLUS a fresh
     * re-authentication (money-adjacent act, same control
     * `RecordExternalRenewalPaymentAction` uses) before calling
     * `ReleasePlotReservation` with `overridePaidOrder: true` so the audit
     * row lands under the distinct `_PAID_ORDER_OVERRIDE` action rather
     * than the plain, routine one.
     */
    private static function runPaidOrderOverride(Order $order, PlotReservation $reservation, string $reason): void
    {
        if ((string) $reservation->order_id !== (string) $order->getKey()) {
            Notification::make()
                ->danger()
                ->title('Reservasi tidak sesuai dengan pesanan ini.')
                ->send();

            return;
        }

        if (! CemeteryOrderActionGate::allows($order)) {
            Notification::make()
                ->danger()
                ->title('Anda tidak berwenang melakukan tindakan ini.')
                ->send();

            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(ReauthenticationGuard::class)->assertFresh($actor);
        } catch (ReauthenticationRequiredException) {
            Notification::make()
                ->warning()
                ->title('Perlu verifikasi ulang')
                ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                ->send();

            session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'money_action');
            session()->put('url.intended', OrderViewUrl::for($order));
            redirect()->route(PasswordReauthentication::ROUTE_NAME);

            return;
        }

        $actorReference = (string) $actor->identityReference;
        $actorRole = BookingOrderResource::auditRoleFor($actor);

        try {
            app(ReleasePlotReservation::class)(
                $reservation,
                $actorReference,
                $actorRole,
                $reason,
                overridePaidOrder: true,
            );

            Notification::make()->success()->title('Reservasi pesanan berbayar dilepas.')->send();
            redirect()->to(OrderViewUrl::for($order));
        } catch (\Throwable $exception) {
            Notification::make()->danger()->title('Pembaruan gagal')->body($exception->getMessage())->send();
        }
    }
}
