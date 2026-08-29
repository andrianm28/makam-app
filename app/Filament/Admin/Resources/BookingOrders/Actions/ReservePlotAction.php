<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use App\Filament\Support\OrderViewUrl;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The 'Reservasi Plot' header action on `ViewBookingOrder` — the booking
 * integration of the P3 reservation module (Lane 3, Task 5 of the P3 plan).
 *
 * ---------------------------------------------------------------------------
 * Two-layer enforcement, same shape as `TransitionOrderAction`
 * ---------------------------------------------------------------------------
 * `->visible()` is the RENDER gate: it answers "may this button be drawn at
 * all" from ORDER state — the order must be at a reservable status, must not
 * already hold an active reservation, and its draft's cemetery must resolve.
 * `->authorize()` is the ACTOR gate: the reservation is an operational
 * (non-money) action, so two paths admit: an `/admin` actor holding one of
 * the platform-wide operational roles, or a `cemetery_operator` whose
 * grants include this order's own cemetery. Finance is deliberately
 * excluded from both (finance's domain is money).
 * `run()` re-checks the same actor gate as its first act, because "the
 * button was not rendered" is not a security property.
 *
 * ---------------------------------------------------------------------------
 * Domain-action signature note — the P3 domain classes land at merge time
 * ---------------------------------------------------------------------------
 * This lane (3) runs in parallel with Lane 1 (PlotInventory) and Lane 2
 * (PlotReservation); the classes this action references
 * (`CemeteryBlock`, `GravePlot`, `PlotState`, `PlotReservation`,
 * `ReservePlot`) are written against the plan's Task 1/3 signatures and
 * resolve once lanes 1 and 2 land (merge order 1 → 2 → 3).
 */
final class ReservePlotAction
{
    /**
     * The two statuses at which a reservation is still operationally
     * meaningful — the order is being worked by the back office and has not
     * yet moved into quoting/payment.
     *
     * @var list<string>
     */
    private const array RESERVABLE_STATUSES = [
        OrderStatus::DIVERIFIKASI->value,
        OrderStatus::MENUNGGU_KETERSEDIAAN->value,
    ];

    /**
     * The PLATFORM-WIDE operational admission list — actors whose authority
     * is not scoped to any cemetery. Deliberately not finance: reserving a
     * plot is not a money-adjacent action, and finance's domain is money.
     *
     * `ActorRole::CEMETERY_OPERATOR` is deliberately NOT on this list even
     * though it is admitted by `roleAllowed()`. It is a cemetery-scoped
     * role, so it is answered by its own branch, which additionally
     * requires the order's cemetery to be among the actor's grants. Folding
     * it into this list would have meant either applying the cemetery check
     * to the platform-wide roles (which hold no grants, so every order would
     * be denied) or skipping it for everyone (cross-tenant exposure).
     *
     * @var list<string>
     */
    private const array PLATFORM_WIDE_ROLES = [
        ActorRole::OPERATOR,
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::ADMIN,
    ];

    public static function make(Order $order): Action
    {
        return Action::make('reserve_plot')
            ->label('Reservasi Plot')
            ->icon(Heroicon::OutlinedMapPin)
            ->visible(fn (): bool => self::visibleFor($order))
            ->authorize(fn (): bool => self::roleAllowed($order))
            ->schema([
                Select::make('plot_id')
                    ->label('Plot')
                    ->options(fn (): array => self::availablePlots($order)
                        ->mapWithKeys(fn (GravePlot $plot): array => [
                            (string) $plot->getKey() => "{$plot->block->code} — {$plot->slot}",
                        ])
                        ->all())
                    ->required(),
            ])
            ->action(fn (array $data) => self::run($order, $data['plot_id']));
    }

    /**
     * The render gate — order-state only. `->authorize()` carries the actor
     * gate; `->visible()` carries the order-state gate.
     */
    private static function visibleFor(Order $order): bool
    {
        if (! in_array($order->status()->value, self::RESERVABLE_STATUSES, true)) {
            return false;
        }

        if (PlotReservation::activeForOrder($order) !== null) {
            return false;
        }

        return self::draftCemeteryResolves($order);
    }

    /**
     * The actor gate — two mutually independent paths, either of which
     * admits.
     *
     * 1. The `/admin` path, unchanged since P3: the actor passes
     *    `BookingOrderResource::canAccess()` (the platform-wide master-data
     *    gate) AND holds one of `PLATFORM_WIDE_ROLES`. These roles are not
     *    cemetery-scoped and hold no cemetery grants, so no cemetery check
     *    applies to them — applying one would deny them every order.
     *
     * 2. The `/operator` path, new in Phase C: the actor passes
     *    `CemeteryOrderResource::canAccess()` (role + at least one active
     *    cemetery grant) AND the order's own cemetery is among their
     *    grants.
     *
     * The per-order check in path 2 is the load-bearing half. Unlike
     * `TransitionOrderAction`, which delegates to the cemetery-aware
     * `OrderTransitionAuthorizerContract`, this action has NO domain
     * authorizer — all of its authorization lives here. Without the
     * `CurrentCemeteryScope::allows()` call, an operator granted cemetery A
     * could reserve a plot against cemetery B's order, because nothing
     * downstream re-checks.
     *
     * An actor holding BOTH an admin-tier role and `cemetery_operator` is
     * admitted by path 1 — correctly: they genuinely hold platform-wide
     * authority, and the narrower role does not subtract from it.
     *
     * `run()` re-checks this as its first act, because "the button was not
     * rendered" is not a security property.
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
     * The reservation is only offered when the draft's cemetery actually
     * resolves — a draft whose cemetery was cleared or deleted (the
     * `booking_drafts.cemetery_id` FK is SET NULL) cannot anchor a plot
     * selection, so the action hides rather than fail at run time.
     */
    private static function draftCemeteryResolves(Order $order): bool
    {
        /** @var ?BookingDraft $draft */
        $draft = $order->bookingDraft;

        if ($draft === null || $draft->cemetery_id === null) {
            return false;
        }

        return Cemetery::query()->whereKey($draft->cemetery_id)->exists();
    }

    /**
     * The plot candidates — available plots of the order's cemetery, class-
     * filtered when the draft carries a `cemetery_package_id`. This is the
     * single source for BOTH the select's options and the run-time re-read:
     * an option can never name a plot this query would not return, and a
     * wire-level call can never pass a plot it would not return either.
     *
     * @return Collection<int, GravePlot>
     */
    private static function availablePlots(Order $order): Collection
    {
        /** @var ?BookingDraft $draft */
        $draft = $order->bookingDraft;

        if ($draft === null || $draft->cemetery_id === null) {
            return new Collection;
        }

        return GravePlot::query()
            ->with('block')
            ->whereIn(
                'block_id',
                CemeteryBlock::query()->where('cemetery_id', $draft->cemetery_id)->pluck('id'),
            )
            ->where('plot_state', PlotState::AVAILABLE)
            ->when(
                $draft->cemetery_package_id,
                fn ($query) => $query->where('cemetery_package_id', $draft->cemetery_package_id),
            )
            ->orderBy('slot')
            ->get();
    }

    /**
     * The enforcement path: re-checks the actor gate, re-reads the chosen
     * plot through the SAME filtered query that built the options, then
     * dispatches to the owning domain Action. Every failure surfaces as a
     * Filament notification — plot state is only ever changed by
     * `ReservePlot`, never by this class.
     */
    private static function run(Order $order, int|string $plotId): void
    {
        if (! self::roleAllowed($order)) {
            Notification::make()
                ->danger()
                ->title('Anda tidak berwenang mereservasi plot.')
                ->send();

            return;
        }

        $plot = self::availablePlots($order)->first(
            fn (GravePlot $plot): bool => (string) $plot->getKey() === (string) $plotId,
        );

        if ($plot === null) {
            Notification::make()
                ->danger()
                ->title('Plot tidak ditemukan atau tidak tersedia.')
                ->send();

            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(ReservePlot::class)(
                $plot,
                $order,
                (string) $actor->identityReference,
                BookingOrderResource::auditRoleFor($actor),
            );

            Notification::make()->success()->title('Plot berhasil direservasi.')->send();
            redirect()->to(OrderViewUrl::for($order));
        } catch (\Throwable $exception) {
            Notification::make()->danger()->title('Reservasi gagal')->body($exception->getMessage())->send();
        }
    }
}
