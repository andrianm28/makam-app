<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\Notification\Contracts\NotificationMatrixSource;
use App\Platform\Notification\Contracts\RecipientRoleSource;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the recipients of one notification event from record scope —
 * AC6 ("resolve recipient scope from record scope: customer, admin,
 * cemetery operator, vendor, case manager, finance") and AC1 (the matrix
 * as the single source of truth for WHICH classes an event targets).
 * Task 2 of `docs/superpowers/plans/2026-08-09-platform-notifications.md`.
 *
 * ---------------------------------------------------------------------------
 * How resolution works
 * ---------------------------------------------------------------------------
 * 1. Read the matrix row for `$eventName` via `NotificationMatrixSource`.
 *    Unknown event -> log a warning and return `RecipientSet::empty()`.
 *    Never throws into business state (AC5) — a matrix miss is a no-op,
 *    not an error.
 * 2. Customer: the matrix's "Customer" column. If it is not `none`/`TBD`
 *    and `$subject->ownerRef` is non-null, emit one
 *    `RecipientRole::CUSTOMER` recipient carrying the owner reference and
 *    no scope entity (ruling 5). An anonymous record (`ownerRef === null`)
 *    yields no customer recipient even when the column is targeted —
 *    there is no one to notify.
 * 3. Admin / cemetery operator / vendor / case manager: derived from
 *    `$subject`'s single scope entity (type + id), via
 *    `RecipientRoleSource::roleForScopeEntityType()` (the provisional role
 *    seam, ruling 2) then `ScopeAssignmentResolver::actorsForEntity()`
 *    (ruling 3). The role is mapped back to its matrix column
 *    (`ROLE_COLUMNS` below); if that column is `none` or `TBD`, or the
 *    role has no column at all, nothing is emitted for it. Cross-scope
 *    leakage is prevented by construction: `actorsForEntity()` filters on
 *    `$subject`'s own `entity_id`, so an actor scoped to a *different*
 *    entity of the same type can never appear.
 * 4. Case manager / finance: currently unreachable in practice, for two
 *    different reasons. Case manager now has a matrix column (ruling 4),
 *    but every cell in it is `TBD` — no recipient policy has been decided
 *    for it yet — and `TBD` resolves to nothing, exactly like `none`, so
 *    it emits nothing until a real value replaces `TBD`. Finance has no
 *    matrix column mapping at all: it is never derivable from any scope
 *    grant (ruling 2 — `business_entity` cannot distinguish admin from
 *    finance), and guessing one would fabricate an authorization
 *    distinction. Both resolve to nothing, honestly, rather than being
 *    guessed.
 * 5. Order/case events (ruling 6): no special-cased event-name branch
 *    exists for these. `app/Domain/OrderWorkflow/` and
 *    `app/Domain/FuneralCase/` contain only `.gitkeep`, so no caller in
 *    this codebase can construct a `RecipientResolutionSubject` carrying a
 *    real owner/scope reference for one of these events yet — resolution
 *    naturally produces nothing from whatever subject a caller can
 *    actually supply today (see `RecipientResolverTest`'s ruling-6 test).
 *
 * A `$subject` carries at most ONE scope entity by design (see
 * `RecipientResolutionSubject`'s own doc block) — a real record with more
 * than one relevant scope entity is out of this task's scope; nothing in
 * the current matrix rows this task can exercise needs more than one.
 *
 * ---------------------------------------------------------------------------
 * Overlapping-grant dedupe (ruling 2's third binding condition)
 * ---------------------------------------------------------------------------
 * Recipients are deduplicated on `(actor_ref, actor_role, scope_entity_type,
 * scope_entity_id)`. With a single-scope-entity subject this tuple can only
 * repeat if `actorsForEntity()` itself returned a duplicate, which it
 * already prevents (`->distinct()`) — the dedupe here is defensive
 * belt-and-braces for when a future subject shape carries more than one
 * scope entity, documented per ruling 2's requirement rather than left
 * implicit.
 */
final class RecipientResolver
{
    private const string CUSTOMER_COLUMN = 'Customer';

    /**
     * Maps a provisional recipient role back to the matrix column that
     * targets it. Derived from `docs/contracts/notification-matrix.md`'s
     * current header row (`Customer | Admin platform | Pengelola TPU/TPS |
     * Vendor | Case manager | Finance`). Finance stays absent: it remains
     * underivable per ruling 2 (`business_entity` cannot distinguish admin
     * from finance), and its column is `TBD` anyway.
     *
     * @var array<string, string>
     */
    private const array ROLE_COLUMNS = [
        RecipientRole::PLATFORM_ADMIN => 'Admin platform',
        RecipientRole::CEMETERY_OPERATOR => 'Pengelola TPU/TPS',
        RecipientRole::VENDOR => 'Vendor',
        RecipientRole::CASE_MANAGER => 'Case manager',
    ];

    private const string NONE = 'none';

    /**
     * Matrix cell values that mean "no recipient" — `none` (an explicit
     * decision) and `TBD` (an undecided one). Both must resolve to nothing:
     * treating `TBD` as anything else would silently emit recipients for a
     * policy nobody has decided (ruling 4's refinement,
     * `docs/superpowers/plans/2026-08-10-wave1a-notifications-decisions.md`).
     *
     * @var list<string>
     */
    private const array EMPTY_VALUES = [self::NONE, 'TBD'];

    public function __construct(
        private readonly NotificationMatrixSource $matrixSource,
        private readonly ScopeAssignmentResolver $scopeResolver,
        private readonly ?RecipientRoleSource $roleSource = null,
    ) {}

    public function resolve(string $eventName, RecipientResolutionSubject $subject): RecipientSet
    {
        $row = $this->matrixSource->forEvent($eventName);

        if ($row === null) {
            Log::warning('Notification recipient resolution: unknown matrix event, resolving no recipients.', [
                'event' => $eventName,
            ]);

            return RecipientSet::empty();
        }

        $recipients = [];
        $seen = [];

        $this->resolveCustomer($row['recipients'], $subject, $recipients, $seen);
        $this->resolveScopedRecipients($row['recipients'], $subject, $recipients, $seen);

        return new RecipientSet($recipients);
    }

    /**
     * @param  array<string, string>  $matrixRecipients
     * @param  list<Recipient>  $recipients
     * @param  array<string, true>  $seen
     */
    private function resolveCustomer(array $matrixRecipients, RecipientResolutionSubject $subject, array &$recipients, array &$seen): void
    {
        if ($subject->ownerRef === null) {
            return;
        }

        if ($this->isNone($matrixRecipients[self::CUSTOMER_COLUMN] ?? self::NONE)) {
            return;
        }

        $key = $this->dedupeKey($subject->ownerRef, RecipientRole::CUSTOMER, null, null);

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $recipients[] = new Recipient($subject->ownerRef, RecipientRole::CUSTOMER, null, null);
    }

    /**
     * @param  array<string, string>  $matrixRecipients
     * @param  list<Recipient>  $recipients
     * @param  array<string, true>  $seen
     */
    private function resolveScopedRecipients(array $matrixRecipients, RecipientResolutionSubject $subject, array &$recipients, array &$seen): void
    {
        if (! $subject->hasScopeEntity()) {
            return;
        }

        /** @var string $scopeEntityType */
        $scopeEntityType = $subject->scopeEntityType;
        /** @var int|string $scopeEntityId */
        $scopeEntityId = $subject->scopeEntityId;

        $role = $this->roleSource()->roleForScopeEntityType($scopeEntityType);

        if ($role === null) {
            return;
        }

        $column = self::ROLE_COLUMNS[$role] ?? null;

        if ($column === null || $this->isNone($matrixRecipients[$column] ?? self::NONE)) {
            return;
        }

        foreach ($this->scopeResolver->actorsForEntity($scopeEntityType, $scopeEntityId) as $actorRef) {
            $key = $this->dedupeKey($actorRef, $role, $scopeEntityType, $scopeEntityId);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $recipients[] = new Recipient($actorRef, $role, $scopeEntityType, $scopeEntityId);
        }
    }

    private function roleSource(): RecipientRoleSource
    {
        return $this->roleSource ?? new ProvisionalScopeEntityRecipientRoleSource;
    }

    private function isNone(string $value): bool
    {
        $trimmed = trim($value);

        foreach (self::EMPTY_VALUES as $emptyValue) {
            if (strcasecmp($trimmed, $emptyValue) === 0) {
                return true;
            }
        }

        return false;
    }

    private function dedupeKey(int|string $actorRef, string $role, ?string $scopeEntityType, int|string|null $scopeEntityId): string
    {
        return implode('|', [$actorRef, $role, $scopeEntityType ?? '', $scopeEntityId ?? '']);
    }
}
