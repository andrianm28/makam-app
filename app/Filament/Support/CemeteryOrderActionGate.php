<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Domain\OrderWorkflow\Models\Order;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;

/**
 * The shared actor gate for the order-scoped operational actions
 * (`ReservePlotAction`, `PlotReservationLifecycleActions`) — two mutually
 * independent paths, either of which admits.
 *
 * 1. The `/admin` path, unchanged since P3: the actor passes
 *    `BookingOrderResource::canAccess()` (the platform-wide master-data
 *    gate) AND holds one of `PLATFORM_WIDE_ROLES`. These roles are not
 *    cemetery-scoped and hold no cemetery grants, so no cemetery check
 *    applies to them — applying one would deny them every order.
 *
 * 2. The `/operator` path, new in Phase C: the actor passes
 *    `CemeteryOrderResource::canAccess()` (role + at least one active
 *    cemetery grant) AND the order's own cemetery is among their grants.
 *
 * The per-order check in path 2 is the load-bearing half. Neither caller has
 * a domain authorizer of its own — all of their authorization lives here.
 * Without the `CurrentCemeteryScope::allows()` call, an operator granted
 * cemetery A could act against cemetery B's order, because nothing
 * downstream re-checks.
 *
 * An actor holding BOTH an admin-tier role and `cemetery_operator` is
 * admitted by path 1 — correctly: they genuinely hold platform-wide
 * authority, and the narrower role does not subtract from it.
 *
 * Both call sites re-check this as the first act of their `run()`, because
 * "the button was not rendered" is not a security property.
 *
 * This used to be two byte-for-byte identical private methods, one per
 * caller — extracted here so the enforcement has one home instead of two
 * copies that can drift out of lockstep.
 */
final class CemeteryOrderActionGate
{
    /**
     * The PLATFORM-WIDE operational admission list — actors whose authority
     * is not scoped to any cemetery. Deliberately not finance: these are
     * non-money-adjacent actions, and finance's domain is money.
     *
     * `ActorRole::CEMETERY_OPERATOR` is deliberately NOT on this list even
     * though it is admitted by `allows()`. It is a cemetery-scoped role, so
     * it is answered by its own branch, which additionally requires the
     * order's cemetery to be among the actor's grants. Folding it into this
     * list would have meant either applying the cemetery check to the
     * platform-wide roles (which hold no grants, so every order would be
     * denied) or skipping it for everyone (cross-tenant exposure).
     *
     * @var list<string>
     */
    private const array PLATFORM_WIDE_ROLES = [
        ActorRole::OPERATOR,
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::ADMIN,
    ];

    public static function allows(Order $order): bool
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
}
