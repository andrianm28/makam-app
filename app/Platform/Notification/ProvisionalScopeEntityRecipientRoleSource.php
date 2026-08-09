<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Contracts\RecipientRoleSource;

/**
 * PROVISIONAL — ruling 2, `docs/superpowers/plans/2026-08-10-wave1a-
 * notifications-decisions.md`. No source of `actor_role` exists anywhere
 * in this codebase: `scope_assignments` stores no role column,
 * `ScopeGrantLevel` is authorization-capability metadata rather than a role
 * discriminator (see that class's own doc block), and
 * `ActorContext::$roles` is hardcoded `[]` by deliberate design because no
 * local roles table is authorized to exist. This class is a lane-local
 * stand-in until the real K1/K2 identity/role contract lands, isolated
 * behind `Contracts\RecipientRoleSource` so replacing it later does not
 * ripple into `RecipientResolver` — that is the entire point of the seam.
 *
 * ---------------------------------------------------------------------------
 * The mapping and why it stops where it stops
 * ---------------------------------------------------------------------------
 * Role is derived from the scope grant's `entity_type` alone — no other
 * column on `scope_assignments` is read for this purpose (in particular,
 * NOT `grant_level`: `ScopeGrantLevel::PRIVILEGED` reads as "admin-class"
 * in its own doc block, but that class's own doc block also says query-
 * level scope, and everything built on top of it, deliberately ignores
 * grant_level — it is a capability distinction, not an identity one; using
 * it here would be reaching past what ruling 2 authorized):
 *
 * - `cemetery` -> cemetery operator (`RecipientRole::CEMETERY_OPERATOR`) —
 *   the matrix's "Pengelola TPU/TPS" column.
 * - `vendor` -> vendor (`RecipientRole::VENDOR`) — the matrix's "Vendor"
 *   column.
 * - `case` -> case manager (`RecipientRole::CASE_MANAGER`). The matrix has
 *   no "case manager" column today (ruling 4 is blocked pending a
 *   decision on 34 new cell values), so this role can never actually match
 *   a matrix column right now — `RecipientResolver` simply finds no column
 *   to check and resolves nothing for it. That is a correct, honest
 *   no-op, not a bug in this class.
 * - `business_entity` -> platform admin (`RecipientRole::PLATFORM_ADMIN`)
 *   — the matrix's "Admin platform" column. Finance is NOT derivable from
 *   this: `business_entity` cannot distinguish an admin grant from a
 *   finance grant, and guessing would fabricate an authorization
 *   distinction. Consistent anyway, because the matrix has no "finance"
 *   column either (also blocked by ruling 4).
 * - `order`, `grave` -> `null`. Neither implies a recipient role by
 *   itself: an order-scoped grant's *actual* holder role (case manager?
 *   assigned vendor?) is exactly the ambiguity ruling 6 defers — no
 *   `OrderWorkflow`/`FuneralCase` domain model exists to disambiguate it
 *   today — and a grave is a physical location, not an actor-holding
 *   entity type in this mapping.
 *
 * ---------------------------------------------------------------------------
 * Overlapping grants (ruling 2's third binding condition)
 * ---------------------------------------------------------------------------
 * An actor legitimately holds grants of more than one `entity_type` at
 * once (e.g. a cemetery operator who is also a vendor), so this class
 * intentionally has no notion of "the" role for an actor — only "the role
 * a grant of this type implies". `RecipientResolver` calls this once per
 * scope entity type relevant to a record and de-duplicates the resulting
 * recipients on the tuple `(actor_ref, actor_role, scope_entity_type,
 * scope_entity_id)`, so the same actor legitimately appears more than once
 * across different roles/entities, but never twice for the same one. See
 * `RecipientResolver`'s own doc block for where that dedupe happens.
 */
final class ProvisionalScopeEntityRecipientRoleSource implements RecipientRoleSource
{
    public function roleForScopeEntityType(string $scopeEntityType): ?string
    {
        ScopeEntityType::assertKnown($scopeEntityType);

        return match ($scopeEntityType) {
            ScopeEntityType::CEMETERY => RecipientRole::CEMETERY_OPERATOR,
            ScopeEntityType::VENDOR => RecipientRole::VENDOR,
            ScopeEntityType::CASE_RECORD => RecipientRole::CASE_MANAGER,
            ScopeEntityType::BUSINESS_ENTITY => RecipientRole::PLATFORM_ADMIN,
            default => null,
        };
    }
}
