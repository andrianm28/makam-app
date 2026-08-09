# Wave 1a — Platform Notifications Rulings

**Approved:** 10 Aug 2026 (user rulings, relayed via the Track A coordinator)
**Lane:** L2 `platform-notifications` — branch `lane/l2-notifications`
**Plan doc:** [`2026-08-09-platform-notifications.md`](2026-08-09-platform-notifications.md)

Records the six rulings that unblocked Task 2 (recipient resolution) after the
lane escalated with six verified missing contracts. Follows the artifact
pattern set by [`2026-08-09-wave0-decisions.md`](2026-08-09-wave0-decisions.md).

This document records decisions. It is not a rival source of canonical data —
`docs/contracts/notification-matrix.md` remains the authority for the event ×
recipient × channel matrix (AC1), and `docs/contracts/event-catalog.md` remains
the authority for event names.

---

## Why this was escalated

Task 2 required six contracts that do not exist in the repository. Each was
independently verified against the code before escalating; none was a subagent
misreading. Building past them would have meant inventing an authorization
model — deciding who receives notification content — and then writing tests
asserting that invention. `AGENTS.md` §Infrastructure-agent execution requires
human review before authorization and privacy changes.

---

## Ruling 1 — Event-name mapping

**Ruled:** the seed stores machine event names matching `event-catalog.md`,
not the raw human matrix labels. Land as a Task 1 fix round before Task 2,
because Tasks 3-6 depend on event identity too.

**Application question raised 10 Aug 2026.** The ruling could not be applied
literally. Of the 17 matrix rows, only 7 have a clean catalogue counterpart;
2 ("Order processing", "Order completed") both correspond to
`order.status_changed.v1`, and `notification_templates.event_name` is
`unique()`, so writing machine names collides at seed time; the remaining 8
have no catalogue counterpart at all. Applying the ruling literally would
require inventing 8+ event names absent from the canonical catalogue that no
producer emits.

**Refinement approved 10 Aug 2026 — implementation-level correction to avoid
fabrication, not a new user decision.** Keep `event_name` as the matrix label
(the matrix's own row identity, canonical per AC1). Add a **nullable,
non-unique `outbox_event_name`** column carrying the machine key only for the
7 rows with a real catalogue counterpart. Leave it NULL for the 2 colliding
order rows and the 8 rows with no counterpart. Dispatch keys on
`outbox_event_name`; NULL means no template matches and nothing is sent — the
same honest-absence pattern as Task 1's nullable `default_channel` ruling.

**Follow-up question, deliberately left open:** whether
`order.status_changed.v1` should be discriminated by status value so that
"Order processing" and "Order completed" can be addressed separately. That is
a template-key shape change. It is NOT decided here — whoever builds order
dispatch must resolve it rather than assume a shape.

**Status:** approach approved. Applied as a Task 1 fix round by the lane
agent — explicitly outside Task 2's scope.

## Ruling 2 — Provisional actor-role concept

**Ruled:** AUTHORIZED for this lane's own use, with three binding conditions:

1. Ledgered and doc-blocked as **PROVISIONAL**, pending the real K1/K2
   identity/role contract, using the disclosure pattern this repo already
   applies to uncatalogued gaps (see `ActorContext`'s own doc block).
2. Isolated behind a single small, swappable seam so replacing it later does
   not ripple through the resolver.
3. Overlapping-grant classification is the lane's call, with documented
   reasoning.

**Context:** no source of `actor_role` exists anywhere. `ScopeAssignment`
stores no role; `ScopeGrantLevel` is authorization metadata, not a role
discriminator; `ActorContext::$roles` is hardcoded `[]` in
`LocalUsersTableIdentityAccessAdapter` by deliberate design, because no local
roles table is authorized to exist.

## Ruling 3 — Reverse scope lookup

**Ruled:** this lane owns adding `actorsForEntity(entityType, entityId)` (or
equivalent) to `ScopeAssignmentResolver` in IdentityAccess. Because
IdentityAccess is another module's boundary, the addition carries a doc
comment explaining why notifications needed it, so the next person working in
IdentityAccess is not surprised.

**Context:** `ScopeAssignmentResolver` had only actor-first methods
(`grantedEntityIds`, `scopeStringsForActor`). Recipient resolution needs the
inverse — which actors hold a grant on a given entity.

## Ruling 4 — Matrix vs AC6 recipient classes

**Ruled:** extend `docs/contracts/notification-matrix.md` with "case manager"
and "finance" columns so it covers all six classes
`.kiro/specs/platform-notifications/requirements.md:16` (AC6) requires.
Additive only — existing rows, columns, and cells are not touched.

**Application question raised 10 Aug 2026.** The structural change is
unambiguous, but two columns across 17 rows is 34 new cells whose values are
recipient policy. Filling them with `none` is not a neutral default — it
asserts that case managers and finance are never notified of anything, which
would become canonical.

**Refinement approved 10 Aug 2026 — implementation-level correction to avoid
fabrication, not a new user decision.** Seed all 34 new cells with an explicit
`TBD` marker rather than `none`. `TBD` is honest about being unfilled; `none`
would be an affirmative, uninvestigated policy claim. Behaviourally this costs
nothing, because rulings 2 and 6 already defer case-manager and finance
resolution. A `TBD` cell resolves to no recipients, exactly as `none` would,
but the document does not claim the question was decided.

**Status:** approach approved. Applied by the lane agent after Task 2's
implementer completes — sequenced deliberately, because adding columns changes
the recipient classes `RecipientResolver` reads and would otherwise shift the
matrix under a running implementation. Explicitly outside Task 2's scope.

## Ruling 5 — `RecipientSet` shape

**Ruled:** not a policy question. The lane defines the concrete field list
from what channel resolution and role/scope resolution actually need.

## Ruling 6 — Order and case events

**Ruled:** seed `notification_templates` rows for order and case events now;
ledger recipient resolution for those events as explicitly blocked pending the
`FuneralCase` and `OrderWorkflow` domain models.

**Context:** `app/Domain/FuneralCase/` and `app/Domain/OrderWorkflow/` contain
only `.gitkeep`. There is no order model and no case model, so resolution via
`ScopeAssignment` on the record's order/case is unimplementable today.

---

## Consequences for the lane

- Task 1 gains a fix round applying ruling 1's approved refinement
  (`outbox_event_name`).
- Task 2 proceeds under rulings 2, 3, 5, and 6.
- Tasks 3-6 key dispatch on `outbox_event_name`, and must treat NULL as "no
  template matches, nothing sent" rather than as an error.
- Whoever builds order dispatch owns the open `order.status_changed.v1`
  status-discrimination question recorded under ruling 1.
- The provisional role seam (ruling 2) is a known replacement point when the
  K1/K2 contract lands; it is not a permanent design.
