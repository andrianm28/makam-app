<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Actions;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use App\Filament\Support\OrderViewUrl;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
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
 * the operational-actor gate (operator/restricted_admin/admin — reservation
 * is not a money-adjacent action), and the run path re-checks the actor
 * gate before dispatching, because "the button was not rendered" is not a
 * security property.
 */
final class PlotReservationLifecycleActions
{
    /**
     * The platform-wide operational admission list — identical to
     * `ReservePlotAction::PLATFORM_WIDE_ROLES`, deliberately, because these
     * are the same class of non-money reservation action. Not finance.
     *
     * `cemetery_operator` is answered by its own branch in `roleAllowed()`,
     * which additionally requires the order's cemetery to be among the
     * actor's grants — see `ReservePlotAction::roleAllowed()`'s doc block
     * for the full argument, which applies here unchanged.
     *
     * @var list<string>
     */
    private const array PLATFORM_WIDE_ROLES = [
        ActorRole::OPERATOR,
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::ADMIN,
    ];

    public static function confirm(Order $order, PlotReservation $reservation): Action
    {
        return Action::make('confirm_plot_reservation')
            ->label('Konfirmasi Reservasi')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi reservasi plot')
            ->modalDescription('Reservasi ini dicatat di audit.')
            ->visible(fn (): bool => $reservation->state === PlotReservationState::HELD)
            ->authorize(fn (): bool => self::roleAllowed($order))
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
            ->authorize(fn (): bool => self::roleAllowed($order))
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
            ->authorize(fn (): bool => self::roleAllowed($order))
            ->action(fn () => self::run($order, $reservation, 'expire_plot_reservation', 'Reservasi kedaluwarsa.'));
    }

    /**
     * Structurally identical to `ReservePlotAction::roleAllowed()` — the
     * `/admin` path (master-data gate + a platform-wide role) or the
     * `/operator` path (cemetery gate + `cemetery_operator` + the order's
     * own cemetery among the actor's grants). See that method's doc block
     * for the reasoning; the two are kept the same shape on purpose,
     * because `/operator`'s `ViewCemeteryOrder` renders all four actions
     * together and a divergence between them would show up as an operator
     * able to place a hold they cannot clear.
     */
    private static function roleAllowed(Order $order): bool
    {
        $actor = app(ActorContext::class);

        if (BookingOrderResource::canAccess()) {
            foreach (self::PLATFORM_WIDE_ROLES as $role) {
                if ($actor->hasRole($role)) {
                    return true;
                }
            }
        }

        return CemeteryOrderResource::canAccess()
            && $actor->hasRole(ActorRole::CEMETERY_OPERATOR)
            && app(CurrentCemeteryScope::class)->allows($order->bookingDraft?->cemetery_id);
    }

    /**
     * The enforcement path: re-checks the actor gate, dispatches to the
     * owning domain Action by transition name, then notifies + redirects.
     * The transition-name vocabulary is shared with the three factory
     * methods above — the same one-vocabulary discipline
     * `TransitionOrderAction` uses.
     */
    private static function run(Order $order, PlotReservation $reservation, string $transition, string $successTitle): void
    {
        if (! self::roleAllowed($order)) {
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
