<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Models\InAppNotification;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ONLY way any read surface may query the in-app notification inbox —
 * task-5-brief.md Step 1's "scope-filtered list ... no existence leak": a
 * user sees exactly the `in_app_notifications` rows that both (a) name them
 * as the recipient and (b) sit inside a scope the user actually holds.
 *
 * ---------------------------------------------------------------------------
 * Why `recipient_ref` alone is NOT an authorization boundary
 * ---------------------------------------------------------------------------
 * Every in-app row is written with a real `scope_entity_type`/
 * `scope_entity_id` (from the resolved recipient — see `Actions\
 * RecordInAppNotification`), and a row addressed to one cemetery must never
 * become visible to an actor who shares a reference shape but holds no grant
 * on that cemetery. This class re-derives the actor's grants from
 * `scope_assignments` via `ScopeAssignmentResolver`, then constrains every
 * row to a scope the actor actually owns. It never touches `ActorContext::
 * $scopes` (always `[]` today — see that class's doc block); it reads
 * `scope_assignments` directly, the same source
 * `ScopeAssignmentGlobalScope` reads.
 *
 * ---------------------------------------------------------------------------
 * Closed by default, in the same direction as ScopeAssignmentGlobalScope
 * ---------------------------------------------------------------------------
 * An actor with zero grants sees nothing scoped; a row carrying a scope
 * entity type this codebase does not recognise yet matches nothing. The
 * only rows visible without any grant are genuinely unscoped ones
 * (`scope_entity_type IS NULL`) that also name the actor — and none exist
 * today, because `RecipientResolver` never resolves a scoped recipient
 * without a scope entity; the branch is kept for forward compatibility, not
 * because production rows exercise it.
 *
 * Consumers (the Livewire inbox component, the Filament page badge) call
 * only this class — never a bare `InAppNotification::query()`, which has no
 * scope constraint and would leak every row in the table.
 */
final class InAppNotificationInboxQuery
{
    public function __construct(
        private readonly ScopeAssignmentResolver $scopeResolver,
    ) {}

    /**
     * The current actor's scoped inbox, newest first. A guest resolves to a
     * query that matches nothing (closed default), never an unconstrained
     * one.
     */
    public function forCurrentActor(): Builder
    {
        $actorRef = $this->scopeResolver->currentActorIdentifier();

        if ($actorRef === null) {
            return $this->closedQuery();
        }

        return $this->forActor($actorRef);
    }

    /**
     * The scoped inbox for an explicit actor reference, newest first. Used
     * both by `forCurrentActor()` and by `Actions\MarkInAppNotificationRead`
     * to re-verify a single record's visibility before the read transition.
     */
    public function forActor(int|string $actorRef): Builder
    {
        return InAppNotification::query()
            ->where('recipient_ref', (string) $actorRef)
            ->where(function (Builder $query) use ($actorRef): void {
                $query->whereNull('scope_entity_type');

                foreach ($this->grantsByEntityType($actorRef) as $entityType => $entityIds) {
                    $query->orWhere(function (Builder $scoped) use ($entityType, $entityIds): void {
                        $scoped
                            ->where('scope_entity_type', $entityType)
                            ->whereIn('scope_entity_id', $entityIds);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * The unread (`read_at IS NULL`) count for the current actor's scoped
     * inbox — the admin panel's navigation badge (task-5-brief.md Step 2).
     */
    public function unreadCountForCurrentActor(): int
    {
        $actorRef = $this->scopeResolver->currentActorIdentifier();

        if ($actorRef === null) {
            return 0;
        }

        return (int) $this->forActor($actorRef)->whereNull('read_at')->count();
    }

    /**
     * The actor's active grants, grouped by entity type. `grantedEntityIds()`
     * is called only for the known types it validates, so no type can throw.
     *
     * @return array<string, list<string>>
     */
    private function grantsByEntityType(int|string $actorRef): array
    {
        $grants = [];

        foreach (ScopeEntityType::KNOWN_TYPES as $entityType) {
            $grants[$entityType] = $this->scopeResolver->grantedEntityIds($actorRef, $entityType);
        }

        return $grants;
    }

    private function closedQuery(): Builder
    {
        return InAppNotification::query()->whereRaw('1 = 0');
    }
}
