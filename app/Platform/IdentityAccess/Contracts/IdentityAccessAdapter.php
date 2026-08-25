<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Contracts;

use App\Platform\IdentityAccess\ActorContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The module design.md names: "`IdentityAccessAdapter` ... Wraps the K1/K2
 * contract and exposes one `ActorContext` per request."
 *
 * K1/K2's real interface has not been seen by this repository (tasks.md's
 * own NOT TESTED note: "The K1/K2 contract is external and its actual
 * interface has not been seen ... must be reconciled with the real K1/K2
 * spec before implementation"). This interface is therefore written as the
 * seam that isolates every consumer of `ActorContext` from that unknown —
 * consumers depend on THIS contract, never on a concrete adapter class.
 * When the real K1/K2 contract is available, the correct change is a new
 * implementation of this interface (e.g. a future `K1K2IdentityAccessAdapter`)
 * rewiring the single container binding in
 * `Providers\IdentityAccessServiceProvider`, not a rewrite of every
 * controller/policy/Resource that reads an `ActorContext`.
 *
 * The one concrete implementation shipped by this batch,
 * `Adapters\LocalUsersTableIdentityAccessAdapter`, is explicitly the
 * MVP/local-auth stand-in — it is backed by the existing `users` table and
 * Laravel's session guard, NOT an assumption about what K1/K2 itself looks
 * like.
 */
interface IdentityAccessAdapter
{
    /**
     * Resolve the platform-local `ActorContext` for the given identity.
     *
     * `$identity` is whatever the calling guard currently has authenticated
     * (or `null` for an unauthenticated/guest request) — this method does
     * NOT decide who is logged in; that remains the guard's job (AC1: same
     * origin session auth, no token issuance). This method's only
     * responsibility is mapping an already-authenticated identity onto the
     * platform-local authorization state (roles, scopes, MFA state,
     * last-authenticated-at) that `ActorContext` carries.
     */
    public function resolveActorContext(?Authenticatable $identity): ActorContext;
}
