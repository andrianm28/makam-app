<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Actions;

use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Actions\IssueQuoteFromReservedPlot;
use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Filament\Support\OrderViewUrl;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * TPU/TPS operator dashboard roadmap, Phase F
 * (`docs/superpowers/plans/2026-08-29-booking-flow-shortening.md`, Task 2)
 * — the "Lanjutkan dengan Plot Tercadang" header action on both
 * `ViewBookingOrder` (`/admin`) and `ViewCemeteryOrder` (`/operator`).
 * Renders ALONGSIDE the generic `transition_MENUNGGU_KETERSEDIAAN`
 * button, never replacing it — an order at `DIVERIFIKASI` with a
 * qualifying plot reservation gets BOTH options.
 *
 * ---------------------------------------------------------------------------
 * Two-layer enforcement, same shape as `ReservePlotAction`/
 * `TransitionOrderAction`
 * ---------------------------------------------------------------------------
 * `->visible()` is the RENDER gate, from ORDER state alone: status must
 * be `DIVERIFIKASI`, `PlotReservation::activeForOrder()` must be non-null,
 * AND that reservation's own cemetery must be granular-tier.
 * `->authorize()` is the ACTOR gate — the SAME
 * `OrderTransitionAuthorizerContract` check `TransitionOrderAction` uses
 * for the normal `issue_quote` edge (this shortcut is authorization-
 * equivalent to issuing a quote the normal way; only the precondition
 * differs, so it reuses the same transition NAME rather than inventing a
 * second one). `run()` re-checks BOTH gates as its first acts, because
 * "the button was not rendered" is not a security property, and because
 * `IssueQuoteFromReservedPlot` itself ALSO re-asserts its own three
 * preconditions independently — belt and braces across three layers
 * (Filament visible, Filament authorize, domain-action precondition), not
 * redundant: each closes a different bypass (a stale render, a direct
 * wire call with a spoofed record, a caller that skips this Filament
 * class entirely and calls the domain action directly).
 *
 * ---------------------------------------------------------------------------
 * Why this is NOT registered via `OrderTransition::allowedFrom()`'s
 * generic loop
 * ---------------------------------------------------------------------------
 * `TransitionOrderAction`'s `TRANSITION_NAME` map is keyed by TARGET
 * status only, not by (from, to) pair — so `allowedFrom(DIVERIFIKASI)`
 * now containing `PENAWARAN_TERKIRIM` (Task 1's matrix widening) would,
 * left alone, make the generic per-edge loop auto-render a
 * `transition_PENAWARAN_TERKIRIM` button that dispatches to
 * `IssueOrderQuote` UNCONDITIONALLY — no reservation check at all. Both
 * `ViewBookingOrder::getHeaderActions()` and
 * `ViewCemeteryOrder::getHeaderActions()` explicitly skip that one
 * (from, to) pair in their loop and call this class directly instead —
 * see those files' own edits in this task.
 */
final class IssueQuoteFromReservedPlotAction
{
    private const string TRANSITION_NAME = 'issue_quote';

    public static function make(Order $order): Action
    {
        return Action::make('issue_quote_from_reserved_plot')
            ->label('Lanjutkan dengan Plot Tercadang')
            ->icon(Heroicon::OutlinedForward)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Lanjutkan dengan plot tercadang')
            ->modalDescription('Pesanan akan langsung menuju status Penawaran Terkirim tanpa langkah konfirmasi ketersediaan manual, karena plot untuk pesanan ini sudah tercadang.')
            ->schema([Textarea::make('reason')->label('Catatan (opsional)')])
            ->visible(fn (): bool => self::qualifies($order))
            ->authorize(fn (): bool => self::authorized($order))
            ->action(fn (array $data) => self::run($order, $data['reason'] ?? null));
    }

    /**
     * The RENDER gate — order state only, no actor concept. All three
     * preconditions `IssueQuoteFromReservedPlot` itself re-asserts,
     * including the granular-tier check: the reservation's own
     * `plot -> block -> cemetery` chain must be granular-tier, because
     * aggregate-tier cemeteries can (today) still hold real plot
     * inventory — see that class's doc block for why that is an enforced
     * check here rather than an assumed impossibility.
     */
    private static function qualifies(Order $order): bool
    {
        if ($order->status() !== OrderStatus::DIVERIFIKASI) {
            return false;
        }

        $reservation = PlotReservation::activeForOrder($order);

        return $reservation?->plot?->block?->cemetery?->plot_tracking_mode === PlotTrackingMode::GRANULAR;
    }

    private static function authorized(Order $order): bool
    {
        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                app(ActorContext::class),
                self::TRANSITION_NAME,
                $order->bookingDraft?->cemetery_id,
            );
        } catch (OrderActionNotAuthorisedException) {
            return false;
        }

        return true;
    }

    private static function run(Order $order, ?string $reason): void
    {
        $actor = app(ActorContext::class);

        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                $actor,
                self::TRANSITION_NAME,
                $order->bookingDraft?->cemetery_id,
            );
        } catch (OrderActionNotAuthorisedException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $actorRef = (string) $actor->identityReference;
        $actorRole = BookingOrderResource::auditRoleFor($actor);

        try {
            app(IssueQuoteFromReservedPlot::class)(
                $order,
                CarbonImmutable::now()->addDays(30),
                $actorRef,
                $actorRole,
                $reason,
            );

            Notification::make()->success()->title('Transisi berhasil dicatat.')->send();
            redirect()->to(OrderViewUrl::for($order));
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Transisi gagal')->body($exception->getMessage())->send();
        }
    }
}
