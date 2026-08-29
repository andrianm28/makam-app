<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Filament\Support\CemeteryOrderActionGate;
use App\Filament\Support\OrderViewUrl;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
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
            ->visible(fn (): bool => $reservation->state === PlotReservationState::HELD)
            ->authorize(fn (): bool => CemeteryOrderActionGate::allows($order))
            ->action(fn () => self::run($order, $reservation, 'expire_plot_reservation', 'Reservasi kedaluwarsa.'));
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
}
