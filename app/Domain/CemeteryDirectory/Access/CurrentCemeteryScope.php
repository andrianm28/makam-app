<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory\Access;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * "Which cemeteries is the current actor acting for?" — the single answer
 * every `/operator` panel surface reads before it builds a query.
 *
 * Mirrors `App\Domain\Marketplace\Access\CurrentVendorScope` exactly — see
 * that class's own doc block for the full reasoning: the answer comes from
 * `scope_assignments` rows of entity type `cemetery` and nothing else,
 * empty is the safe/deny-by-default answer for `whereIn(...)`, and the
 * enforcement point is the panel boundary (every Resource/Page under
 * `App\Filament\Operator`) rather than a model global scope — because, like
 * `vendor_listings`, some cemetery-owned data (e.g. the public cemetery
 * directory) is read by unauthenticated guests who by definition hold no
 * cemetery grant.
 */
final class CurrentCemeteryScope
{
    public function __construct(
        private readonly ScopeAssignmentResolver $scopes,
    ) {}

    /**
     * Cemetery ids (`cemeteries.id`, a UUID) the current actor holds an
     * active, non-revoked grant for. Empty for a guest and for an actor
     * with no cemetery grant — see the class doc block on why that is the
     * safe result and not an error.
     *
     * @return list<string>
     */
    public function grantedCemeteryIds(): array
    {
        $actorIdentifier = $this->scopes->currentActorIdentifier();

        if ($actorIdentifier === null) {
            return [];
        }

        return $this->scopes->grantedEntityIds($actorIdentifier, ScopeEntityType::CEMETERY);
    }

    public function hasAnyGrant(): bool
    {
        return $this->grantedCemeteryIds() !== [];
    }

    /**
     * The cemetery a newly created record should be stamped with when the
     * actor holds exactly one grant, or `null` when they hold none or
     * several — same "don't guess for the actor" reasoning as
     * `CurrentVendorScope::defaultVendorId()`.
     */
    public function defaultCemeteryId(): ?string
    {
        $granted = $this->grantedCemeteryIds();

        return count($granted) === 1 ? $granted[0] : null;
    }

    /**
     * `true` iff the current actor holds an active grant for `$cemeteryId`.
     * The server-side re-check every write path runs against client-
     * supplied input — see `CurrentVendorScope::allows()`'s own doc block.
     */
    public function allows(?string $cemeteryId): bool
    {
        if ($cemeteryId === null || $cemeteryId === '') {
            return false;
        }

        return in_array($cemeteryId, $this->grantedCemeteryIds(), true);
    }

    /**
     * Granted cemeteries as `id => name`, for a future create form's
     * cemetery picker.
     *
     * @return array<string, string>
     */
    public function grantedCemeteryOptions(): array
    {
        $granted = $this->grantedCemeteryIds();

        if ($granted === []) {
            return [];
        }

        return Cemetery::query()
            ->whereIn('id', $granted)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
