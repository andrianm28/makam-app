# Final fix round — payment controller authorization hotfix

**Date:** 12 Aug 2026
**Branch:** `fix/payment-controller-authorization`
**Worktree:** `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix`
**Base:** `51d6a85`, clean tree at start
**Input:** `2026-08-12-payment-auth-whole-branch-review.md` — the nine SHOULD-FIX items
**State:** everything below is **uncommitted**, by instruction. Nothing was committed or pushed.

This is the last bounded round before human merge sign-off. Scope was exactly the nine SHOULD-FIX
items; the nine NITs, the rate limit, the MFA middleware itself, the `FinancialLedger` doc blocks,
the AC9 guard test, and the pre-existing CASCADE errors in other lanes were all out of scope and
are untouched.

---

## 1. Disposition of the nine SHOULD-FIX items

| # | Item | Disposition |
| --- | --- | --- |
| SF-1 | Plan §6 still claimed an "MFA-enrolled user" precondition | **Done** — corrected, plus a dated note in the fix-round report whose "all three places" claim it falsified |
| SF-2 | Reversal route's spec entry lacked the "Superseded" note | **Done** |
| SF-3 | Verification records contradicted each other | **Done** — over-claim narrowed, both NOT TESTED items given dated resolutions |
| SF-4 | No test pinned the refusal row's `actor_ref` | **Done** — pinned in three files, mutation-tested |
| SF-5 | Refusal row recorded the sentinel even for a real role | **Done** — code + tests, mutation-tested |
| SF-6 | No MFA gate on two now-privileged routes, and no record of it | **Deferred, explicitly and with reasoning**, in plan §7. Middleware NOT added |
| SF-7 | `identity:grant-role` step lived only in the SDD plan | **Done** — added to `docs/operations/ci-cd-and-release.md` §5.1 and §8 |
| SF-8 | Task-scoped review's "zero sentinel call sites" claim now false | **Done** — dated correction appended in both places, reflecting the FINAL state after SF-5 |
| SF-9 | Task-scoped review miscounts `EnforceMfaChallenge` attachment points | **Done** — dated correction appended in all three places |

---

## 2. SF-1 — the surviving MFA claim

`docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md:120` said *"Any authenticated,
MFA-enrolled user can currently record a reversal or approve a manual payment."* That is the exact
claim the earlier SF-5 round retracted in plan §1 and both controller doc blocks; this fourth
instance survived and understated the severity of a live authorization bypass.

Replaced with the 900 s freshness-window wording and an explicit "no MFA gate existed on these
routes", carrying a dated correction note so the retraction is visible rather than silent.

The fix-round report's §4 claim *"All three places now say so"* was falsified by the same gap, so a
dated correction was appended there too, keeping the original text unedited.

**Repo-wide sweep for other surviving instances.** Every file changed by this branch was grepped
for `MFA`. What remains is correct: both controller doc blocks and plan §1 carry the accurate
two-attachment-point wording; the fix-round report §4 records the correction; the task-scoped
review's remaining hits are the finding text itself, now carrying the SF-9 corrections. No file on
this branch now claims these two routes were ever gated by MFA.

---

## 3. SF-2 — the reversal route's spec entry

`.kiro/specs/platform-payment-adapter/tasks.md` item 2 of the Task 6 Wave 1d correction now carries
the same `**Superseded 12 Aug 2026:**` clause its manual-verification twin (item 2 of the Task 5
correction) already had, naming `PaymentActionAuthorizer`, the same role pair, and the plan. Shape
and wording match the existing note deliberately — the asymmetry was the defect.

---

## 4. SF-3 — reconciling the verification records

Two edits, in opposite directions, both appended rather than rewritten per this repository's
correction convention.

**Over-claim narrowed.** `2026-08-12-payment-auth-implementation.md` §8 item 1's *"The plan §5
requirement is met; this line is no longer an open gate"* now carries a dated NARROWED note: that
sentence was true only for `8be7e49`, because the 218-test PostgreSQL run predates `51d6a85`'s
refusal-audit write and could not have covered it. The note points the reader at the fix-round
report's §8 for the current state.

**Under-claim resolved.** `2026-08-12-payment-auth-fix-round.md` §8 items 1 and 2 keep their
original NOT TESTED text verbatim and each gains a dated RESOLVED line recording, explicitly as the
lane driver's observed output rather than a run performed by the report's own author:

- **PostgreSQL 18.4**, disposable container on port 55572, at `51d6a85`'s content:
  payment module **227 tests, 1028 assertions** — the same counts as the SQLite run of the same
  content. Both PostgreSQL-specific risks named in the original item are therefore exercised rather
  than argued.
- **Full suite** at `51d6a85`'s content: **1859 tests, 7201 assertions, 0 failures**, plus 2 errors
  that are pre-existing `DROP TABLE ... CASCADE` SQLite incompatibilities in other lanes' tests
  (`HomePageRouteTest`, `EloquentGateRegistrySourceTest`) — files this branch does not touch and
  code paths it does not reach.

**Deliberately still NOT TESTED, and recorded as such in the same place:** the tests added by *this*
round postdate the PostgreSQL run above and have been exercised on SQLite only. The lane driver
owns that re-run. Nothing in this report claims otherwise.

---

## 5. SF-4 — pinning the refusal row's actor reference

The gap was real: setting `actorRef: null` in `RecordPaymentActionRefusal` left all 227 payment
tests green. `actor_ref` is the field that makes the row a monitoring signal rather than a counter.

Pinned in three places:

- `tests/Feature/Payment/RecordPaymentReversalRouteTest.php` —
  `assertTheRefusalWasAuditedExactlyOnce(User $actor, string $expectedRole)`.
- `tests/Feature/Payment/VerifyManualPaymentRouteTest.php` —
  `assertTheRefusalWasAuditedExactly(User $actor, string $expectedRole, int $expected = 1, ?string $unknownId = null)`.
- `tests/Unit/Platform/Payment/RecordPaymentActionRefusalTest.php` (new) — direct assertions on the
  written row, including a string identity reference (`ActorContext::$identityReference` is typed
  `int|string|null` for a future K1/K2 adapter, and must survive either way).

Both helper parameters are **required, not optional**. An optional assertion is one a future caller
silently skips, which is how this property went unpinned in the first place.

---

## 6. SF-5 — the refusal row now records the real role

**The defect.** `RecordPaymentActionRefusal` wrote
`actorRole: $actor->isAuthenticated() ? 'authenticated_actor' : 'guest'` for every refusal. Its doc
block justified this as "the authorizer just established that they hold neither authorized role",
but that premise does not follow: the authorizer establishes that the actor holds neither
*authorized* role, not that they hold **no** role. A refused `admin` and a refused `customer` both
hold a real, granted, server-resolved role, and both were written to the trail as the absence of
one — on the same branch that replaced four such sentinels on the allowed paths precisely because
the trail must record the real role.

**The fix.** A private `auditRoleFor(ActorContext $actor): string` that returns `guest` for an actor
with no resolved identity, otherwise the first match walking `ActorRole::KNOWN_ROLES` in order, and
`authenticated_actor` only when the actor holds none of them.

Conventions reused rather than invented, per `AGENTS.md` §Documentation:

- **Precedence** is `ActorRole::KNOWN_ROLES`' declaration order, which that class's own doc block
  states IS precedence order, most privileged first. No role list is restated here.
- **Shape** is `DocumentVault\Policies\DocumentAccessPolicy::auditRoleFor()`'s, which the earlier
  review cited as the established form for exactly this — same guard-first structure, same
  fallback, same sentinel literals.
- **One deliberate difference**, recorded in the method's doc block: `DocumentAccessPolicy` walks
  the roles *it* recognises, because a role outside its allow-list is irrelevant to a document
  decision. Here the whole point is to record roles the authorizer does **not** accept, so the full
  canonical list is the right one — anything narrower would put real roles back under the sentinel.

**No leak on the other side.** `ActorContext::$roles` is server-resolved through `ActorRoleReader`
from non-revoked `actor_role_assignments` rows, never caller-supplied, and the same values already
reach `audit_events.actor_role` on every allowed path. The class doc block's `report()` /
§Observability claim was updated to name the role among the bounded values it may carry.

**Tests added** (beyond the `actor_role` pin now in both feature helpers):

- refused `customer` records `customer` — both routes;
- refused `admin` (a real role, deliberately not on the authorized pair) records `admin` — both
  routes, in a test named for the operational case: after merge, the likeliest real refusal is an
  existing admin who has not been granted `finance` yet, and that must be distinguishable from
  probing;
- refused actor with no roles records `authenticated_actor` — both routes, plus unit;
- a revoked grant is not a held role, so it records the sentinel — both routes;
- every entry of `ActorRole::KNOWN_ROLES` records itself — unit, data-provided, so widening the
  canonical list cannot silently leave a role behind;
- most-privileged-wins with roles supplied in the wrong order — unit;
- a role outside the canonical list falls back to the sentinel rather than being echoed — unit;
- **no resolved identity records `guest`** — unit only, deliberately: both routes carry `auth` and
  `ActorContext` is a per-request `scoped` binding resolved from the authenticated guard, so no
  controller-driven test can reach that arm. Calling the class directly is the only honest way to
  pin it.

---

## 7. SF-6 — MFA gate: deferred explicitly, middleware NOT added

Recorded in plan §7 beside the N-3 rate-limit deferral, as a **deferral with reasoning, not a
dismissal**. The reasoning of record, verified by reading the middleware rather than the reports:

1. `EnforceMfaChallenge::handle()` returns `$next($request)` untouched for any actor whose
   `mfaState !== ActorContext::MFA_STATE_ENROLLED` (`app/Http/Middleware/EnforceMfaChallenge.php:77-79`).
   It is a **no-op for a non-enrolled actor** and never blocks anyone who has not opted in to MFA.
   So adding it would close no part of the authorization bypass this hotfix exists to fix — the role
   gate is what does that. It would add only "an already-MFA-enrolled actor must prove their second
   factor this session."
2. That is still worth having, by parity with the sibling `/admin/finance/exports` route, which is
   why this is a tracked follow-up. The entry explicitly says not to read it as "not needed."
3. It is deferred from this branch because it changes session/login behaviour on two routes, needs
   its own tests and its own human review, and this branch is an urgent authorization fix whose
   blast radius should stay minimal.

Owner named: whoever picks up the N-3 rate limit, since both are the same middleware-array change
on the same two routes.

Independently confirmed while writing this: `EnforceMfaChallenge` is attached in exactly two places
(`AdminPanelProvider.php:162` and inline at `routes/web.php:282`), and neither payment route was
modified by this round.

---

## 8. SF-7 — the grant step now lives where operators read

`docs/operations/ci-cd-and-release.md` gains **§5.1 Release-specific manual steps**, immediately
after the deployment sequence, with an entry for this hotfix recording: what changes, why a manual
step exists at all (no seeder grants any role, so both endpoints refuse everyone including existing
admins until an operator acts), who runs it (the deploy operator, with the release approver naming
the accounts, at the same sign-off bar as any authorization change), the exact command, that either
role unblocks both flows and **plain `admin` does not**, and that both flows stay dark until it
happens. §8's deployment checks gains one line requiring confirmation that §5.1's steps were
executed — by having the intended operator complete the flow, not by assuming the grant landed.

The rationale and the authority basis for the role pair are **linked**, not restated: the entry
points at plan §6.1. `docs/operations/deployment.md` was deliberately not edited — its §6 already
names `ci-cd-and-release.md` as the canonical process, and duplicating the step would create
exactly the rival hand-maintained copy `AGENTS.md` §Documentation forbids.

---

## 9. SF-8 and SF-9 — corrections to the task-scoped review

Appended, never rewritten: these are review artifacts, and the point is that nobody cites the
defective sentences later.

**SF-8** (two places — the §7 conformance table row for §3.5, and the §10 "checked and clean"
bullet). Both claimed *"zero `actorRole: 'authenticated_actor'` call sites remain in
`app/Platform/Payment/`"*. Each now carries a dated correction giving the **final** state after this
round: the sentinel is no longer written unconditionally; `RecordPaymentActionRefusal::auditRoleFor()`
records the refused actor's real role and falls back to the sentinel only when they hold none, or
`guest` with no resolved identity. No literal `actorRole: 'authenticated_actor'` argument exists
anywhere in the module; the string survives as one private class constant plus six explanatory
comments. Readers are pointed at `ROLE_AUTHENTICATED_ACTOR` and `auditRoleFor()` rather than at the
stale grep.

**SF-9** (three places — §2's "attached only in", SF-5's "registered only in", and SF-5's prescribed
replacement text). All three said `EnforceMfaChallenge` was attached in one place. It is attached in
two: `AdminPanelProvider.php:162` and inline at `routes/web.php:282`. Each now carries a dated
correction stating the true count, noting that the finding's *conclusion* (no MFA on these two
routes) is unaffected and if anything stronger — an adjacent standalone route carrying the
middleware is what makes the omission conspicuous rather than uniform — and that the implementers'
shipped two-place wording, not the dictated text, is the wording of record.

---

## 10. Verification executed

Every command below was run in this worktree and its output observed. Nothing is reported as PASS
that was not executed.

| # | Check | Command | Result |
| --- | --- | --- | --- |
| 1 | AC9 payout-vocabulary guard | `php vendor/bin/phpunit tests/Feature/FinancialLedger/NoAutomatedPayoutPathTest.php` | **PASS** — `OK (10 tests, 42 assertions)` |
| 2 | Payment module suite | `php vendor/bin/phpunit tests/Feature/Payment/ tests/Unit/Platform/Payment/` | **PASS** — `OK (244 tests, 1091 assertions)` (was 227/1028 at `51d6a85`) |
| 3 | Mutation tests | see §11 | **PASS both directions**, both mutations |
| 4 | Style | `php vendor/bin/pint --test app/Platform/Payment tests/Feature/Payment tests/Unit/Platform/Payment` | **PASS** — `{"tool":"pint","result":"passed"}` |
| 5 | Static analysis | `php -d memory_limit=512M vendor/bin/phpstan analyse app/Platform/Payment tests/Feature/Payment tests/Unit/Platform/Payment` | **PASS** — `[OK] No errors` (79 files) |
| 6 | Doc gates | `bash ci/verify-docs.sh` | **PASS** — `RESULT: ALL DOC GATES PASS` (13 gates) |

Check 1 is the one that matters for the doc-block edits in this round: no payout-vocabulary token
was reintroduced into `app/Platform/Payment/`. The spaced-prose convention was preserved in every
sentence added to `RecordPaymentActionRefusal`; no prose was converted back into a class name.

Test count moved 227 → 244 (+17) and assertions 1028 → 1091 (+63): 2 new feature tests (one per
route), 13 new unit tests including the 8-case `KNOWN_ROLES` provider, and the `actor_ref` /
`actor_role` assertions added to both feature refusal helpers.

---

## 11. Mutation tests — both directions, both mutations

### 11.1 SF-4 — `actorRef: $actor->identityReference` → `actorRef: null`

**Mutated:** `Tests: 244, Assertions: 1001, Failures: 13.` The 13 killers:

- `RecordPaymentReversalRouteTest`: `test_an_authenticated_actor_with_no_role_is_refused_and_writes_nothing`,
  `test_a_real_but_unauthorized_role_is_refused`,
  `test_a_refused_admin_is_recorded_under_their_real_role_not_the_sentinel`,
  `test_a_revoked_role_grant_no_longer_authorizes`,
  `test_an_unauthorized_actor_gets_403_not_a_validation_error`
- `VerifyManualPaymentRouteTest`: the same five names, plus
  `test_an_unknown_verification_id_is_403_not_404_for_an_unauthorized_actor`
- `RecordPaymentActionRefusalTest`: `test_the_refusal_row_carries_the_refused_actors_reference`,
  `test_a_string_identity_reference_is_recorded_verbatim`

Representative diff: `Failed asserting that two strings are identical. -'1' +''`.

**Restored:** `OK (244 tests, 1091 assertions)`.

This is the mutation that was live before this round: at `51d6a85` the same edit left all 227 tests
green.

### 11.2 SF-5 — `auditRoleFor($actor)` → `$actor->isAuthenticated() ? ROLE_AUTHENTICATED_ACTOR : ROLE_GUEST`

The exact pre-fix expression restored, i.e. the mutation is "undo SF-5".

**Mutated:** `Tests: 244, Assertions: 1067, Failures: 13.` The 13 killers:

- `RecordPaymentReversalRouteTest`: `test_a_real_but_unauthorized_role_is_refused`,
  `test_a_refused_admin_is_recorded_under_their_real_role_not_the_sentinel`
- `VerifyManualPaymentRouteTest`: the same two
- `RecordPaymentActionRefusalTest`:
  `test_a_refused_actor_holding_a_real_role_is_recorded_under_that_role` for all eight
  `KNOWN_ROLES` data cases, plus
  `test_the_most_privileged_held_role_wins_regardless_of_input_order`

Note what correctly did **not** fail: the no-role, revoked-grant and guest cases, which expect the
sentinel under both versions. That asymmetry is the evidence the new tests pin the *change* rather
than merely the code's current shape.

**Restored:** `OK (244 tests, 1091 assertions)`.

### 11.3 Residue

`git status --porcelain` after both cycles shows only the intended edits. The backup used to restore
the mutated file lives in the session scratchpad, not in the worktree. No mutation artefact remains.

---

## 12. NOT TESTED / BLOCKED — read this before approving

1. **PostgreSQL — NOT TESTED for this round.** `phpunit.xml` pins `DB_CONNECTION=sqlite`/`:memory:`,
   so every run in §10 and §11 was SQLite. By instruction, no PostgreSQL container was started here;
   the lane driver owns that re-run. The PostgreSQL 18.4 result recorded in the fix-round report is
   for `51d6a85`'s content and predates the tests added here. What a PostgreSQL run should confirm
   for this round specifically: that `audit_events.actor_role` accepts the real role values now
   written on the refusal path (previously only the two sentinels reached that column on this path),
   and that `actor_ref` comparisons behave identically under the real column type.
2. **Full test suite — NOT TESTED here**, by instruction; the lane driver owns it. The full-suite
   figures quoted in §4 are the lane driver's observed output at `51d6a85`'s content, reported
   second-hand and labelled as such in the report they were added to. This round changed one
   production file (`RecordPaymentActionRefusal`), two existing test files, and added one test file;
   nothing outside `app/Platform/Payment/` and `tests/**/Payment/` was touched in code.
3. **No live HTTP exercise.** No login flow exists in this repository. Standing gap, unchanged.
4. **The two pre-existing CASCADE errors in other lanes' tests are untouched**, deliberately — out
   of scope, and not this branch's to fix.
5. **The refusal row is still not monitored by anything.** This round makes the row *useful*
   (it now names who was refused and what role they held); no alert, dashboard, or metric consumes
   `PAYMENT_ADMIN_ACTION_DENIED` yet, and none was added.
6. **Deferrals carried forward, unchanged:** the N-3 rate limit, the SF-6 MFA gate (§7 above), the
   D2 `actorRole` passthrough on the two write APIs, and the four stale `FinancialLedger` authorizer
   doc blocks.

---

## 13. Deliberately not done

- **No `EnforceMfaChallenge` added** to either route — SF-6 is a documented deferral, per the
  ruling.
- **No rate limiting / throttle middleware** — tracked separately.
- **No edit to the four `FinancialLedger` authorizer doc blocks** — another lane.
- **No edit to `tests/Feature/FinancialLedger/NoAutomatedPayoutPathTest.php`** — another lane's
  guard; it was executed, not modified.
- **No fix for the pre-existing CASCADE test errors** — another lane.
- **None of the nine NITs**, including NIT-5's `1030` → `1028` transcription error in the fix-round
  report's verification table, which was explicitly out of this round's scope.
- **Nothing committed or pushed.** The tree is left dirty for human review, as instructed.
