# Platform Identity Seam — Implementation Plan (Lane L5, Wave 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `ActorContext::$roles` and `ActorContext::$scopes` resolve real values from real tables, so that platform-wide authorization stops being inert.

**Architecture:** A new `actor_role_assignments` table with an application-validated closed role list (`ActorRole`), read by a stateless `ActorRoleReader`; the existing `scope_assignments` table read by a new stateless `ScopeAssignmentReader` extracted from `ScopeAssignmentResolver`. `LocalUsersTableIdentityAccessAdapter` composes both into the `ActorContext` it builds. A console-only, audited grant path makes the seam exercisable end to end.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18 (production/verification), SQLite in-memory (default test connection), PHPUnit.

**Design doc:** `docs/superpowers/specs/2026-08-11-platform-identity-seam-design.md` — read it before Task 1. It records the rulings behind every decision here.

## Global Constraints

- This is authorization code. Full independent review per task; human sign-off before merge (`AGENTS.md` §Infrastructure-agent execution).
- Never report `PASS` for a check that was not executed. Use `BLOCKED` or `NOT TESTED` explicitly.
- Do not run `composer install` or `npm run build` on this host (`CLAUDE.md` §Scope note). `vendor/` is already present in this worktree.
- Closed lists are application-layer PHP constants validated on save, never Postgres enum types — extending a list must never require a migration.
- `actor_identifier` is a **string** column, never a `users` foreign key. Identity is not mastered by this module.
- Never place restricted data in logs or audit metadata. Audit metadata keys must already exist in `MetadataAllowlist::ALLOWED_KEYS`.
- Never construct `ActorContext` ad hoc. It is built only by an `IdentityAccessAdapter`.
- **Nothing in the adapter's dependency graph may depend on `ActorContext`.** See Task 2; violating this recurses to OOM, it does not throw.
- Host has 3GB RAM. Do not run the full test suite concurrently with another lane; prefer `--filter` runs.

## Current state — read before planning any change

### What already exists and works

- `scope_assignments` table, `ScopeAssignment` model, `ScopeEntityType` / `ScopeGrantLevel` closed lists, `ScopeAssignmentResolver`, `ScopeAssignmentGlobalScope`.
- `ActorContext` value object, `ActorContextResolver` (`scoped()` per request/job), `IdentityAccessAdapter` contract, `LocalUsersTableIdentityAccessAdapter`.
- `Audit::record()` / `Audit::wrap()`, `AuditSubject`, `AuditOutcome`, `AuditSource`, `SensitiveActions`, `MetadataAllowlist`.

### What is missing (this lane's whole job)

- No roles table anywhere in the repository.
- `LocalUsersTableIdentityAccessAdapter:52-53` hardcodes `roles: []` and `scopes: []`.
- No write path to `scope_assignments` anywhere — no `create` call in `app/` or `database/`, no seeder, no admin UI.

### Consumers that unblock when this lands

`FinanceLedgerReadAuthorizer`, `FinanceReconciliationAuthorizer`, `FinanceVendorPayableAuthorizer`, `FinanceOrRestrictedAdminPayoutAuthorizer`, `DocumentVault\Policies\DocumentAccessPolicy`. All five currently deny every request because the role list is empty. **Do not modify any of them in this lane** — they are already written against the final shape.

## File Structure

**Create:**

| Path | Responsibility |
|---|---|
| `database/migrations/2026_08_11_100000_create_actor_role_assignments_table.php` | Schema |
| `app/Platform/IdentityAccess/Roles/ActorRole.php` | Closed role list + validation |
| `app/Platform/IdentityAccess/Roles/Models/ActorRoleAssignment.php` | Eloquent model, validates on save |
| `app/Platform/IdentityAccess/Roles/ActorRoleReader.php` | Stateless actor→roles query |
| `app/Platform/IdentityAccess/Roles/RoleAuditActions.php` | `ROLE_GRANT` / `ROLE_REVOKE` constants |
| `app/Platform/IdentityAccess/Roles/Actions/GrantActorRole.php` | Audited grant |
| `app/Platform/IdentityAccess/Roles/Actions/RevokeActorRole.php` | Audited soft-revoke |
| `app/Platform/IdentityAccess/Scopes/ScopeAssignmentReader.php` | Stateless actor→scopes query |
| `app/Platform/IdentityAccess/Scopes/ScopeAuditActions.php` | `SCOPE_GRANT` / `SCOPE_REVOKE` constants |
| `app/Platform/IdentityAccess/Scopes/Actions/GrantScopeAssignment.php` | Audited grant |
| `app/Platform/IdentityAccess/Scopes/Actions/RevokeScopeAssignment.php` | Audited soft-revoke |
| `app/Console/Commands/IdentityGrantRoleCommand.php` | `identity:grant-role` |
| `app/Console/Commands/IdentityRevokeRoleCommand.php` | `identity:revoke-role` |
| `app/Console/Commands/IdentityGrantScopeCommand.php` | `identity:grant-scope` |
| `app/Console/Commands/IdentityRevokeScopeCommand.php` | `identity:revoke-scope` |

**Modify:**

| Path | Change |
|---|---|
| `app/Platform/IdentityAccess/Adapters/LocalUsersTableIdentityAccessAdapter.php` | Resolve roles + scopes; correct doc block |
| `app/Platform/IdentityAccess/ActorContext.php` | Doc block only — remove "always empty" claims |
| `app/Platform/IdentityAccess/Scopes/ScopeAssignmentResolver.php` | Delegate to reader; public API unchanged |
| `app/Platform/Audit/AuditSource.php` | Add `Console` case |
| `app/Platform/Audit/SensitiveActions.php` | Add 4 grant/revoke actions |
| `docs/security/rbac-matrix.md` | Correct line 19 |

---

## Task 1: Role vocabulary, table, and model

**Files:**
- Create: `app/Platform/IdentityAccess/Roles/ActorRole.php`
- Create: `database/migrations/2026_08_11_100000_create_actor_role_assignments_table.php`
- Create: `app/Platform/IdentityAccess/Roles/Models/ActorRoleAssignment.php`
- Test: `tests/Unit/Platform/IdentityAccess/Roles/ActorRoleTest.php`
- Test: `tests/Feature/IdentityAccess/Roles/ActorRoleAssignmentModelTest.php`

**Interfaces:**
- Produces: `ActorRole::KNOWN_ROLES` (`list<string>`, declaration order is precedence order, most privileged first), `ActorRole::isKnown(string): bool`, `ActorRole::assertKnown(string): void` (throws `InvalidArgumentException`), plus one `public const string` per role. Model `ActorRoleAssignment` with `$fillable = ['actor_identifier','role','revoked_at']`, `isActive(): bool`, `revoke(): void`.

Read `app/Platform/IdentityAccess/Scopes/ScopeEntityType.php` and `Scopes/Models/ScopeAssignment.php` first. This task is deliberately their structural twin — match their doc-block depth and their validation approach.

- [ ] **Step 1: Write the failing vocabulary test**

```php
// tests/Unit/Platform/IdentityAccess/Roles/ActorRoleTest.php
public function test_known_roles_is_the_ruled_closed_list_in_precedence_order(): void
{
    $this->assertSame([
        'admin', 'restricted_admin', 'finance', 'operator',
        'case_manager', 'vendor', 'customer', 'system',
    ], ActorRole::KNOWN_ROLES);
}

public function test_audit_sentinels_are_never_grantable_roles(): void
{
    // 'guest' and 'authenticated_actor' mean "no role applies"
    // (DocumentAccessPolicy:124,130). If either were grantable an audit row
    // could claim a role the authorization layer reads as its absence.
    foreach (['guest', 'authenticated_actor'] as $sentinel) {
        $this->assertFalse(ActorRole::isKnown($sentinel));
        $this->expectException(InvalidArgumentException::class);
        ActorRole::assertKnown($sentinel);
    }
}

public function test_every_role_consumed_by_shipped_code_is_present(): void
{
    // Regression guard: these literals are already checked by shipped
    // authorizers. Dropping one silently re-breaks that consumer.
    foreach ([
        FinanceLedgerReadAuthorizer::FINANCE_ROLE,
        FinanceOrRestrictedAdminPayoutAuthorizer::RESTRICTED_ADMIN_ROLE,
        FinanceVendorPayableAuthorizer::SYSTEM_ROLE,
    ] as $role) {
        $this->assertTrue(ActorRole::isKnown($role));
    }
}
```

Note: `expectException` inside a loop stops at the first iteration. Split the sentinel test into two test methods, or assert with a try/catch — do not leave a loop that only ever tests `guest`.

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit --filter ActorRoleTest`
Expected: FAIL, `Class "App\Platform\IdentityAccess\Roles\ActorRole" not found`.

- [ ] **Step 3: Write `ActorRole`**

Mirror `ScopeEntityType` exactly in shape. Eight `public const string` values, `KNOWN_ROLES` listing them in the precedence order above, `isKnown()`, `assertKnown()` throwing `InvalidArgumentException` with the same message format ScopeEntityType uses. Document in the class doc block: where the vocabulary came from (union of shipped consumers plus the RBAC matrix columns), why `issuer`/`auditor` are excluded, and why the sentinels must never be added.

- [ ] **Step 4: Write the migration**

Table `actor_role_assignments`: `id`, `actor_identifier` (string), `role` (string, 64), `revoked_at` (nullable timestamp), `timestamps()`. Index `['actor_identifier', 'revoked_at']` named `actor_role_assignments_actor_active_idx`; index `['role']` named `actor_role_assignments_role_idx`. No unique constraint, no foreign key. Doc block must explain each of those three omissions, mirroring the `scope_assignments` migration.

- [ ] **Step 5: Write the model test and model**

```php
// tests/Feature/IdentityAccess/Roles/ActorRoleAssignmentModelTest.php — uses RefreshDatabase
public function test_it_rejects_a_role_outside_the_closed_list_on_save(): void
{
    $this->expectException(InvalidArgumentException::class);
    ActorRoleAssignment::create(['actor_identifier' => '1', 'role' => 'wizard']);
}

public function test_revoke_soft_revokes_without_deleting_the_row(): void
{
    $assignment = ActorRoleAssignment::create(['actor_identifier' => '1', 'role' => ActorRole::FINANCE]);
    $assignment->revoke();

    $this->assertFalse($assignment->fresh()->isActive());
    $this->assertDatabaseCount('actor_role_assignments', 1);
}
```

Model mirrors `ScopeAssignment`: `$table`, `$fillable`, `casts()` with `'revoked_at' => 'immutable_datetime'`, a `booted()` `saving` listener calling `ActorRole::assertKnown($model->role)`, `isActive()`, `revoke()`.

- [ ] **Step 6: Run both test files and confirm they pass**

Run: `vendor/bin/phpunit --filter 'ActorRoleTest|ActorRoleAssignmentModelTest'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Platform/IdentityAccess/Roles database/migrations/2026_08_11_100000_create_actor_role_assignments_table.php tests/Unit/Platform/IdentityAccess/Roles tests/Feature/IdentityAccess/Roles
git commit -m "feat(identity-seam): add actor_role_assignments table and closed role list"
```

---

## Task 2: Extract `ScopeAssignmentReader` without changing the resolver's API

**Files:**
- Create: `app/Platform/IdentityAccess/Scopes/ScopeAssignmentReader.php`
- Modify: `app/Platform/IdentityAccess/Scopes/ScopeAssignmentResolver.php`
- Test: `tests/Feature/IdentityAccess/Scopes/ScopeAssignmentReaderTest.php`

**Interfaces:**
- Produces: `ScopeAssignmentReader` with **no constructor arguments** and three methods moved verbatim from the resolver — `grantedEntityIds(int|string $actorIdentifier, string $entityType): array`, `actorsForEntity(string $entityType, int|string $entityId): array`, `scopeStringsForActor(int|string $actorIdentifier): array`.
- Consumes: nothing from Task 1.

**Why this task exists.** `ActorContext` is a `scoped()` binding resolved through `ActorContextResolver` → `IdentityAccessAdapter`, and `ScopeAssignmentResolver::__construct` takes an `ActorContext`. Injecting the resolver into the adapter closes a container cycle. This was verified empirically: it does **not** raise `CircularDependencyException`, it recurses unboundedly to ~1GB RSS and OOMs the host. The reader has no constructor dependencies, so the adapter can depend on it safely.

**Hard constraint: `ScopeAssignmentResolver`'s public API must not change.** It has three live consumers (`ScopeAssignmentGlobalScope`, `DocumentVault\Policies\DocumentAccessPolicy`, `Notification\RecipientResolver`). Do not touch any of them. This is a pure behaviour-preserving extraction.

- [ ] **Step 1: Confirm the existing resolver tests pass before you touch anything**

Run: `vendor/bin/phpunit --filter 'ScopeAssignmentResolverTest|ScopeAssignmentGlobalScopeTest'`
Expected: PASS. Record the assertion count — it must not drop by the end of this task.

- [ ] **Step 2: Write the failing reader test**

```php
// tests/Feature/IdentityAccess/Scopes/ScopeAssignmentReaderTest.php — RefreshDatabase
public function test_it_is_constructible_without_an_actor_context(): void
{
    // The whole point of this class: no ActorContext in its graph, so the
    // identity adapter can depend on it without closing a container cycle.
    $this->assertInstanceOf(ScopeAssignmentReader::class, new ScopeAssignmentReader());
}

public function test_scope_strings_use_the_entity_type_colon_entity_id_shape(): void
{
    ScopeAssignment::create([
        'actor_identifier' => '7',
        'entity_type' => ScopeEntityType::CEMETERY,
        'entity_id' => '1',
    ]);

    $this->assertSame(['cemetery:1'], (new ScopeAssignmentReader())->scopeStringsForActor(7));
}

public function test_revoked_grants_are_excluded(): void
{
    $grant = ScopeAssignment::create([
        'actor_identifier' => '7',
        'entity_type' => ScopeEntityType::VENDOR,
        'entity_id' => '3',
    ]);
    $grant->revoke();

    $this->assertSame([], (new ScopeAssignmentReader())->scopeStringsForActor(7));
    $this->assertSame([], (new ScopeAssignmentReader())->grantedEntityIds(7, ScopeEntityType::VENDOR));
}
```

- [ ] **Step 3: Run it and confirm it fails**

Run: `vendor/bin/phpunit --filter ScopeAssignmentReaderTest`
Expected: FAIL, class not found.

- [ ] **Step 4: Create the reader and make the resolver delegate**

Move the three method bodies verbatim into `ScopeAssignmentReader`. In `ScopeAssignmentResolver`, keep all four public methods with identical signatures; the three moved ones become one-line delegations to a `ScopeAssignmentReader` the resolver constructs or receives with a default. `currentActorIdentifier()` keeps reading `$this->actorContext` and stays where it is.

Update the resolver's class doc block: the long "`$scopes` wiring gap" section is about to become false, so rewrite it to say the wiring now happens in the adapter via `ScopeAssignmentReader`, and explain why the adapter must not use the resolver itself.

- [ ] **Step 5: Run reader tests plus every existing consumer's tests**

Run: `vendor/bin/phpunit --filter 'ScopeAssignmentReaderTest|ScopeAssignmentResolverTest|ScopeAssignmentGlobalScopeTest|DocumentAccessPolicy|RecipientResolver'`
Expected: PASS, with no drop in assertion count from Step 1.

- [ ] **Step 6: Commit**

```bash
git add app/Platform/IdentityAccess/Scopes tests/Feature/IdentityAccess/Scopes
git commit -m "refactor(identity-seam): extract stateless ScopeAssignmentReader"
```

---

## Task 3: Resolve roles and scopes in the adapter

**Files:**
- Create: `app/Platform/IdentityAccess/Roles/ActorRoleReader.php`
- Modify: `app/Platform/IdentityAccess/Adapters/LocalUsersTableIdentityAccessAdapter.php`
- Modify: `app/Platform/IdentityAccess/ActorContext.php` (doc block only)
- Test: `tests/Feature/IdentityAccess/Roles/ActorRoleReaderTest.php`
- Test: `tests/Feature/IdentityAccess/LocalUsersTableIdentityAccessAdapterTest.php` (extend)

**Interfaces:**
- Consumes: `ActorRole`, `ActorRoleAssignment` (Task 1); `ScopeAssignmentReader` (Task 2).
- Produces: `ActorRoleReader` with no constructor arguments and `rolesForActor(int|string $actorIdentifier): array` returning `list<string>` ordered by `ActorRole::KNOWN_ROLES` declaration order.

This is the task that makes authorization live. Read the design doc's "Blast radius" section before starting.

- [ ] **Step 1: Write the failing reader test**

```php
// tests/Feature/IdentityAccess/Roles/ActorRoleReaderTest.php — RefreshDatabase
public function test_it_returns_active_roles_in_known_roles_declaration_order(): void
{
    // Inserted in reverse precedence deliberately: the reader must not
    // return database insertion order. DocumentAccessPolicy::auditRoleFor()
    // documents why non-deterministic order is a real problem.
    ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::CUSTOMER]);
    ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::ADMIN]);

    $this->assertSame(
        [ActorRole::ADMIN, ActorRole::CUSTOMER],
        (new ActorRoleReader())->rolesForActor(5),
    );
}

public function test_revoked_roles_are_excluded(): void
{
    $grant = ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::FINANCE]);
    $grant->revoke();

    $this->assertSame([], (new ActorRoleReader())->rolesForActor(5));
}

public function test_it_deduplicates_repeated_grants_of_the_same_role(): void
{
    ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::FINANCE]);
    ActorRoleAssignment::create(['actor_identifier' => '5', 'role' => ActorRole::FINANCE]);

    $this->assertSame([ActorRole::FINANCE], (new ActorRoleReader())->rolesForActor(5));
}

public function test_an_actor_with_no_grants_gets_an_empty_list(): void
{
    $this->assertSame([], (new ActorRoleReader())->rolesForActor(999));
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit --filter ActorRoleReaderTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `ActorRoleReader`**

Query `actor_role_assignments` for the actor with `whereNull('revoked_at')`, pluck `role`, unique, then sort by index in `ActorRole::KNOWN_ROLES` and `array_values()` the result. Do **not** rely on a database `ORDER BY` for precedence — the order is an application concept, and sorting in PHP against `KNOWN_ROLES` keeps the single source of truth in one place.

- [ ] **Step 4: Write the failing adapter test**

```php
// tests/Feature/IdentityAccess/LocalUsersTableIdentityAccessAdapterTest.php
public function test_it_resolves_real_roles_and_scopes_for_an_authenticated_actor(): void
{
    $user = User::factory()->create();

    ActorRoleAssignment::create(['actor_identifier' => (string) $user->id, 'role' => ActorRole::FINANCE]);
    ScopeAssignment::create([
        'actor_identifier' => (string) $user->id,
        'entity_type' => ScopeEntityType::CEMETERY,
        'entity_id' => '4',
        'grant_level' => ScopeGrantLevel::PRIVILEGED,
    ]);

    $context = app(IdentityAccessAdapter::class)->resolveActorContext($user);

    $this->assertTrue($context->hasRole(ActorRole::FINANCE));
    $this->assertTrue($context->hasScope('cemetery:4'));
}

public function test_a_guest_carries_no_roles_or_scopes(): void
{
    $context = app(IdentityAccessAdapter::class)->resolveActorContext(null);

    $this->assertSame([], $context->roles);
    $this->assertSame([], $context->scopes);
}

public function test_resolving_actor_context_through_the_container_does_not_recurse(): void
{
    // Regression guard for the verified container cycle: if the adapter ever
    // depends on something that depends on ActorContext, this hangs and OOMs
    // rather than failing. See the design doc, decision 4.
    $this->assertInstanceOf(ActorContext::class, app(ActorContext::class));
}

public function test_another_actors_grants_do_not_leak(): void
{
    $user = User::factory()->create();
    $other = User::factory()->create();
    ActorRoleAssignment::create(['actor_identifier' => (string) $other->id, 'role' => ActorRole::ADMIN]);

    $context = app(IdentityAccessAdapter::class)->resolveActorContext($user);

    $this->assertSame([], $context->roles);
}
```

- [ ] **Step 5: Wire the adapter**

Constructor-inject `ActorRoleReader` and `ScopeAssignmentReader` (both have no dependencies, so no container configuration is needed). Replace the hardcoded `roles: []` and `scopes: []` with reader calls keyed on the normalized identifier. Guests keep returning `ActorContext::guest()` — do not query for them.

Then correct the now-false doc blocks. In the adapter, the paragraph claiming "`roles` and `scopes` are always returned empty" must go. In `ActorContext`, the class-level notes on `$roles` and `$scopes`, and the "always false today" comments on `hasRole()` / `hasScope()`, are all now wrong. Replace them with what is actually true, including the fact that an empty roles list still means "no roles" and must never be read as "no roles required."

- [ ] **Step 6: Run the adapter and reader tests**

Run: `vendor/bin/phpunit --filter 'ActorRoleReaderTest|LocalUsersTableIdentityAccessAdapterTest|ActorContextTest|ActorContextResolverTest'`
Expected: PASS.

- [ ] **Step 7: Run the previously-inert consumers' tests**

Run: `vendor/bin/phpunit --filter 'Finance|DocumentAccessPolicy'`
Expected: PASS. These suites assert fail-closed behaviour with empty roles; they construct `ActorContext` directly, so they should be unaffected. **If any now fails, stop and report it** — it means a consumer was depending on roles being unconditionally empty, which is exactly the blast radius this lane needs to surface rather than paper over.

- [ ] **Step 8: Commit**

```bash
git add app/Platform/IdentityAccess tests/Feature/IdentityAccess
git commit -m "feat(identity-seam): resolve real roles and scopes into ActorContext"
```

---

## Task 4: Audited grant and revoke Actions

**Files:**
- Create: `app/Platform/IdentityAccess/Roles/RoleAuditActions.php`
- Create: `app/Platform/IdentityAccess/Roles/Actions/GrantActorRole.php`
- Create: `app/Platform/IdentityAccess/Roles/Actions/RevokeActorRole.php`
- Create: `app/Platform/IdentityAccess/Scopes/ScopeAuditActions.php`
- Create: `app/Platform/IdentityAccess/Scopes/Actions/GrantScopeAssignment.php`
- Create: `app/Platform/IdentityAccess/Scopes/Actions/RevokeScopeAssignment.php`
- Modify: `app/Platform/Audit/AuditSource.php`
- Modify: `app/Platform/Audit/SensitiveActions.php`
- Test: `tests/Feature/IdentityAccess/Roles/GrantActorRoleTest.php`
- Test: `tests/Feature/IdentityAccess/Scopes/GrantScopeAssignmentTest.php`

**Interfaces:**
- Consumes: Task 1's `ActorRole` / `ActorRoleAssignment`.
- Produces:
  - `RoleAuditActions::GRANT = 'ROLE_GRANT'`, `RoleAuditActions::REVOKE = 'ROLE_REVOKE'`
  - `ScopeAuditActions::GRANT = 'SCOPE_GRANT'`, `ScopeAuditActions::REVOKE = 'SCOPE_REVOKE'`
  - `GrantActorRole::__invoke(int|string $actorIdentifier, string $role, string $reason, int|string|null $grantedBy): ActorRoleAssignment`
  - `RevokeActorRole::__invoke(int|string $actorIdentifier, string $role, string $reason, int|string|null $revokedBy): int` (count revoked)
  - `GrantScopeAssignment::__invoke(int|string $actorIdentifier, string $entityType, int|string $entityId, ?string $grantLevel, string $reason, int|string|null $grantedBy): ScopeAssignment`
  - `RevokeScopeAssignment::__invoke(int|string $actorIdentifier, string $entityType, int|string $entityId, string $reason, int|string|null $revokedBy): int`

Read `app/Platform/Audit/Audit.php` (`record()` and `wrap()`) and one existing `Audit::wrap()` caller before starting.

- [ ] **Step 1: Write the failing grant test**

```php
// tests/Feature/IdentityAccess/Roles/GrantActorRoleTest.php — RefreshDatabase
public function test_it_grants_a_role_and_writes_one_audit_row_in_the_same_transaction(): void
{
    $assignment = app(GrantActorRole::class)(
        actorIdentifier: 42,
        role: ActorRole::FINANCE,
        reason: 'Finance lead onboarding, ticket OPS-114',
        grantedBy: 1,
    );

    $this->assertTrue($assignment->isActive());
    $this->assertDatabaseHas('audit_events', [
        'action' => RoleAuditActions::GRANT,
        'actor_ref' => '1',
        'source' => 'console',
    ]);
}

public function test_it_refuses_a_role_outside_the_closed_list(): void
{
    $this->expectException(InvalidArgumentException::class);

    app(GrantActorRole::class)(42, 'wizard', 'nope', 1);
}

public function test_a_rejected_grant_writes_no_audit_row_and_no_assignment(): void
{
    try {
        app(GrantActorRole::class)(42, 'wizard', 'nope', 1);
    } catch (InvalidArgumentException) {
        // expected
    }

    $this->assertDatabaseCount('actor_role_assignments', 0);
    $this->assertDatabaseCount('audit_events', 0);
}

public function test_it_requires_a_reason(): void
{
    $this->expectException(AuditReasonRequiredException::class);

    app(GrantActorRole::class)(42, ActorRole::FINANCE, '   ', 1);
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/phpunit --filter GrantActorRoleTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Extend the audit vocabulary**

Add `case Console = 'console';` to `AuditSource`, with a comment saying it is for the human-run `identity:*` commands. `audit_events.source` is a plain string column, so no migration is needed — confirm that by reading `2026_07_26_110000_create_audit_events_table.php:118` rather than assuming.

Add all four action constants to `SensitiveActions::ACTIONS`, so a reason is mandatory. Write a comment in the same style as the existing entries explaining why: granting `admin` or `finance` is the same privilege-escalation category as the `MFA_RESET` and `CERTIFICATE_REVOKE` entries already on the list, and a grant with no recorded justification is indistinguishable from one nobody authorised.

- [ ] **Step 4: Implement the four Actions**

Each wraps its mutation in `Audit::wrap()` so the row and its audit event commit together. Validate the closed list (`ActorRole::assertKnown`, `ScopeEntityType::assertKnown`, `ScopeGrantLevel::assertKnown` when a level is given) **before** opening the transaction. `actorRole` on the audit call is the granting operator's role — pass `ActorRole::SYSTEM` for a console invocation, since the console operator is not acting as a resolved `ActorContext`. Revocation updates every matching active row and returns the count.

Metadata: use only keys already in `MetadataAllowlist::ALLOWED_KEYS`. `new_state` carries the role or `"entity_type:entity_id"` string. Do not add new allowlist keys in this task; if you believe one is needed, stop and report it.

- [ ] **Step 5: Write and run the scope-grant test**

Mirror the role tests in `tests/Feature/IdentityAccess/Scopes/GrantScopeAssignmentTest.php`, asserting `SCOPE_GRANT` audit rows, closed-list rejection for a bad `entity_type`, and that a revoked grant disappears from `ScopeAssignmentReader::scopeStringsForActor()`.

Run: `vendor/bin/phpunit --filter 'GrantActorRoleTest|GrantScopeAssignmentTest|Audit'`
Expected: PASS, including the pre-existing audit suite.

- [ ] **Step 6: Commit**

```bash
git add app/Platform/IdentityAccess app/Platform/Audit tests/Feature/IdentityAccess
git commit -m "feat(identity-seam): audited grant and revoke actions for roles and scopes"
```

---

## Task 5: Console commands

**Files:**
- Create: `app/Console/Commands/IdentityGrantRoleCommand.php`
- Create: `app/Console/Commands/IdentityRevokeRoleCommand.php`
- Create: `app/Console/Commands/IdentityGrantScopeCommand.php`
- Create: `app/Console/Commands/IdentityRevokeScopeCommand.php`
- Test: `tests/Feature/IdentityAccess/Console/IdentityGrantCommandsTest.php`

**Interfaces:**
- Consumes: Task 4's four Actions.
- Produces: signatures
  - `identity:grant-role {actor} {role} {--reason=}`
  - `identity:revoke-role {actor} {role} {--reason=}`
  - `identity:grant-scope {actor} {entityType} {entityId} {--level=} {--reason=}`
  - `identity:revoke-scope {actor} {entityType} {entityId} {--reason=}`

Console only. No HTTP surface, no self-service, no interactive role picker. Read an existing command in `app/Console/Commands/` for the house style.

- [ ] **Step 1: Write the failing command test**

```php
// tests/Feature/IdentityAccess/Console/IdentityGrantCommandsTest.php — RefreshDatabase
public function test_grant_role_command_creates_an_active_assignment(): void
{
    $this->artisan('identity:grant-role', [
        'actor' => '42',
        'role' => ActorRole::FINANCE,
        '--reason' => 'Finance lead onboarding, ticket OPS-114',
    ])->assertSuccessful();

    $this->assertDatabaseHas('actor_role_assignments', [
        'actor_identifier' => '42',
        'role' => ActorRole::FINANCE,
        'revoked_at' => null,
    ]);
}

public function test_grant_role_command_fails_without_a_reason(): void
{
    $this->artisan('identity:grant-role', ['actor' => '42', 'role' => ActorRole::FINANCE])
        ->assertFailed();

    $this->assertDatabaseCount('actor_role_assignments', 0);
}

public function test_grant_role_command_rejects_an_unknown_role_with_a_readable_message(): void
{
    $this->artisan('identity:grant-role', [
        'actor' => '42', 'role' => 'wizard', '--reason' => 'x',
    ])->assertFailed();

    $this->assertDatabaseCount('actor_role_assignments', 0);
}

public function test_revoke_role_command_soft_revokes(): void
{
    ActorRoleAssignment::create(['actor_identifier' => '42', 'role' => ActorRole::FINANCE]);

    $this->artisan('identity:revoke-role', [
        'actor' => '42', 'role' => ActorRole::FINANCE, '--reason' => 'Left the team',
    ])->assertSuccessful();

    // Soft-revoke: the row survives for history, but the actor no longer resolves the role.
    $this->assertDatabaseCount('actor_role_assignments', 1);
    $this->assertSame([], (new ActorRoleReader())->rolesForActor(42));
}
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/phpunit --filter IdentityGrantCommandsTest`
Expected: FAIL, command not found.

- [ ] **Step 3: Implement the four commands**

Each command validates that `--reason` is present and non-blank before calling its Action, catches `InvalidArgumentException` and `AuditReasonRequiredException` to `$this->error(...)` with `return self::FAILURE`, and on success prints a one-line confirmation naming the actor, the role/scope, and that an audit row was written. List the valid roles in the error message when an unknown role is supplied — read them from `ActorRole::KNOWN_ROLES`, never a hardcoded copy.

Commands are auto-discovered from `app/Console/Commands`; verify with `php artisan list identity` rather than assuming.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit --filter IdentityGrantCommandsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands tests/Feature/IdentityAccess/Console
git commit -m "feat(identity-seam): console-only grant and revoke commands"
```

---

## Task 6: Real PostgreSQL 18 verification and doc correction

**Files:**
- Modify: `docs/security/rbac-matrix.md`
- Create: `.superpowers/sdd/2026-08-11-platform-identity-seam/postgres-verification.md` (evidence, git-ignored)

**Interfaces:**
- Consumes: everything from Tasks 1-5.

The default test connection is SQLite in-memory (`phpunit.xml:47`), and this repo has repeatedly been bitten by SQLite-only-passes bugs. This task proves the schema and the full grant→resolve round trip against real PostgreSQL 18.

**Never touch the shared `makam-nonprod` stack.** Use a disposable container and remove it afterwards. The `postgres:18` image is already present locally.

- [ ] **Step 1: Start a disposable PostgreSQL 18 container**

```bash
docker run -d --rm --name makam-l5-verify \
  -e POSTGRES_PASSWORD=verify -e POSTGRES_DB=makam_l5 \
  -p 55518:5432 postgres:18
```

Wait for readiness with `docker exec makam-l5-verify pg_isready -U postgres` before continuing. Do not `sleep` blindly.

- [ ] **Step 2: Run the migrations against it**

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55518 DB_DATABASE=makam_l5 \
DB_USERNAME=postgres DB_PASSWORD=verify \
php artisan migrate --force
```

Expected: all migrations apply cleanly, including `create_actor_role_assignments_table`. Record the actual output.

- [ ] **Step 3: Run this lane's suites against real Postgres**

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55518 DB_DATABASE=makam_l5 \
DB_USERNAME=postgres DB_PASSWORD=verify \
vendor/bin/phpunit --filter 'ActorRole|ScopeAssignment|LocalUsersTableIdentityAccessAdapter|IdentityGrantCommands'
```

Expected: PASS. Memory note: run only this filter, not the whole suite — the host has 3GB RAM.

- [ ] **Step 4: Prove the end-to-end round trip on real Postgres**

Grant a role and a scope through the console commands, then assert both resolve through a fresh `ActorContext`. This is the assertion that justifies the grant path existing at all. Capture the real command output as evidence.

- [ ] **Step 5: Tear the container down**

```bash
docker stop makam-l5-verify
```

Confirm with `docker ps` that it is gone, and that no other container was affected.

- [ ] **Step 6: Correct `docs/security/rbac-matrix.md`**

Line 19 currently reads "Exact roles depend on K1/K2. Query-level scope is mandatory." The first sentence is superseded by the Round A ruling. Replace it so the matrix points at `App\Platform\IdentityAccess\Roles\ActorRole::KNOWN_ROLES` as the canonical role list rather than restating the eight values — restating them would make the matrix a rival definition, which `AGENTS.md` §Documentation forbids. Keep the "Query-level scope is mandatory" sentence: it is still true.

- [ ] **Step 7: Run the docs gate**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 8: Commit**

```bash
git add docs/security/rbac-matrix.md
git commit -m "docs(identity-seam): point rbac matrix at the real role vocabulary"
```

---

## Task 7: Review, fix wave, re-review

- [ ] **7a. Task-scoped reviews** — already done after each task, per subagent-driven-development.
- [ ] **7b. Whole-branch review** — dispatch a fresh reviewer over the complete diff `docs/design-system-and-planning..lane/l5-identity-seam`. This is authorization code: full independent rigor, no abbreviated pass. The reviewer must specifically check that roles and scopes stayed orthogonal, that no consumer of `ScopeAssignmentResolver` changed behaviour, that the sentinels cannot be granted, and that no audit row can be written without a reason.
- [ ] **7c. Bounded fix wave** for Critical and Important findings, then a scoped re-review. Minor findings are ledgered, not fixed.
- [ ] **7d. Escalate** to the coordinator if a fix loop reaches round 4.

## Task 8: Finish the branch

- [ ] Use `superpowers:finishing-a-development-branch`.
- [ ] Push and open a PR against `docs/design-system-and-planning`. **Do not merge.** Every Wave 1 lane was human-merged and this one carries mandatory human sign-off as authorization code.
- [ ] The PR description must state the blast radius explicitly: five authorizers and one policy stop being inert, and no actor gains anything until a grant row exists.

## Verification

| Claim | How it is proven |
|---|---|
| Closed role list rejects sentinels | `ActorRoleTest` |
| Role and scope resolution is real | `LocalUsersTableIdentityAccessAdapterTest` |
| Role order is deterministic | `ActorRoleReaderTest` |
| No container recursion | `test_resolving_actor_context_through_the_container_does_not_recurse` |
| Existing scope consumers unaffected | Task 2 Step 5 assertion-count comparison |
| No grant without an audited reason | `GrantActorRoleTest`, `IdentityGrantCommandsTest` |
| Schema works on real PostgreSQL 18 | Task 6, disposable container |

## Findings for the PR body — outside this lane's scope, named for their owners

### 1. `Journal.php:239` is a sixth consumer of `ActorContext::$roles`

`app/Platform/FinancialLedger/Journal.php:239` reads:

```php
$actorRole = $actor->roles[0] ?? ($actor->isAuthenticated() ? 'unresolved' : 'system');
```

It was not in this lane's list of five known consumers, and its behaviour
changes here: `JOURNAL_REVERSAL` audit rows previously always recorded
`unresolved` (or `system` for a guest), because `$roles` was always empty.
They now record an actual role.

**This is not a correctness bug, and the "non-deterministic order" framing it
was first reported under is wrong.** `ActorRoleReader` emits roles sorted by
`ActorRole::KNOWN_ROLES` declaration order, which is precedence order, so
`$roles[0]` is deterministically the actor's most privileged role. The value
is also an audit label only — it feeds `Audit::record()`'s `actorRole`, never
an authorization decision.

What it *is*: an undocumented coupling. `Journal` depends on the adapter's
emission order without saying so at the call site, so reordering
`KNOWN_ROLES` would silently change what `JOURNAL_REVERSAL` rows record.
`DocumentAccessPolicy::auditRoleFor()` solves the same problem explicitly,
with its own documented precedence walk, and is the pattern to copy.

Owner: the `platform-financial-ledger` lane (L4). Not changed here — this lane
does not edit consumer modules.

### 2. Doc rot in the five consumers

`FinanceLedgerReadAuthorizer`, `FinanceReconciliationAuthorizer`,
`FinanceVendorPayableAuthorizer`, `FinanceOrRestrictedAdminPayoutAuthorizer`,
and `DocumentVault\Policies\DocumentAccessPolicy` each carry doc blocks
stating that `ActorContext::$roles` is "always `[]`" and that they therefore
refuse every request. Both halves are now false.

Their *code* is correct and deliberately untouched by this lane — only the
prose is stale. Flagged so whoever next edits those files corrects it rather
than trusting it. A reader who believes "this always denies" could easily
misjudge a change to one of these authorizers.

## Cross-cutting finding for the merge sign-off bundle — NOT fixed in this lane

**`Audit::record()`'s mandatory-reason check can be bypassed with Unicode
whitespace, for every sensitive action in the application.**

`Audit.php:104` guards a mandatory reason with `trim($reason) === ''`. PHP's
`trim()` strips only the ASCII whitespace set, so a reason consisting solely of
U+00A0 (non-breaking space) or U+3000 (ideographic space) passes the check. The
mutation was confirmed live: the grant commits, and the audit row records a
justification that is invisible to a human reviewing the trail. A grant
justified by one invisible character is indistinguishable, in review, from one
nobody authorised.

Scope is **not** limited to this lane. Every action on
`SensitiveActions::ACTIONS` goes through the same check, so `PAYMENT_REFUND`,
`PAYMENT_CHARGEBACK`, `VENDOR_PAYOUT`, `JOURNAL_REVERSAL`,
`RECONCILIATION_EXCEPTION_RESOLVED`, `MFA_RESET`, `DOCUMENT_DELETE`,
`PLOT_OVERRIDE`, and the rest are all reachable the same way — all of them
already merged.

This lane closed the hole **only at its own console layer**
(`app/Console/Commands/Concerns/RequiresAuditReason.php`, a Unicode-aware
`\p{Z}\p{C}\s` check, with four regression tests). That is defence in depth:
the `identity:*` commands are safe, the platform is not. Fixing the shared
`Audit::record()` check was deliberately left alone — it is shared
infrastructure that already-merged lanes depend on, and a change there needs
its own review and its own regression pass across every calling module.

Decision needed from the coordinator: whether this becomes its own lane, a
hotfix against trunk, or a ledgered backlog item.

## NOT TESTED (this lane)

- **The "validate before the transaction opens" ordering claim in Task 4 is not
  independently tested** (Task 4 review, Minor). Removing the pre-transaction
  `assertKnown()` calls does not fail any test, because `ActorRoleAssignment`
  and `ScopeAssignment` re-validate in their own `saving` listeners inside the
  transaction, and `Audit::wrap()` rolls the whole thing back either way. The
  outcome is identical from the outside — no assignment row, no audit row — so
  the two layers cannot be distinguished by a behavioural test. Kept as
  deliberate defence in depth rather than removed, and recorded here so the
  redundancy is not mistaken for coverage: if a future change removes the model
  listener, nothing will fail until the pre-check is also verified.

- Behaviour of the five previously-inert authorizers under a *real* granted role in a *real* HTTP request. Their own suites construct `ActorContext` directly. Exercising them end to end belongs to the lanes that own those surfaces.
- The `FaqArticlesTable` authorization gap. Unblocked here, closed elsewhere.
- Any K1/K2 adapter. Still unseen; the interface remains a drop-in swap.
