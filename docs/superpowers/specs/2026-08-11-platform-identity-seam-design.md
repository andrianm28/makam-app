# Platform Identity Seam — actor→role and actor→scope resolution

Design doc for lane L5 (`lane/l5-identity-seam`), Wave 2. Written 11 Aug 2026.

Implements the two Round A rulings recorded for this lane: wire `scope_assignments`
into `ActorContext`, and build a real local roles table instead of continuing to
wait on the unseen external K1/K2 identity contract.

## Problem

`ActorContext` is the single per-request "who is acting and what do they carry"
value object (`AGENTS.md` source-precedence → `platform-identity-and-access`
requirements.md AC8). Two of its fields have never been populated:

- `$scopes` is hardcoded `[]` at
  `app/Platform/IdentityAccess/Adapters/LocalUsersTableIdentityAccessAdapter.php:53`,
  even though `scope_assignments`, `ScopeAssignmentResolver`, and
  `ScopeAssignmentGlobalScope` all exist and work.
- `$roles` is hardcoded `[]` at the same site, because no roles table exists
  anywhere in the repository.

The consequence is that authorization is inert platform-wide. Every role check
fails closed, not because the actor lacks the role but because the vocabulary
has no source. This is documented in the affected classes themselves rather
than being a silent gap — `FinanceLedgerReadAuthorizer`, `FinanceReconciliation
Authorizer`, `FinanceVendorPayableAuthorizer`,
`FinanceOrRestrictedAdminPayoutAuthorizer`, and
`DocumentVault\Policies\DocumentAccessPolicy` each carry a doc block saying so.

## Non-goals

- Closing the `FaqArticlesTable` authorization gap
  (`docs/planning/retrofit-backlog.md:88`). That Critical finding is unblocked
  by this lane but fixed by a later one.
- Any HTTP surface for granting roles, self-service role request, role
  hierarchy/inheritance, or a general permissions framework. This lane builds
  actor→role and actor→scope resolution and nothing beyond it.
- Replacing the `IdentityAccessAdapter` seam. A future K1/K2-backed adapter
  remains a drop-in binding swap; this lane makes the local adapter honest, it
  does not remove the interface.

## Decisions

### 1. Role vocabulary is discovered, not invented

The closed list is the union of roles already load-bearing in shipped code plus
the RBAC matrix's own column set. Nothing here is new nomenclature:

| Role | Already consumed by |
|---|---|
| `admin` | `DocumentAccessPolicy::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS` |
| `restricted_admin` | `FinanceOrRestrictedAdminPayoutAuthorizer::RESTRICTED_ADMIN_ROLE` |
| `operator` | `DocumentAccessPolicy` |
| `case_manager` | `DocumentAccessPolicy` |
| `customer` | `DocumentAccessPolicy` |
| `vendor` | `docs/security/rbac-matrix.md` column; no code consumer yet |
| `finance` | 4 `FinancialLedger` authorizers |
| `system` | `FinanceVendorPayableAuthorizer::SYSTEM_ROLE` (unattended/machine actor) |

`issuer` and `auditor` are deliberately excluded. The RBAC matrix mentions both,
but folds them into one column with finance and no code consumes them. The list
is one-line-extensible (same shape as `ScopeEntityType::KNOWN_TYPES`), so adding
them when a real consumer exists is an application-layer change, not a migration.

**`guest` and `authenticated_actor` are not roles and must be rejected.** Both
are audit sentinels (`DocumentAccessPolicy:124,130`) meaning "no role applies."
If either became grantable, an audit row could claim an actor holds a role that
the authorization layer reads as its absence. `ActorRole::assertKnown()` rejects
them by construction; a test pins this explicitly.

### 2. Roles are orthogonal to scopes

A role answers *what kind of actor is this* (global capability class). A scope
answers *which records may this actor touch* (row-level grant). They compose;
neither subsumes the other. The already-shipped authorizers demonstrate exactly
this composition — each checks a role **and** a `scope_assignments` grant, and
refuses if either is missing.

Therefore `actor_roles` has **no `entity_id` column**. A per-entity role would
duplicate `scope_assignments` and recreate precisely the confusion the Wave 1f
ruling caught: `ProvisionalScopeEntityRecipientRoleSource` answers *entity
type → notification recipient role*, a different question from *actor → role*.
Conflating them was assessed as a privilege-escalation risk and rejected. This
design keeps the two tables answering their own questions.

`scope_assignments.grant_level` (`own`/`assigned`/`read`/`privileged`) stays
where it is and is not mirrored onto roles. It qualifies a specific grant's
strength; it is not an actor attribute.

### 3. Table shape mirrors `scope_assignments`

`actor_roles`:

| Column | Type | Rationale |
|---|---|---|
| `actor_identifier` | string | Mirrors `scope_assignments`. **Not** a `users` FK — `ActorContext::$identityReference` is `int|string` precisely so a future K1/K2 adapter can key on an external string id, and design.md states identity is not mastered here. |
| `role` | string(64) | App-validated against `ActorRole::KNOWN_ROLES` on save, not a Postgres enum — extending the list must not require a migration. Same pattern and same reasoning as `entity_type`. |
| `revoked_at` | nullable timestamp | Soft-revoke, not delete, so grant history stays queryable. Mirrors `scope_assignments` and `actor_sessions`. |
| `granted_reason` | string | The human justification captured at grant time. See decision 5. |
| timestamps | | |

Indexes: `(actor_identifier, revoked_at)` for the resolution path, `(role)` for
"who holds this role" review queries.

A partial unique index is deliberately omitted, for the same reason
`scope_assignments` omits one: a role can be granted, revoked, and re-granted,
and resolution de-duplicates anyway.

Multi-role is supported by construction (one row per role). An actor may hold
both `finance` and `admin`; consumers already iterate rather than assuming one.

### 4. Resolution must not recurse — verified constraint

`ActorContext` is a `scoped()` container binding
(`IdentityAccessServiceProvider:53`) resolved through `ActorContextResolver` →
`IdentityAccessAdapter`. `ScopeAssignmentResolver::__construct` takes an
`ActorContext` (`ScopeAssignmentResolver:50-52`). So injecting
`ScopeAssignmentResolver` into the adapter closes a cycle.

This was verified empirically, not assumed. A probe binding an adapter that
constructor-injects `ScopeAssignmentResolver` and then resolving
`app(ActorContext::class)` does **not** raise Laravel's
`CircularDependencyException` — it recurses unboundedly, reaching ~1GB RSS in
under six minutes until the host OOMs. On a 3GB host that is a hang, not a
failure.

Resolution: the adapter depends only on stateless readers that know nothing
about `ActorContext`.

- New `ActorRoleReader` — no constructor dependencies, queries `actor_roles`.
- New `ScopeAssignmentReader` — the actor-keyed queries extracted out of
  `ScopeAssignmentResolver`, which keeps its present public API and delegates.
  Its three existing consumers (`ScopeAssignmentGlobalScope`,
  `DocumentAccessPolicy`, `Notification\RecipientResolver`) are untouched.

Extraction is preferred over the adapter querying `ScopeAssignment` directly
because the `"entity_type:entity_id"` scope-string format would otherwise exist
in two places. That string is an authorization value; duplicating its
construction invites drift.

### 5. Grants are console-only, audited, and reasoned

There is today **no write path to `scope_assignments` anywhere** — no `create`
call in `app/` or `database/`, no seeder, no admin UI. Grants exist only via
hand-written SQL. Without a grant path this lane produces a seam that is correct
but permanently empty, and untestable across a real grant→resolve round trip.

Per the Round A ruling, this lane adds a minimal grant path: console only, no
HTTP surface, no self-service.

Four artisan commands over four Actions: grant/revoke a role, grant/revoke a
scope. Revocation is included because the ability to withdraw access is part of
an authorization control, not an enhancement.

Each Action writes through `Audit::wrap()` so the mutation and its audit row
commit in one transaction. Four new audit actions — `ROLE_GRANT`,
`ROLE_REVOKE`, `SCOPE_GRANT`, `SCOPE_REVOKE` — are added to
`SensitiveActions::ACTIONS`, making a human-authored `--reason` mandatory.
Granting `admin` or `finance` is the same privilege-escalation category as the
`MFA_RESET` and `CERTIFICATE_REVOKE` entries already on that list.

`AuditSource` gains a `Console` case. The enum's doc block asks for deliberate
extension rather than widening, and `audit_events.source` is a plain string
column (`2026_07_26_110000_create_audit_events_table.php:118`), so no migration
is involved.

### 6. Emitted role order is deterministic

`DocumentAccessPolicy::auditRoleFor()` notes that reading `$roles[0]` would be
non-deterministic because "the array's order is whatever the identity adapter
happened to emit," and works around it with its own fixed precedence walk. The
adapter therefore emits roles ordered by `ActorRole::KNOWN_ROLES` declaration
order (most privileged first), not by database insertion order. The existing
workaround stays as-is; this simply stops the underlying non-determinism.

## Blast radius

Landing this flips five authorizers and one policy from unconditionally denying
to actually enforcing. Nothing changes for any actor until a grant row exists,
which is why decision 5 matters — but these code paths become live for the first
time. This is authorization code: full independent review per task, and human
sign-off before merge, per `AGENTS.md` §Infrastructure-agent execution.

## Verification

The default test connection is SQLite in-memory (`phpunit.xml:47`). This repo has
been bitten repeatedly by SQLite-only-passes bugs, so every schema-touching
change is additionally verified against a disposable PostgreSQL 18 container.
The shared `makam-nonprod` stack is never used.

The end-to-end assertion that justifies the grant path: grant a role and a scope
via the console commands, resolve `ActorContext` in a fresh request, and confirm
both `hasRole()` and `hasScope()` return true — against real PostgreSQL.

## Documentation consequence

`docs/security/rbac-matrix.md:19` reads "Exact roles depend on K1/K2." The Round
A ruling supersedes that sentence. It is corrected in this lane's PR to point at
`ActorRole::KNOWN_ROLES` as the canonical list, so the matrix does not become a
rival definition of the vocabulary (`AGENTS.md` §Documentation).
