# Payment controller authorization hotfix

**Date:** 2026-08-12
**Branch:** `fix/payment-controller-authorization` (worktree `.worktrees/platform-payment-auth-hotfix`, forked from `d9fea9f`)
**Severity:** live authorization bypass on money-moving actions in already-merged, already-shipped code
**Review tier:** FULL — implementer → task-scoped review → fix loop → whole-branch review → PR. No self-merge.

## 1. Current state — the vulnerability, verified by direct reading

Two shipped controllers perform money-moving actions with **no role or permission check of any kind**.

| Route | Controller | Action |
| --- | --- | --- |
| `POST /admin/payments/reversals/{reversalType}` (`routes/web.php:371-374`) | `app/Platform/Payment/Http/Controllers/RecordPaymentReversalController.php` | Records a refund or chargeback via `ReversalService::record()` |
| `POST /admin/payments/manual-verifications/{paymentVerification}/verify` (`routes/web.php:342-344`) | `app/Platform/Payment/Http/Controllers/VerifyManualPaymentController.php` | Approves or rejects a manual payment via `VerifyManualPayment::verify()` |

Both routes carry exactly `['web', 'auth', RequireRecentAuthentication::class.':<reason>,filament.admin.pages.mfa-challenge']`.

`config/auth.php` defines exactly **one** guard — `web`, provider `users` — and there is no separate admin guard. So `auth` means only "some row exists in the shared `users` table." Any authenticated user who satisfies the recency window can POST directly to either route and record a reversal or approve a payment.

**Corrected 12 Aug 2026 (review finding SF-5) — the exposure is worse than first written.** This paragraph originally said "any authenticated user **who has enrolled MFA** and satisfies the recency window". That credited a compensating control these two routes never had, and it understated the severity. Verified directly:

- `EnforceMfaChallenge` is attached in exactly two places: `app/Providers/Filament/AdminPanelProvider.php:162` (the Filament panel's own middleware array) and inline on the standalone `/admin/finance/exports` route (`routes/web.php:282`).
- Both payment routes are standalone `Route::post` declarations (`routes/web.php:342-344` and `371-374`) whose middleware arrays are only `['web', 'auth', RequireRecentAuthentication::class.':<reason>,...']`. Neither is inside any group carrying `EnforceMfaChallenge`.
- `RequireRecentAuthentication` reads only `ActorContext::$lastAuthenticatedAt` against `config('reauthentication.freshness_seconds')` (default `900`, `config/reauthentication.php:61`). It never consults MFA state.

So the true pre-fix precondition was **authenticated + logged in within the last 15 minutes. No MFA at all.** The adjacent finance-export route DOES carry `EnforceMfaChallenge`, which makes the omission on the two money-moving routes conspicuous rather than merely uniform.

Both controllers additionally hardcode `actorRole: 'authenticated_actor'` at two call sites each (`RecordPaymentReversalController.php:76,102`; `VerifyManualPaymentController.php:61,81`). Per `App\Platform\IdentityAccess\Roles\ActorRole`'s own doc block, `authenticated_actor` is an **audit sentinel meaning "no role applies"**, which "must NEVER" be a grantable role. So even the audit trail for these actions currently records the absence of a role.

### 1.1 A second defect found while reading, fixed by the same change

`ReauthenticationService::satisfy()` writes a `ReauthenticationEvent` with `outcome: SATISFIED`, writes an audit row with `AuditOutcome::Allowed`, and clears the MFA rate limiter. Both controllers call it as their **first** statement, before any authorization decision.

So today an actor who should be refused still gets a `SATISFIED` / `Allowed` audit trail and a cleared MFA rate limiter. Ordering the authorization check first fixes this as a side effect, at no extra cost.

## 2. Decisions

### D1 — Role-only. No `ScopeAssignment` grant check. (Resolves the brief's open question.)

The four existing authorizers in `app/Platform/FinancialLedger/` all check a scope grant, so the absence of one here needs justifying. It is justified by the schema:

- **Neither table has a scopeable column.** `payment_reversals` (`2026_08_11_100010_...`) holds `id, reversal_type, reference, amount_minor, reason, recorded_by_actor_ref, recorded_at`. `payment_verifications` (`2026_08_11_100000_...`) holds `id, reference, payment_method, payment_reference, instructions, proof_document_id, status, submitted_at, decided_*`. Nothing in either is in the `scope_assignments.entity_id` value space — no vendor, order, case, cemetery, grave, or business-entity reference.
- **The one candidate column is explicitly not a key.** The reversals migration annotates `reference` as: "Free-text external reference — the original payment's identifying token, caller-supplied, NOT a foreign key." Both models' doc blocks independently record that these tables deliberately have no FK to `payment_sessions` or any order/booking table.
- **Scoping on `reference` would be worse than no check.** The client supplies `reference` in the POST body. An authorization check whose compared key is chosen by the attacker is not a control; it would let a holder of any single grant forge a matching `reference` and pass.
- **The existing authorizers scope because their records genuinely are scoped** — payout and vendor-payable to `vendor_id`, reconciliation and ledger-read to a business-entity `entity_ref`. The difference here is a property of the record, not a laxer policy.

**Roles:** `finance` or `restricted_admin`, taken from `docs/security/rbac-matrix.md`'s "Payout/refund" row (`Admin: Restricted, Finance: Dedicated finance`) — the same pair `FinanceOrRestrictedAdminPayoutAuthorizer` already uses. No third role is invented.

**Ruling, 12 Aug 2026 (review finding SF-4) — the same pair governs BOTH routes, and this is now a stated decision rather than an inherited one.** The reviewer was right that the Payout/refund row covers the reversal route exactly and did not, as written, name manual payment verification; the nearest other row ("Quote/open payment") gives a plain `admin` the authority and limits finance to read/review. The human ruling is: **same roles for both routes (`finance` / `restricted_admin`), no code change.** The reasoning of record is that both actions are fundamentally "did money move" attestations at the same trust level — recording a reversal and verifying that a payment was received are the same class of judgement in opposite directions, and splitting them would put the two halves of one attestation at two different authorities. Consequences accepted deliberately, not by default: a plain `admin` cannot decide a manual verification, and `finance` gets a decision (not merely read/review) on that path. `docs/security/rbac-matrix.md`'s row description now names manual payment verification so the row genuinely covers what it is cited for, and the authorizer's class doc block states the ruling rather than merely citing the row.

Because the check is record-independent, the authorizer's signature is `authorize(ActorContext $actor): string` with no `$scopeId` parameter. This divergence from the four existing authorizers is deliberate and documented in the class doc block.

### D2 — The check goes in the HTTP controllers, not in the write APIs

The established convention is Action-level (`ManualPayout::pay()` calls `$this->authorizer->authorize(...)` internally). This hotfix deliberately places it at the controller boundary instead:

1. **Each write API's only production caller is its own controller.** Every other reference to `ReversalService` / `VerifyManualPayment` is a direct-call unit test or a doc block.
2. **`ReversalService` is a documented thin dispatcher** that routes by `PaymentReversalType`; injecting an authorization policy into it contradicts its stated single responsibility.
3. **The `abort(403)` convention already lives at the controller boundary** — `app/Http/Controllers/Admin/FinanceExportController.php:58-59` catches `LedgerReadNotAuthorisedException` and calls `abort(403)`. There is no framework-level exception mapping in this app.
4. **Blast radius.** Deriving the role inside the write APIs would change signatures used by ~15 direct call sites across `VerifyManualPaymentTest` and `ReversalServiceTest`, none of which are about authorization.

**Explicitly rejected:** adding `ActorRole::assertKnown($actorRole)` inside the write APIs as defence-in-depth. The audit `actorRole` field legitimately carries values outside `KNOWN_ROLES` — `ReceiveWebhook` writes `'provider'` (`ReceiveWebhook.php:405,521`, `ProcessWebhookEvent.php:263`) and existing tests write `'guest'`. Such a guard would break shipped webhook behaviour.

**Residual risk, stated for the reviewer:** `ReversalService::record()` and `VerifyManualPayment::verify()` still accept a caller-supplied `actorRole` that is passed unchecked into the audit row. That is not exploitable today because no other production caller exists, but a future second caller would re-open the hole. Recorded as a follow-up rather than fixed here, to keep this hotfix tight and reviewable.

### D3 — Ordering: authorize first

Both controllers become: resolve `ActorContext` → **authorize (throws)** → `satisfy(role)` → validate input → look up record → mutate(role).

- Before `satisfy()`, per §1.1.
- Before validation, so a refused actor gets no 422 feedback revealing whether their payload was well-formed.
- Before `PaymentVerification::findOrFail()`, which matters: a role-only check that ran *after* the lookup would turn the endpoint into an existence oracle over verification UUIDs (403 for a real id, 404 for a fake one). Authorizing first means an unauthorized actor always gets 403. This mirrors the existence-oracle defence `ManualPayout.php:224-240` already documents ("an unknown id and an id outside this actor's scope must be indistinguishable"), and is simpler here precisely because the check needs no record.

### D4 — Role vocabulary source

Reference `ActorRole::FINANCE` and `ActorRole::RESTRICTED_ADMIN` rather than redeclaring the string literals a fifth time. `ActorRole` postdates the four `FinancialLedger` authorizers and is now the canonical closed list; `AGENTS.md` §Documentation forbids duplicating canonical data.

## 3. Changes

1. **`app/Platform/Payment/Contracts/PaymentActionAuthorizer.php`** — new interface, `authorize(ActorContext $actor): string`, returns the approved role, throws on refusal.
2. **`app/Platform/Payment/FinanceOrRestrictedAdminPaymentAuthorizer.php`** — implementation. Refuses when `identityReference` is null, or when `$actor->roles` contains neither authorized role. Doc block records D1 and D2.
3. **`app/Platform/Payment/Exceptions/PaymentActionNotAuthorisedException.php`** — `extends RuntimeException`, named constructor `forActorContext()`, no `render()` (matches `PayoutNotAuthorisedException`).
4. **`app/Platform/Payment/Providers/PaymentServiceProvider.php`** — `$this->app->bind(PaymentActionAuthorizer::class, FinanceOrRestrictedAdminPaymentAuthorizer::class)` in `register()`. Transient `bind()`, not `singleton()`, matching the codebase convention.
5. **Both controllers** — authorize first per D3; wrap in `try`/`catch (PaymentActionNotAuthorisedException) { abort(403); }`; replace all four `actorRole: 'authenticated_actor'` occurrences with the role the authorizer returned.

**Not touched:** `GuardPaymentSession`, `GuardCondition` (lane L7 owns them), `SensitiveActions` (verified — `PAYMENT_MANUAL_VERIFICATION`, `PAYMENT_REFUND`, `PAYMENT_CHARGEBACK` already exist at lines 35, 83, 84; nothing to add), and both write APIs' signatures.

## 4. Tests

The existing route tests are **not** absent — `tests/Feature/Payment/RecordPaymentReversalRouteTest.php` (294 lines) and `VerifyManualPaymentRouteTest.php` (242 lines) cover both controllers at the HTTP layer with roughly 22 test methods. Every one authenticates with a bare `actingAs($user)` carrying no roles, so **every success-path test in both files must start granting `finance` or `restricted_admin`**. That those tests fail before being updated is itself the first proof the fix bites.

New coverage, per controller:

1. An authenticated user with **no** role is refused 403, with no `payment_reversals` / decided `payment_verifications` row written, and no `ReauthenticationEvent` row written (proving D3's ordering).
2. An authenticated user with a role that is real but not authorized (`customer`) is refused 403.
3. `finance` succeeds; `restricted_admin` succeeds.
4. The audit row and the `ReauthenticationEvent` record the **real** role, never `authenticated_actor`.
5. An unknown verification id returns 403, not 404, for an unauthorized actor — the existence-oracle assertion from D3.

Unit tests for the authorizer itself mirror `ManualPayoutTest`'s denial/success shape: null `identityReference` refused, empty roles refused, unauthorized role refused, each authorized role returns its own name.

**Mutation testing is mandatory** (a passing test proves nothing until it has been seen to fail): remove the `authorize()` call, confirm the new denial tests fail; restore, confirm they pass. Record both directions in the task report.

## 5. Verification

- Scoped runs (`tests/Feature/Payment`, `tests/Unit/Platform/Payment`) during development; full suite at checkpoints only, given host memory pressure.
- Real PostgreSQL 18 run before done, since this changes authorization and audit behaviour.
- `ci/verify-docs.sh` after the change.
- No `npm run build`, no full `composer install` on this host.

## 6. Impact — this fix is live, not dormant

The role seam **is already wired**, so this fix protects real traffic from the moment it merges.

`app/Platform/IdentityAccess/Adapters/LocalUsersTableIdentityAccessAdapter.php` resolves `roles` from `actor_role_assignments` via `Roles\ActorRoleReader`. Its doc block records that lane L5 "replaced the permanent `roles: []` / `scopes: []` placeholders this class used to hardcode unconditionally," and that this "flips five previously-inert authorizers ... from unconditionally denying to actually enforcing." That landed in commit `3dbdcde`, which is an ancestor of this branch's fork point `d9fea9f` — verified with `git merge-base --is-ancestor`.

Two consequences worth stating plainly for merge review:

- **The bypass being closed is exploitable today, not theoretical.** Any authenticated, MFA-enrolled user can currently record a reversal or approve a manual payment.
- **The new check enforces for real.** An actor holding `finance` or `restricted_admin` passes; everyone else is refused. An empty roles list means "this actor holds no grants today," never "no roles required."

**Caution for anyone copying the precedent:** the four `FinancialLedger` authorizer doc blocks still assert that `ActorContext::$roles` "is always `[]`" and that they refuse every real request. Those sentences predate L5 and are now stale. This hotfix does not inherit that claim and deliberately does not edit those files to correct it — that belongs to whichever lane owns them.

### 6.1 Required deployment step — the admin path goes dark until roles are granted

Whether any account is presently *granted* `finance` or `restricted_admin` is orthogonal to code correctness, but it decides whether the admin path still works after merge. It currently does not:

- Roles are only ever granted by `identity:grant-role {actor} {role} --reason=` (`app/Console/Commands/IdentityGrantRoleCommand.php`), which is console-only and audited.
- **No seeder grants any role.** `database/seeders/DatabaseSeeder.php` contains no role references, and `actor_role_assignments` appears nowhere in `database/` outside its own migration.

So on merge, both endpoints refuse **everyone**, including existing admins, until an operator explicitly grants the roles. That is the correct fail-closed outcome for an authorization fix and must not be softened in code — but it is a real operational change that has to be scheduled alongside the merge, or the manual-verification and reversal flows stop working with no obvious cause.

Whoever signs this off should grant `finance` (or `restricted_admin`) to the operators who legitimately perform these actions, with a reason recorded, as part of the same change window.

**Which roles, for which flow, specifically (added 12 Aug 2026 per SF-4):**

| Flow | Route | Roles that must be granted for it to work |
| --- | --- | --- |
| Record a refund or chargeback | `POST /admin/payments/reversals/{reversalType}` | `finance` **or** `restricted_admin` |
| Decide a manual payment verification (approve/reject) | `POST /admin/payments/manual-verifications/{paymentVerification}/verify` | `finance` **or** `restricted_admin` — the SAME pair, per the D1 ruling above. Whoever performs manual payment verification today most likely holds no role at all and is not an `admin` in the plain sense; **granting plain `admin` will not unblock this flow**, and that is deliberate. |

Both grants go through `identity:grant-role {actor} {role} --reason=`. Granting only one of the two roles is sufficient for both flows; there is no per-flow role.

## 7. Out of scope

- The `actorRole` passthrough in the two write APIs (§D2 residual risk).
- Correcting the stale doc blocks on the four `FinancialLedger` authorizers (§6).
- **Rate limiting either route (review nit N-3).** Neither route carries `throttle` middleware, so probing is unlimited. Explicitly deferred and tracked separately, NOT waived — the fix round below makes probing visible, which is the half that could be done without changing the routes' middleware. Refusal telemetry without a rate limit is a monitoring improvement, not a throttle.

## 8. Fix round, 12 Aug 2026 — refusals are now audited (review finding SF-6)

Added after the task-scoped review: both controllers' `catch (PaymentActionNotAuthorisedException)` was non-capturing, so a refused money-moving attempt wrote no audit row, no log and no metric.

Both now call `RecordPaymentActionRefusal` inside the catch, before `abort(403)`, writing exactly one `AuditOutcome::Denied` row per refusal under a new `PaymentAuditActions::ADMIN_ACTION_DENIED` constant. Design points, all recorded in that class's doc block: one dedicated action rather than reusing `REFUND`/`CHARGEBACK`/`MANUAL_VERIFICATION` (authorization deliberately runs before the `match` that decides refund vs chargeback, and that ordering must not change to suit an audit label); not added to `SensitiveActions`, with a fixed server-side reason string supplied instead; no metadata and no request payload of any kind, since authorization runs before validation; and the write is best-effort — a `Throwable` from it is reported and swallowed so an audit outage can never convert a 403 into a 500 or make refusals distinguishable.
