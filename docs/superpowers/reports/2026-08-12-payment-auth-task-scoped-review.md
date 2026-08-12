# Payment controller authorization hotfix — task-scoped code review

**Date:** 2026-08-12
**Reviewer:** independent task-scoped reviewer (read-only; no source file modified)
**Branch:** `fix/payment-controller-authorization`
**Worktree:** `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix`
**Diff reviewed:** `d9fea9f..8be7e49` (working tree clean at `8be7e49`, `git status --porcelain` empty — no mutation-test residue)
**Plan:** `docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md`
**Implementer report:** `docs/superpowers/reports/2026-08-12-payment-auth-implementation.md`

**Verdict: 0 BLOCKER, 6 SHOULD-FIX, 6 NIT.**

The authorization control itself is correct, unbypassable by any path that exists in this
repository today, correctly ordered, and genuinely tested. I found no way to reach
`ReversalService::record()` or `VerifyManualPayment::verify()` without passing the authorizer.
Every SHOULD-FIX below is about documentation accuracy, the authority basis for one of the two
routes, or refusal observability — none of them is a hole in the control.

---

## 1. Scope of what I verified by reading

Files read in full or in the relevant part:

- `app/Platform/Payment/Contracts/PaymentActionAuthorizer.php` (new)
- `app/Platform/Payment/FinanceOrRestrictedAdminPaymentAuthorizer.php` (new)
- `app/Platform/Payment/Exceptions/PaymentActionNotAuthorisedException.php` (new)
- `app/Platform/Payment/Providers/PaymentServiceProvider.php`
- `app/Platform/Payment/Http/Controllers/RecordPaymentReversalController.php`
- `app/Platform/Payment/Http/Controllers/VerifyManualPaymentController.php`
- `app/Platform/IdentityAccess/ActorContext.php`, `ActorContextResolver.php`,
  `Adapters/LocalUsersTableIdentityAccessAdapter.php`, `Roles/ActorRole.php`,
  `Roles/ActorRoleReader.php`, `Roles/Models/ActorRoleAssignment.php`
- `app/Platform/IdentityAccess/Providers/IdentityAccessServiceProvider.php`
- `app/Http/Middleware/RequireRecentAuthentication.php`,
  `app/Platform/IdentityAccess/Reauthentication/ReauthenticationService.php::satisfy()`
- `app/Platform/FinancialLedger/Actions/ManualPayout.php` (convention comparison),
  `app/Http/Controllers/Admin/FinanceExportController.php` (403 convention)
- `routes/web.php`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php`,
  `bootstrap/providers.php`, `app/Providers/Filament/AdminPanelProvider.php`
- `database/migrations/2026_08_11_100000_create_payment_verifications_table.php`,
  `2026_08_11_100010_create_payment_reversals_table.php`,
  `2026_07_26_130000_create_scope_assignments_table.php`
- `docs/security/rbac-matrix.md`, `AGENTS.md`, `CLAUDE.md`,
  `.kiro/specs/platform-payment-adapter/tasks.md`
- All four test files in the diff.

I did **not** re-run the suites, Pint, PHPStan, or the mutation test — per instruction, the lane
driver's results are taken as given.

---

## 2. Question 1 — is the authorization actually unbypassable?

**Yes, for every path that exists in this repository today.**

Reachability sweep for `ReversalService::record()` and `VerifyManualPayment::verify()`:

| Surface | Result |
| --- | --- |
| `routes/web.php` | Exactly two routes reach these controllers (`admin.payments.reversals.record`, `admin.payments.manual-verifications.verify`). Both now authorize first. |
| `routes/api.php` | One route only: `POST payments/webhook/{merchant}` → `WebhookController`. Does not touch either write API (verified by grep). |
| `routes/console.php` | `inspire` only. |
| `app/Console/Commands/` | 7 commands; none references payment reversal or verification. |
| `app/Jobs/` | Directory does not exist. |
| Filament | `app/Filament/Admin/Resources/` holds only `FaqArticles`; pages are `FinanceReports`, `InAppNotifications`, `MfaChallenge`, `MfaSettings`. No payment resource, action, or page exists. |
| Livewire | No Livewire component references `PaymentVerification` or `PaymentReversal`. |
| Other controllers | None. |
| Model-level writers | `PaymentReversal::createRecorded()` is called only from `Actions/RecordRefund.php:73` and `Actions/RecordChargeback.php:54`, both reached only through `ReversalService`. `PaymentVerification::decide()` is called only from `VerifyManualPayment.php:94`. |

Fail-closed behaviour of the seam itself is also sound:

- `PaymentActionAuthorizer` is an interface with no default resolution. If the binding in
  `PaymentServiceProvider::register()` were ever removed, `app(PaymentActionAuthorizer::class)`
  raises `BindingResolutionException` → 500, not a silent pass. `PaymentAuthorizerBindingTest`
  additionally pins both the binding and `PaymentServiceProvider`'s presence in
  `bootstrap/providers.php:47`.
- The `catch` is type-narrow (`catch (PaymentActionNotAuthorisedException)`). Any other throwable
  from `authorize()` or from `ActorContext` resolution propagates as a 500 — again fail-closed.
- `abort()` is `never`-returning and is called inside the `catch` body, so `$actorRole` is
  definitely assigned on every path that continues.

**One thing is worse than the brief describes** (see SF-5): the pre-fix exposure did **not**
require MFA enrolment. `EnforceMfaChallenge` is attached only in
`app/Providers/Filament/AdminPanelProvider.php:162`, i.e. to the Filament panel's middleware
array. **CORRECTION 12 Aug 2026 (whole-branch finding SF-9): "only in" is false — `EnforceMfaChallenge` is attached in exactly TWO places, `AdminPanelProvider.php:162` and inline at `routes/web.php:282` on `/admin/finance/exports`. The conclusion below (no MFA on these two payment routes) is unaffected and correct; the premise understates the finding, because an adjacent standalone route DOES carry the middleware, which makes the omission conspicuous rather than uniform. The implementers shipped the accurate two-place wording in both controller doc blocks; that wording, not this sentence, is the one to cite.** Both of these routes are plain `web` routes outside the panel, so the only gates were
`auth` plus a `actor_sessions.last_authenticated_at` inside 900 s. Any user who had logged in in
the last 15 minutes could move money. That widens the pre-fix severity, and it makes the fix more
urgent, not less.

---

## 3. Question 2 — ordering, and pre-decision observables

**D3's ordering holds in both controllers.** Confirmed by reading, not by report:

`RecordPaymentReversalController::__invoke()` —
`app(ActorContext::class)` → `authorize()` (line 86–90) → `match($reversalType)` → `satisfy()` →
`$request->validate()` → `ReversalService::record()`.

`VerifyManualPaymentController::__invoke()` —
`app(ActorContext::class)` → `authorize()` (line 89–93) → `satisfy()` → `$request->validate()` →
`PaymentVerification::findOrFail()` → `VerifyManualPayment::verify()`.

Observable-leak analysis before the decision point:

- **Audit rows / `reauthentication_events`.** Nothing is written by the controller before
  `authorize()`. `ReauthenticationService::satisfy()` (line 153–181) is the only writer, and it is
  now unconditionally downstream of the decision. The two `..._is_refused_and_writes_nothing`
  tests assert `ReauthenticationEvent::query()->count() === 0`, which is a real assertion here
  because a *fresh* actor never triggers the middleware's `challenge()` path either.
- **MFA rate-limiter state.** `MfaRateLimiter::clear()` lives inside `satisfy()`
  (`ReauthenticationService.php:178`), so a refused actor no longer resets it. Correct, and this
  was a genuine second defect closed by the same reordering.
- **Existence oracle.** Confirmed closed. `PaymentVerification::findOrFail()` is strictly after
  `authorize()`, the route carries no route-model binding (the controller signature is
  `string $paymentVerification`), and the refusal exception has exactly one named constructor
  carrying no record data. The mutation run reproduced the oracle live (404 vs 302 with the check
  removed) — the strongest single piece of evidence in the implementer's report.
- **Status-code differences.** An unauthorized actor gets a flat 403 for: a valid body, an empty
  body (would otherwise be 422), a real id, and a fabricated id. Pinned by
  `test_an_unauthorized_actor_gets_403_not_a_validation_error` and
  `test_an_unknown_verification_id_is_403_not_404_for_an_unauthorized_actor`.
- **Timing.** `ActorContext` resolution performs the same queries (roles, scopes, MFA enrolment,
  actor session) for every authenticated caller regardless of the outcome, so there is no
  role-dependent query-count difference before the decision. The authorizer itself does no I/O.
- **Not a leak, but worth stating:** `RequireRecentAuthentication` runs *before* the controller
  and, for a *stale* actor, writes a `reauthentication_events` challenge row and an audit row and
  consumes limiter state. That happens identically for authorized and unauthorized actors, so it
  reveals nothing about the authorization decision. Pre-existing, unchanged, and correct to leave
  alone.

---

## 4. Question 3 — the authorizer's own logic

`FinanceOrRestrictedAdminPaymentAuthorizer::authorize()` is 14 lines and I could not construct an
input shape that returns a role it should not.

- `$actor->identityReference === null` → refuse, checked first and on its own, so a guest context
  carrying a populated `roles` array is still refused (pinned by
  `test_a_null_identity_reference_is_refused`).
- Role match is `in_array($role, $actor->roles, true)` — **strict**, so no type-juggling admission
  (`0 == 'finance'` style) is possible.
- The allow-list is a `private const array` of two `ActorRole` class constants. It cannot be
  widened at runtime and cannot be influenced by request data.
- `authenticated_actor` can never be returned: it is not in `AUTHORISED_ROLES`, `ActorRole` does
  not declare it, and `ActorRoleAssignment::booted()` calls `ActorRole::assertKnown()` on every
  save, so a row claiming that role cannot even be persisted.
- An empty `roles` list falls through `roleFromContext()` to `null` → refuse. There is no branch
  that reads emptiness as permission.

`ActorContext::$roles` provenance verified end to end and it is **real, live grant data**:
`IdentityAccessServiceProvider.php:53` binds `ActorContext` as `scoped()` to
`ActorContextResolver::resolve()`; the resolver reads the `web` guard's user and delegates to
`LocalUsersTableIdentityAccessAdapter::resolveActorContext()`
(`LocalUsersTableIdentityAccessAdapter.php:71-86`), which populates `roles` from
`ActorRoleReader::rolesForActor()`. That reader filters `->whereNull('revoked_at')`, so **revoked
grants are genuinely excluded** — and the route tests prove it end to end with
`test_a_revoked_role_grant_no_longer_authorizes`, which the mutation run killed.

The implementer is **right** and the plan's original text was wrong (see SF-3 for the residual
mess this left behind).

---

## 5. Question 4 — does the role pair match the authority?

`finance` and `restricted_admin` are both in `ActorRole::KNOWN_ROLES` (`ActorRole.php:113-122`),
and the constant order (`RESTRICTED_ADMIN` then `FINANCE`) matches `KNOWN_ROLES` precedence order,
so the "most privileged wins" behaviour the tests assert is real and not incidental.

`docs/security/rbac-matrix.md:16` — `| Payout/refund | No | No | No | View own | Restricted | Dedicated finance |`
— is an exact match **for the reversal route**. Same pair `FinanceOrRestrictedAdminPayoutAuthorizer`
already uses. No third role invented. Good.

**The verification route is not covered by that row** — see SF-4.

---

## 6. Question 5 — test quality

I looked specifically for assertions that would still pass with the fix removed, beyond the ones
the mutation test already killed. The tests are in good shape; the denial tests reach the
authorization check for the right reason.

Verified genuinely:

- **The middleware-first concern is real and correctly handled.** `RequireRecentAuthentication`
  treats a null `lastAuthenticatedAt` as stale (`RequireRecentAuthentication.php:174-176`) and
  redirects to the challenge. So a denial test built on a bare `User::factory()->create()` would
  have returned 302, not 403, and `assertForbidden()` would have failed — it would not have
  "passed for the wrong reason", it would simply have failed. The implementer's reasoning for
  `freshlyAuthenticatedUser()` (a recent `actor_sessions` row, no role grant) is sound and
  necessary: it is what makes the 403 attributable to the authorizer.
- **The role is seeded the way production seeds it** — a real `actor_role_assignments` row, so the
  whole chain (table → reader → adapter → resolver → controller → authorizer) is exercised rather
  than a container-substituted `ActorContext`. For an authorization fix this is the right call and
  is a strict improvement over `ManualPayoutTest`'s `$this->app->instance(ActorContext::class, …)`
  shape.
- **The two "without a fresh authentication" tests now grant a role** so their 302 is
  unambiguously the middleware's, not the authorizer's. Correct disambiguation.
- `test_an_authenticated_actor_with_no_role_is_refused_and_writes_nothing` — the
  `ReauthenticationEvent::query()->count() === 0` assertion is non-vacuous precisely because the
  actor is fresh (the middleware writes no challenge row for a fresh actor), so any row in that
  table could only have come from `satisfy()`.
- `test_the_audit_trail_records_the_real_role_never_the_sentinel` — uses `sole()`, so it also
  pins "exactly one row", and additionally asserts zero rows carry `authenticated_actor` in either
  table.
- `test_recording_via_http_leaves_payment_sessions_untouched` and
  `test_deciding_via_http_leaves_payment_sessions_untouched` were correctly converted to
  `authorizedUser()`; left on a bare user they would have become vacuous (a 403 also leaves
  `payment_sessions` empty). The implementer caught this explicitly. Good.
- The unit test file covers all six non-authorized `KNOWN_ROLES` entries by data provider,
  including `admin`, plus the sentinel, plus both input orders for the dual-role case, plus a
  string identity reference.

Tests that still pass with the fix removed, and correctly so (they pin other behaviour):
`test_*_without_a_fresh_authentication_*`, `test_the_route_is_registered_with_the_reauthentication_middleware`,
`test_an_unauthenticated_request_is_refused_by_the_auth_guard`,
`test_an_invalid_reversal_type_segment_is_rejected_by_the_route_itself`, and the three
`PaymentAuthorizerBindingTest` methods. None of these claims to test authorization.

Gaps: N-4 and N-5 below.

---

## 7. Question 6 — plan conformance

| Plan item | Status |
| --- | --- |
| **D1** role-only, no scope check | Implemented as specified. Argument re-verified against the real migrations — see §8. |
| **D2** check at the controller boundary | Implemented. Residual risk carried forward and documented in the class doc block and report §8.4. |
| **D3** authorize → satisfy → validate → findOrFail → write | Implemented in both controllers, verified by reading. |
| **D4** reference `ActorRole::FINANCE` / `RESTRICTED_ADMIN` rather than new literals | Implemented. No fifth copy of the role strings added. |
| **§3.1** new interface | Present, signature as specified. |
| **§3.2** implementation | Present. |
| **§3.3** exception, `RuntimeException`, no `render()` | Present. |
| **§3.4** transient `bind()` in `register()` | Present, `PaymentServiceProvider.php:80-83`. |
| **§3.5** both controllers, all four sentinel occurrences replaced | Verified: zero `actorRole: 'authenticated_actor'` call sites remain in `app/Platform/Payment/`; the only three remaining occurrences of the string are explanatory comments. **CORRECTION 12 Aug 2026 (whole-branch finding SF-8) — this cell was true at `8be7e49` and stopped being true afterwards; do not cite it as a current property.** The SF-6 fix round added `RecordPaymentActionRefusal`, which deliberately writes the sentinel on the refusal path, and the count of string occurrences changed too. FINAL state after the 12 Aug final fix round (SF-5): the sentinel is no longer written unconditionally — `RecordPaymentActionRefusal::auditRoleFor()` records the refused actor's real, most-privileged held role from `ActorRole::KNOWN_ROLES`, and falls back to `authenticated_actor` only for an actor who holds none, or `guest` for an actor with no resolved identity. There is no `actorRole: 'authenticated_actor'` literal call site anywhere in the module; the string survives as one private class constant (`RecordPaymentActionRefusal::ROLE_AUTHENTICATED_ACTOR`) plus six explanatory comments. See that class's `auditRoleFor()` doc block. |
| **§4.1** no-role → 403, no domain row, no `ReauthenticationEvent` | Present in both files. |
| **§4.2** real-but-unauthorized role (`customer`) → 403 | Present in both files. |
| **§4.3** `finance` and `restricted_admin` both succeed | Present via `#[DataProvider]` in both files. |
| **§4.4** audit + reauth event carry the real role | Present in both files. |
| **§4.5** unknown verification id → 403 not 404 | Present (verification file). |
| **§4** unit tests mirroring `ManualPayoutTest` shape | Present, and broader than asked. |
| **§4** mutation test mandatory, both directions | Executed and recorded (report §6.1–6.4), and reproduces the existence oracle live. |
| **§5** real PostgreSQL run | Executed by the lane driver after the report was written (218 tests green on PostgreSQL 18.4). Report §8.1 still says NOT TESTED — stale but harmless; see N-6. |

**Judgement on the five documented deviations (report §9):**

1. *Refusing to write the instructed stale doc-block sentence* — **correct and important**. The
   instruction was factually wrong; writing it would have put a false claim about a live
   authorization control into the code. Verified independently against
   `LocalUsersTableIdentityAccessAdapter` and `ActorRoleReader`. However the replacement text
   introduced its own false claim — see SF-3.
2. *Leaving the four `FinancialLedger` doc blocks alone* — **sound**. Explicitly out of scope
   (plan §7), and they are another lane's files.
3. *Authorization above the `reversalType` `match`* — **sound and slightly better than the plan**.
   The `match`'s `default` arm is unreachable behind the route's `whereIn` constraint, so this is
   not load-bearing, and putting the check unconditionally first is the ordering that cannot be
   got wrong by a later edit.
4. *Extra binding test file* — **sound**. An unbound authorization interface is exactly the shape
   of silent-failure this repo has already been bitten by (`FeatureGateServiceProvider`'s own
   comment records the precedent), and the test is cheap.
5. *`abort(403)` inside the `catch`* — **sound**. Matches `FinanceExportController:58-59` exactly,
   and PHPStan accepts it because `abort()` is `never`-returning.

**Who is right about plan §6:** the implementer, about the *original* plan. `git show
83d6398:…` shows the plan's §6 originally read "`ActorContext::$roles` is `[]` under the current
local identity adapter, so these endpoints will **fail closed for everyone**." That was stale, and
the implementer was right to refuse it. But commit `f548b53` ("docs(payment): correct a stale
claim about the role seam being unwired") already fixed the plan **before** the implementation
commit — so the correction the report asks for has already happened, and the new code's own
cross-reference to it is now the stale artefact. That is SF-3.

---

## 8. Known-and-accepted items — re-checked, none worse than described

- **Role-only with no scope check.** D1's argument is **sound against the real migrations**.
  `2026_08_11_100010_create_payment_reversals_table.php` and
  `2026_08_11_100000_create_payment_verifications_table.php` both carry no vendor/order/case/
  cemetery/grave/business-entity column, and the verifications migration annotates `reference` as
  "Free-text external reference — NOT a foreign key" with "caller-supplied" stated in its own doc
  block (lines 24-34). `scope_assignments.entity_id` is a plain string keyed by
  `ScopeEntityType::KNOWN_TYPES`; nothing in either payment table lives in that value space. The
  "scoping on an attacker-chosen key is worse than not scoping" conclusion follows. **But the
  canonical security doc was not updated to record the exception — SF-1.**
- **Caller-supplied `actorRole` still accepted by the write APIs.** Confirmed still true, and
  confirmed still not exploitable: each write API has exactly one production caller, and that
  caller now passes only the authorizer's return value. Correctly carried forward as a follow-up.
- **Stale `FinancialLedger` doc blocks.** Untouched, as scoped. Not made worse.
- **Fails closed for everyone on merge until roles are granted.** Confirmed: role grants exist
  only via `IdentityGrantRoleCommand`, and no seeder writes `actor_role_assignments`. Plan §6.1
  documents this correctly and it is the right outcome. It is an operational prerequisite, not a
  code defect — and SF-4 interacts with it (see below).

---

## 9. Findings

### BLOCKER

**None.** I looked specifically for a bypass path, an ordering leak, an input shape that admits an
unauthorized actor, and a vacuous denial test, and did not find one. The control is correct.

### SHOULD-FIX

---

**SF-1 — `docs/security/rbac-matrix.md:31` now states a security invariant that this change makes false.**

*File:* `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/docs/security/rbac-matrix.md`, line 31.

The document reads:

> "A role never by itself grants access to a record: the shipped authorizers require a role **and** a scope grant."

As of this commit that sentence is false. `FinanceOrRestrictedAdminPaymentAuthorizer` is a shipped
authorizer that grants access on a role alone. `AGENTS.md` §Authorization and files also says
"Policies and query-level scope are mandatory," and §Documentation requires updating the spec when
behaviour changes.

*Why it matters:* this is the canonical security reference a future implementer will read to
decide whether their own authorizer needs a scope grant. Leaving it absolute means the next person
either copies the role-only shape without D1's schema justification, or wastes a review cycle
believing this change violates a hard rule. The deviation is defensible; being undocumented is not.

*Suggested fix:* add one sentence to `rbac-matrix.md` immediately after line 31 recording the
narrow exception and its basis — e.g. "Exception: `Payment\FinanceOrRestrictedAdminPaymentAuthorizer`
is role-only, because `payment_reversals` and `payment_verifications` carry no column in the
`scope_assignments.entity_id` value space and their one candidate column (`reference`) is
caller-supplied free text; see that class's doc block." Do not weaken the general rule.

---

**SF-2 — `.kiro/specs/platform-payment-adapter/tasks.md:54` still describes the verification route's authorization as re-authentication only.**

*File:* `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/.kiro/specs/platform-payment-adapter/tasks.md`, line 54 (and the AC8 narrative at line 53).

The spec's completion narrative records the route as "wired for real … behind
`App\Http\Middleware\RequireRecentAuthentication`" with no mention of a role gate, and AC8's
"separate authorized action" is described purely in terms of the state transition and audit.
`AGENTS.md` §Documentation: "Update spec, traceability, screen inventory, API contract, and test
when behavior changes." The behaviour of a shipped, spec-tracked route changed here.

*Why it matters:* `.kiro/specs/*/tasks.md` is named in `AGENTS.md` §Development methodology as the
durable "what does the spec require" authority. A route whose authorization model changed and
whose spec entry does not say so is exactly the drift that rule exists to prevent — and it is the
document a future lane will read before touching this route.

*Suggested fix:* append one clause to the item at line 54 naming
`Payment\Contracts\PaymentActionAuthorizer` and the `finance` / `restricted_admin` requirement,
pointing at this hotfix's plan. One line; no restatement of the role list beyond the two names
already in the RBAC matrix.

---

**SF-3 — `FinanceOrRestrictedAdminPaymentAuthorizer.php:83-84` asserts a false fact about the plan it implements.**

*File:* `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/FinanceOrRestrictedAdminPaymentAuthorizer.php`, lines 83-84.

```
 * Two stale claims are deliberately NOT repeated here. The hotfix plan's §6
 * describes `$roles` as permanently `[]`, and each of the four
 * `FinancialLedger` authorizers still says the same in its own doc block;
```

Verified against the tree: the plan at `HEAD` says the exact opposite. Commit `f548b53`
("docs(payment): correct a stale claim about the role seam being unwired") rewrote §6 into
"Impact — this fix is live, not dormant", and moved "Out of scope" to §7. That commit is an
ancestor of the implementation commit `8be7e49`, so the code shipped a cross-reference to a
document state that no longer existed when it was written. `docs/superpowers/reports/2026-08-12-payment-auth-implementation.md`
§9.1 carries the same now-false instruction ("Plan §6 is therefore stale on this point and should
be corrected by whoever owns the plan doc" — it already was).

*Why it matters:* this is the class doc block of a live money-moving authorization control, and it
tells the reader that the governing plan document is wrong about the single most important fact in
the change (whether the role seam enforces for real). A reader who follows the pointer finds the
opposite and now distrusts the rest of the block, which is otherwise accurate and load-bearing.
`AGENTS.md` §Documentation forbids exactly this kind of hand-maintained drift.

*Suggested fix:* in the class doc block, drop "The hotfix plan's §6 describes `$roles` as
permanently `[]`, and" and keep the remaining, still-true clause about the four `FinancialLedger`
authorizers. Correspondingly, amend report §9.1's closing sentence to record that plan §6 **was**
corrected in `f548b53` rather than asking for a correction that already landed.

---

**SF-4 — the verification route's role pair is not derived from any authority; the RBAC row nearest to it says something different.**

*Files:*
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/FinanceOrRestrictedAdminPaymentAuthorizer.php`, lines 17-20 (the doc block's authority claim) and 111-114 (`AUTHORISED_ROLES`);
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md`, §D1 "Roles" paragraph.

D1 justifies the pair `finance` / `restricted_admin` from `docs/security/rbac-matrix.md:16`'s
**Payout/refund** row. That row governs the reversal route exactly. It does not obviously govern
"approve or reject a manual payment verification", which is a decision about whether an inbound
customer payment is accepted. The nearest row in the same table is **Quote/open payment**
(`rbac-matrix.md:11`): `| Accept only | Prepare/request | No | No | Authorized | Read/review |` —
which gives a plain **`admin`** authority ("Authorized") and gives finance only "Read/review".

The change therefore, for the verification route:

- **excludes `admin`**, who the nearest matrix row marks as authorized. Combined with plan §6.1's
  fail-closed-on-merge behaviour, the practical outcome is that admins cannot verify manual
  payments after merge and there is no documented row saying they should not be able to;
- **includes `finance`** with decision authority the nearest matrix row limits to read/review.

Neither direction is a security hole — the change is a large net tightening either way, and a
reasonable reading is that manual payment verification is a finance/reconciliation function rather
than a quote/open-payment function. But this is a money-moving authority assignment going to human
sign-off with no stated basis, and it has an operational consequence.

*Why it matters:* the plan's own D1 sets the standard ("taken from `rbac-matrix.md`'s Payout/refund
row … No third role is invented"), and the verification route silently inherits a row that does not
name it. The signer needs to actively decide, not inherit.

*Suggested fix:* two options, either acceptable. (a) Get an explicit human ruling that manual
payment verification is governed by the Payout/refund row, and record that in
`rbac-matrix.md` by naming the action in that row's description plus one sentence in the
authorizer's doc block — no code change. (b) If the ruling goes the other way, split the policy so
the verification route's allow-list differs from the reversal route's. Do **not** simply add
`admin` to the shared list without a ruling; `admin` is deliberately excluded from Payout/refund
and the unit test at `FinanceOrRestrictedAdminPaymentAuthorizerTest.php:101` pins that exclusion.

Whichever way it goes, plan §6.1's deployment note should name which roles must be granted to
which operators for the verification flow specifically, not just "finance (or restricted_admin)".

---

**SF-5 — the new doc blocks overstate the pre-fix compensating controls (MFA was never required).**

*Files:*
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/Http/Controllers/RecordPaymentReversalController.php`, line 37;
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/Http/Controllers/VerifyManualPaymentController.php`, line 44;
same claim in `docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md`, line 19.

Both controllers' new doc blocks say the pre-fix exposure was "Any authenticated user **who had
enrolled MFA** and satisfied the recency window."

That is not what the middleware stack did. `EnforceMfaChallenge` is registered only in
`app/Providers/Filament/AdminPanelProvider.php:162`, i.e. on the Filament panel's own middleware
array. **CORRECTION 12 Aug 2026 (whole-branch finding SF-9): "only in" is false — two attachment points, `AdminPanelProvider.php:162` and inline at `routes/web.php:282`. See the correction at §2 above; the finding's conclusion stands and is if anything stronger.** Neither of these routes is a panel page — both are plain `web` routes declared in
`routes/web.php:342` and `routes/web.php:371` with `['web', 'auth', RequireRecentAuthentication…]`.
`RequireRecentAuthentication` reads only `ActorContext::$lastAuthenticatedAt`
(`RequireRecentAuthentication.php:172-183`) against
`config('reauthentication.freshness_seconds')` (default 900). It never consults `mfaState`.

So the actual pre-fix precondition was: authenticated + logged in within the last 15 minutes. No
MFA enrolment at all.

*Why it matters:* this understates the severity of the vulnerability being closed, in the very
comments a human signer will read to calibrate urgency, and it credits a compensating control that
does not exist on these routes. If anyone later reasons "MFA still gates it" while making a change
here, that reasoning is wrong.

*Suggested fix:* change both doc-block sentences to "Any authenticated user whose last login fell
inside `reauthentication.freshness_seconds` (default 900 s) could POST here — `EnforceMfaChallenge`
is attached to the Filament panel only, so these plain `web` routes carried no MFA gate at all."
Correct plan §1's sentence the same way (the plan is the artefact of record for the severity claim).

**CORRECTION 12 Aug 2026 (whole-branch finding SF-9): do not copy the dictated text above — its
"attached to the Filament panel only" clause is factually wrong.** `EnforceMfaChallenge` is
attached in exactly two places: `AdminPanelProvider.php:162` and inline at `routes/web.php:282` on
`/admin/finance/exports`. The implementers noticed this and shipped the accurate two-place wording
instead, in both controller doc blocks and in plan §1; that is the wording of record. Plan §6's
surviving instance of the same claim was corrected in the 12 Aug final fix round (whole-branch
finding SF-1).

---

**SF-6 — a refused money-moving action leaves no trace anywhere.**

*Files:*
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/Http/Controllers/RecordPaymentReversalController.php`, lines 88-90;
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/Http/Controllers/VerifyManualPaymentController.php`, lines 91-93.

```php
} catch (PaymentActionNotAuthorisedException) {
    abort(403);
}
```

The `catch` is non-capturing, so the exception — and the actor reference it carries — is discarded
entirely. Nothing is logged, no audit row is written, no metric is emitted. An actor probing either
endpoint can do so indefinitely and leave zero evidence: there is also no `throttle` middleware on
either route (see N-3).

Precedent in this codebase is genuinely mixed, which is why this is SHOULD-FIX and not BLOCKER:
`ManualPayout` also throws its authorizer refusal without an audit row
(`ManualPayout.php:234-239`), but `DocumentVault\Actions\IssueSignedUrl` writes
`AuditOutcome::Denied` on every policy refusal (`IssueSignedUrl.php:195`, `:313`, `:320`), and
`ReauthenticationService::challenge()` exists precisely so a refused sensitive action leaves a row.
For the two highest-value admin endpoints in the application, the `IssueSignedUrl` precedent is the
better one.

*Why it matters:* an authorization control with no telemetry on refusals cannot be monitored, and
credential-stuffing or insider probing against these two endpoints is invisible. The new tests
currently assert `AuditEvent … count() === 0` on denial, so this is also a decision the tests have
already frozen in place — changing it later means changing those assertions.

*Suggested fix:* capture the exception and write one `AuditOutcome::Denied` row per refusal with
the actor reference, the action constant already available in `PaymentAuditActions`, and no request
payload — then narrow the existing denial assertions from "zero audit rows" to "zero rows with
`AuditOutcome::Allowed` / zero `PaymentAuditActions::REFUND` mutation rows, exactly one `Denied`
row". If the reviewer prefers to keep parity with `ManualPayout` instead, that is defensible, but
then record the decision explicitly in the plan's out-of-scope section rather than leaving it
implicit — and pair it with a rate limit (N-3).

---

### NIT

**N-1 — stale route-constraint reference in a test comment.**
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/tests/Feature/Payment/RecordPaymentReversalRouteTest.php`, line 404: the comment says "the route's own `->where('reversalType', ...)` constraint". The route
(`routes/web.php:372`) uses `->whereIn('reversalType', ['refund', 'chargeback'])`. The plan's D3
and report §9.3 carry the same `->where(...)` phrasing. Cosmetic; the behaviour described is
correct. Fix: say `whereIn`.

**N-2 — one unit assertion is fragile against its own subject.**
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/tests/Unit/Platform/Payment/FinanceOrRestrictedAdminPaymentAuthorizerTest.php`, line 192: `assertStringNotContainsString(ActorRole::CUSTOMER, $exception->getMessage())`. It passes only
because the data case is `customer`; the refusal message literally contains the substrings
`finance` and `restricted-admin`, so if anyone ever parameterises this test over
`unauthorizedRoles()` or swaps the role, it fails for a reason that is not a leak. Fix: assert on
the message's *shape* instead — e.g. `assertSame` against the expected full string, or assert the
message does not contain the word `roles`/the record id.

**N-3 — neither route carries a rate limit.**
`routes/web.php:342` and `routes/web.php:371` carry no `throttle` middleware, unlike
`internal.documents.download` (`throttle:document-download`) and the webhook route. Pre-existing
and out of this hotfix's scope, but it compounds SF-6: unlimited unauthenticated-adjacent probing
of two money-moving endpoints, with no record. Worth a follow-up ticket alongside SF-6.

**N-4 — no test pins that an *authorized* actor still gets 404 for an unknown verification id.**
The existence-oracle test correctly proves 403-for-both from the *unauthorized* side, but nothing
proves the reordering left the legitimate `findOrFail()` path intact (an authorized actor posting a
fabricated UUID should still get 404). Cheap to add to
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/tests/Feature/Payment/VerifyManualPaymentRouteTest.php`
next to `test_an_unknown_verification_id_is_403_not_404_for_an_unauthorized_actor`.

**N-5 — `identityReference` of `''` or `0` is treated as present.**
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/FinanceOrRestrictedAdminPaymentAuthorizer.php`, line 118: `if ($actorReference === null)`. An empty-string or zero identity reference passes the identity
check and is then gated only by the role lookup. Not exploitable with the current adapter
(`LocalUsersTableIdentityAccessAdapter::normalizeIdentifier()` returns a real `users.id`) and the
role lookup fails closed anyway, but a future K1/K2 adapter that returns `''` for "unresolved"
would be read as an identity. Optional hardening: `if ($actorReference === null || $actorReference === '')`,
or use `$actor->isAuthenticated()`.

**N-6 — implementer report §8.1 is now stale.**
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/docs/superpowers/reports/2026-08-12-payment-auth-implementation.md`, §8 item 1 still reads "**PostgreSQL 18 — NOT TESTED** … This is an outstanding plan
requirement, not a waived one." The lane driver has since run the payment module suite green on
real PostgreSQL 18.4 (218 tests, 928 assertions). The report was right to flag it at the time and
right to use NOT TESTED rather than PASS (`AGENTS.md` §Infrastructure-agent execution), but the
signer will read this line as an open gate. Fix: add a one-line "resolved — run performed by the
lane driver on PostgreSQL 18.4, 218 tests / 928 assertions" note, keeping the original text so the
history stays honest.

---

## 10. Things I checked that turned out clean

Recorded so the whole-branch reviewer does not repeat them:

- No path to either write API outside the two controllers (§2 table).
- No `ActorContext` construction inside either controller; both read the `scoped()` container
  binding, so the actor cannot be spoofed from request state.
- `ActorContextResolver` caches per request/job via `scoped()`, so authorizing first does not cost
  an extra identity resolution and cannot see a different actor than `satisfy()` later does.
- `ActorRoleReader::rolesForActor()` filters `whereNull('revoked_at')` — revocation is honoured,
  and the route tests prove it end to end.
- `ActorRoleAssignment::booted()` calls `ActorRole::assertKnown()` on save, so the
  `authenticated_actor` / `guest` sentinels cannot be persisted as grants.
- `AUTHORISED_ROLES` order matches `ActorRole::KNOWN_ROLES` precedence, so the "most privileged
  wins" audit behaviour is deterministic and matches `DocumentAccessPolicy::auditRoleFor()`'s
  convention.
- Zero `actorRole: 'authenticated_actor'` call sites remain in `app/Platform/Payment/`. **CORRECTION 12 Aug 2026 (whole-branch finding SF-8):** true at `8be7e49`, superseded twice since. The SF-6 fix round introduced one deliberate sentinel write in `RecordPaymentActionRefusal`; the 12 Aug final fix round (SF-5) then made it conditional, so the module now records the refused actor's real role and reaches the sentinel only when the actor genuinely holds none. No literal `actorRole: 'authenticated_actor'` argument remains, but the claim as phrased is no longer the check a future reader should run — grep for `ROLE_AUTHENTICATED_ACTOR` and read `RecordPaymentActionRefusal::auditRoleFor()` instead.
- `PaymentServiceProvider` is registered at `bootstrap/providers.php:47`; the binding is transient
  and the binding test pins all three properties.
- Working tree at `8be7e49` is clean — no mutation-test artefacts left behind.
- No design tokens, Blade, or CSS touched, so `ci/verify-docs.sh`'s hardcoded-value scan is not
  implicated by this change.
- No restricted data (KTP/KK/document content/amounts/references) appears in the exception message,
  logs, or test output. The exception carries the actor reference only, and it is discarded at the
  catch site (which is SF-6's problem, not a leak).

---

## 11. Recommendation

**Approve for human sign-off after SF-1 through SF-3 and SF-5 are applied** — all four are
single-sentence documentation corrections, none touches the control.

**SF-4 needs a human decision before merge**, not a code change by an agent: it is an authority
assignment on money-moving actions, and `AGENTS.md` §Infrastructure-agent execution reserves that
to human review. It also determines what plan §6.1's grant step should say.

**SF-6 is a legitimate follow-up** if the signer prefers parity with `ManualPayout`; if it is
deferred, record it explicitly in the plan's out-of-scope list together with N-3 rather than
leaving it implicit.

The security fix itself is correct and I recommend it ships.
