<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Contracts\PanelAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * Explicit access check for the `/vendor` Filament panel — AC4: "THE SYSTEM
 * SHALL require each panel (`/admin`, `/vendor`, operator) to declare explicit
 * access checks. THE SYSTEM SHALL NOT grant record access on panel membership
 * alone."
 *
 * `Contracts\PanelAccessPolicy`'s own doc block records that S3-T1 shipped the
 * mechanism plus `AdminPanelAccessPolicy`, and deliberately did NOT invent a
 * rule for `/vendor` because no vendor panel existed yet. One exists now
 * (`App\Providers\Filament\VendorPanelProvider`), so this is that rule.
 *
 * ---------------------------------------------------------------------------
 * The rule: the `vendor` role AND at least one vendor scope grant
 * ---------------------------------------------------------------------------
 * Both conditions, not either. They answer different questions and neither
 * substitutes for the other:
 *
 * - The `vendor` role (`docs/security/rbac-matrix.md`'s vendor column, in
 *   `ActorRole::KNOWN_ROLES` since lane L5) says this actor is a vendor
 *   principal at all. Without it, a customer or an operator who happened to be
 *   granted a `vendor:` scope for some unrelated reason would reach the
 *   vendor's own commercial and payout surfaces.
 * - At least one active `vendor:` grant in `scope_assignments` says this actor
 *   acts for some specific vendor. Without it there is no vendor whose records
 *   the panel could show: every surface inside scopes on
 *   `Domain\Marketplace\Access\CurrentVendorScope::grantedVendorIds()`, which
 *   would be empty, so the actor would land on a panel of uniformly empty
 *   tables. Refusing at the boundary is both more honest and strictly safer
 *   than admitting them and relying on every downstream query to close.
 *
 * This differs from `AdminPanelAccessPolicy`, which is role-only, and the
 * difference is intended: `/admin` surfaces are platform-wide by design, so
 * "which records" is not a question the panel boundary can answer there. Every
 * `/vendor` surface is by definition about one vendor's own records, so
 * "acting for which vendor" is answerable at the boundary and is checked here.
 *
 * Panel entry is still not record access. This predicate says an actor may
 * open `/vendor`; it never implies which rows they see. That remains
 * `CurrentVendorScope`'s decision, applied per query by every Resource and
 * Page in the panel — the layering AC4's "SHALL NOT grant record access on
 * panel membership alone" requires. In particular an actor granted vendor A
 * passes this check and still sees nothing belonging to vendor B.
 *
 * Widening either condition is an authorization change and carries
 * `AGENTS.md` §Infrastructure-agent execution's mandatory-human-review bar.
 */
final class VendorPanelAccessPolicy implements PanelAccessPolicy
{
    private const string VENDOR_SCOPE_PREFIX = ScopeEntityType::VENDOR.':';

    public function allows(ActorContext $actor): bool
    {
        if (! $actor->isAuthenticated()) {
            return false;
        }

        if (! $actor->hasRole(ActorRole::VENDOR)) {
            return false;
        }

        foreach ($actor->scopes as $scope) {
            if (str_starts_with($scope, self::VENDOR_SCOPE_PREFIX)) {
                return true;
            }
        }

        return false;
    }
}
