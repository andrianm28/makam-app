<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Access;

use App\Domain\Marketplace\Models\Vendor;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * "Which vendors is the current actor acting for?" — the single answer every
 * `/vendor` panel surface reads before it builds a query.
 *
 * The answer comes from `scope_assignments` rows of entity type `vendor`, and
 * from nothing else. In particular it is NOT read from `vendor_users`: that
 * table's own model doc block is explicit that it carries membership metadata
 * only and that "authorization is decided by `scope_assignments`, never by
 * this table". A vendor whose `vendor_users` row still exists but whose scope
 * grant was revoked must lose access immediately, which is only true if the
 * grant table is the sole input.
 *
 * ---------------------------------------------------------------------------
 * Empty is the safe answer, and callers must keep it that way
 * ---------------------------------------------------------------------------
 * `grantedVendorIds()` returns `[]` for a guest and for an authenticated actor
 * holding no vendor grant. Every caller feeds that list to `whereIn(...)`,
 * which Laravel compiles to an always-false clause on an empty array — so the
 * "no grants" state closes the query rather than leaving it unconstrained.
 * That is the same deny-by-default direction `ScopeAssignmentGlobalScope`
 * takes, and for the same reason: an open default would expose every vendor's
 * records to every actor until someone remembered to grant rows.
 *
 * ---------------------------------------------------------------------------
 * Why this reads the grant table directly instead of using
 * `ScopeAssignmentGlobalScope`
 * ---------------------------------------------------------------------------
 * That global scope is mandatory-by-construction and would be the stronger
 * mechanism if the vendor-owned models were only ever read by the vendor
 * panel. They are not. `vendor_listings` and `service_areas` are the
 * catalogue the PUBLIC marketplace browses (lane L11), where the reader is an
 * unauthenticated guest who by definition holds no vendor grant. Attaching a
 * deny-by-default global scope to those models would make the public
 * marketplace return zero listings to every visitor — the scope cannot tell
 * "guest browsing the catalogue" from "actor reaching for another vendor's
 * records".
 *
 * So the enforcement point is the panel boundary instead: every Resource and
 * Page under `App\Filament\Vendor` scopes its own query through this class.
 * The weakness of a per-surface mechanism is that a future surface can forget
 * to apply it — which is exactly what
 * `tests/Feature/Filament/Vendor/VendorPanelScopingTest` exists to prevent: it
 * enumerates every class in `app/Filament/Vendor/**` and fails when one is not
 * scoped, so "forgot to scope the new resource" is a CI failure rather than a
 * cross-vendor read.
 */
final class CurrentVendorScope
{
    public function __construct(
        private readonly ScopeAssignmentResolver $scopes,
    ) {}

    /**
     * Vendor ids (`vendors.id`, a UUID) the current actor holds an active,
     * non-revoked grant for. Empty for a guest and for an actor with no
     * vendor grant — see the class doc block on why that is the safe result
     * and not an error.
     *
     * @return list<string>
     */
    public function grantedVendorIds(): array
    {
        $actorIdentifier = $this->scopes->currentActorIdentifier();

        if ($actorIdentifier === null) {
            return [];
        }

        return $this->scopes->grantedEntityIds($actorIdentifier, ScopeEntityType::VENDOR);
    }

    public function hasAnyGrant(): bool
    {
        return $this->grantedVendorIds() !== [];
    }

    /**
     * The vendor a newly created record should be stamped with when the actor
     * holds exactly one grant, or `null` when they hold none or several.
     *
     * `null` for the several case is deliberate rather than "pick the first":
     * an actor granted two vendors has genuinely not said which one a new
     * listing belongs to, and choosing for them would silently file a record
     * under the wrong vendor. The create forms turn a `null` here into a
     * required vendor picker limited to `grantedVendorIds()`.
     */
    public function defaultVendorId(): ?string
    {
        $granted = $this->grantedVendorIds();

        return count($granted) === 1 ? $granted[0] : null;
    }

    /**
     * `true` iff the current actor holds an active grant for `$vendorId`.
     *
     * This is the server-side re-check every create path runs against the
     * `vendor_id` its form submitted. The form only ever OFFERS granted
     * vendors, but a form field is client-supplied input and Livewire will
     * accept whatever value is posted for it — so the offered-options list is
     * a usability affordance and this method is the authorization decision.
     */
    public function allows(?string $vendorId): bool
    {
        if ($vendorId === null || $vendorId === '') {
            return false;
        }

        return in_array($vendorId, $this->grantedVendorIds(), true);
    }

    /**
     * Granted vendors as `id => name`, for the create forms' vendor picker.
     *
     * @return array<string, string>
     */
    public function grantedVendorOptions(): array
    {
        $granted = $this->grantedVendorIds();

        if ($granted === []) {
            return [];
        }

        return Vendor::query()
            ->whereIn('id', $granted)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
