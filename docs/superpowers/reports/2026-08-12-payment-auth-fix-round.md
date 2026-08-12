# Payment controller authorization hotfix — fix round report

**Date:** 2026-08-12
**Branch:** `fix/payment-controller-authorization`
**Worktree:** `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix`
**Base:** `8be7e49` plus the pre-existing uncommitted doc-comment rewording, which was left in place
**State:** ALL WORK UNCOMMITTED, nothing pushed, as instructed
**Inputs:** `docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md`, `docs/superpowers/reports/2026-08-12-payment-auth-task-scoped-review.md` (0 BLOCKER, 6 SHOULD-FIX, 6 NIT)

One real code change (SF-6), one hardening (N-5), the rest documentation and test accuracy.
Everything asked for is done; N-3 was out of scope by instruction and is untouched.

---

## 1. Findings — disposition

| Finding | Status | Where |
| --- | --- | --- |
| SF-1 | Done — docs only | `docs/security/rbac-matrix.md` |
| SF-2 | Done — docs only | `.kiro/specs/platform-payment-adapter/tasks.md:54` |
| SF-3 | Done — docs only | `FinanceOrRestrictedAdminPaymentAuthorizer.php` doc block + implementation report §9.1 |
| SF-4 | Done — docs only, NO code change (per the human ruling) | plan D1 + §6.1, `rbac-matrix.md`, authorizer doc block, exception doc block |
| SF-5 | Done — docs only | both controllers' doc blocks + plan §1 |
| SF-6 | Done — REAL code change | new `RecordPaymentActionRefusal`, new `PaymentAuditActions::ADMIN_ACTION_DENIED`, both controllers, both route test files |
| N-1 | Done | reversal route test, reversal controller comment, implementation report §9.3 |
| N-2 | Done | authorizer unit test |
| N-3 | **NOT DONE — out of scope by instruction.** No throttle middleware added; recorded explicitly in plan §7 as deferred-not-waived | — |
| N-4 | Done | verification route test |
| N-5 | Done — hardening + unit tests | authorizer + unit test |
| N-6 | Done | implementation report §8 item 1 |

---

## 2. SF-6 — the one real code change

### 2.1 What was wrong

Both controllers caught the refusal non-capturingly and aborted:

```php
} catch (PaymentActionNotAuthorisedException) {
    abort(403);
}
```

No audit row, no log line, no metric. Combined with the absence of a rate limit on either route
(N-3), probing the two highest-value admin endpoints in the application was unlimited AND
invisible.

### 2.2 What was built

**`app/Platform/Payment/PaymentAuditActions.php`** — one new constant,
`ADMIN_ACTION_DENIED = 'PAYMENT_ADMIN_ACTION_DENIED'`, added to the canonical action list and
nowhere else.

**`app/Platform/Payment/RecordPaymentActionRefusal.php`** (new) — writes exactly one
`AuditOutcome::Denied` row per refusal.

**Both controllers** — call it inside the `catch`, before `abort(403)`.

Each design constraint from the brief, and how it was met:

1. **Precedent.** Follows `DocumentVault\Actions\IssueSignedUrl` (writes `Denied` on every policy
   refusal) and `GuardPaymentSession` (`GUARD_DENIED` with `AuditOutcome::Denied`), rather than
   the ledger module's Actions, which write nothing on refusal.
2. **A single dedicated constant, in `PaymentAuditActions` only.** Not a reuse of
   `REFUND`/`CHARGEBACK`/`MANUAL_VERIFICATION`. Two reasons, both in the constant's doc block:
   those three mean "this money movement happened" and an operator counting refunds must not have
   to filter refusals out of the same name; and in the reversal controller authorization
   deliberately runs BEFORE the `match` that decides refund vs chargeback, so at refusal time
   there is no correct choice between `REFUND` and `CHARGEBACK` to be made.
   **That ordering was not changed** — the authorize-first shape the reviewer praised is intact,
   and the audit design was made to fit it rather than the reverse.
3. **Not added to `SensitiveActions`.** A fixed server-side reason string is supplied explicitly
   instead: `'Refused: the actor holds no finance or restricted-admin authority for this payment
   action.'` The caller's request body is never read — authorization runs before validation, so
   every field the caller sent is unvalidated free text at that point.
4. **No restricted data, no request payload, no metadata.** `metadata: []`; `MetadataAllowlist`
   gains no key. The subject is the ACTION that was refused (`payment_admin_action` /
   the controller's own server-side `REASON` constant), never the caller-chosen
   `{paymentVerification}` route segment — writing that would put an unbounded attacker-supplied
   value in the bounded `audit_events.subject_id` column, which is exactly the `QueryException`
   that `IssueSignedUrl` documents refusing for a malformed id, and would re-open in the audit
   trail the existence question the ordering closes on the response. The only caller-derived value
   written is the server-resolved actor reference, which is already in the trail for the allowed
   path.
5. **`actorRole`.** `$actor->isAuthenticated() ? 'authenticated_actor' : 'guest'` — a literal,
   copying `Http\Middleware\RequireRecentAuthentication:154` exactly. The sentinel is semantically
   right here (the authorizer just established that no role applies) and `ActorRole` deliberately
   does not declare it as a constant, because a grantable role that the policy layer reads as the
   absence of a role would be a privilege-escalation-shaped inconsistency. The choice is
   documented in the class doc block, including the note that this is now the ONE place in the
   module the sentinel is still written — the allowed paths were changed by this same hotfix to
   record the real approved role.
6. **The audit write cannot turn a 403 into a 500.** Ordering: it runs inside the `catch`, before
   `abort(403)`, so the row is never lost to a later failure on the response path. But the write
   is best-effort — a `Throwable` from it is passed to `report()` and swallowed. Reasoning, in the
   class doc block: (a) a refusal mutates nothing, so unlike `Audit::wrap()`'s mutation+audit pair
   there is no half-done state for an audit failure to protect, and rolling the refusal back is
   not a coherent option; (b) letting it propagate would turn every refusal into a 500 during an
   audit outage, which is both a worse answer for the caller and a change in the flat-403 property
   the existence-oracle defence depends on — a monitoring improvement must not become a way to
   move the authorization surface. `report()` keeps the failure from being silent, and the
   exception carries only this class's own parameters (action name, closed-list source, the fixed
   reason, the server-resolved actor reference), so reporting it puts no restricted data anywhere.

**One shared writer rather than a copy in each controller**, so the two endpoints cannot drift
into leaving different evidence — the value of the row is that refusals across both are countable
with one query.

### 2.3 Test changes

The old denial assertions were `AuditEvent::query()->where('action', <mutation action>)->count()
=== 0` plus `ReauthenticationEvent::query()->count() === 0`. Those froze "a refusal is invisible"
into the suite. Each file now has one shared helper, applied to every denial test:

- `RecordPaymentReversalRouteTest::assertTheRefusalWasAuditedExactlyOnce()`
- `VerifyManualPaymentRouteTest::assertTheRefusalWasAuditedExactly(int $expected, ?string $unknownId)`

Both assert: **exactly** the expected number of `ADMIN_ACTION_DENIED` rows (`sole()` in the
reversal file, an exact `assertCount` in the verification file, which needs 2 for the
existence-oracle test's two refusals); each row's `outcome` is `Denied`, `subject_type` is
`payment_admin_action`, `subject_id` is the right endpoint; the reason is present, is not the
caller's text, and `metadata` is `[]`; **zero** rows anywhere carry `AuditOutcome::Allowed`; and
still zero `payment_reversals` / zero decided `payment_verifications` (`whereNotNull('decided_at')`)
/ zero `ReauthenticationEvent` rows.

Three properties keep these from going vacuous, and are documented on the helpers:

- the count is EXACT, so both deleting the audit write and double-writing it fail. `>= 1` would
  catch neither.
- the `Allowed` count is asserted separately, so the refusal path cannot start minting
  `satisfied`/`Allowed` rows and still pass by virtue of having produced a `Denied` one too.
- each row's fields are pinned, so a write recording the wrong action, the wrong subject, or the
  caller's payload fails rather than silently counting as "audited".

Also added: `test_a_failed_refusal_audit_still_returns_403_and_never_500` (drops `audit_events`,
asserts the refusal is still a 403 and still writes nothing downstream), and an assertion that the
router's 404 for an out-of-constraint `{reversalType}` mints no payment audit row.

---

## 3. SF-4 — the ruling, recorded in four places

The human ruling taken as given: **same roles for both routes (`finance` / `restricted_admin`), no
code change.** Reasoning of record, written into each artefact: both actions are fundamentally
"did money move" attestations at the same trust level — recording a reversal and verifying that a
payment was received are the same class of judgement in opposite directions.

Made explicit, not implied, in:

1. **Plan D1** — a new "Ruling, 12 Aug 2026" paragraph after the Roles paragraph, which also names
   the two consequences accepted deliberately: a plain `admin` (whom the nearest other row,
   "Quote/open payment", marks as authorized) cannot decide a manual verification, and `finance`
   gets a decision on that path where that row limits finance to read/review.
2. **`FinanceOrRestrictedAdminPaymentAuthorizer`'s class doc block** — states the ruling for the
   verification route and why, rather than merely citing the Payout/refund row for it.
3. **`docs/security/rbac-matrix.md`** — the row is now
   `| Payout/refund, incl. manual payment verification | … |`, with a paragraph below recording
   the ruling and its date. The label keeps its `Payout/refund` prefix deliberately, so the four
   existing citations of that row name (three in `app/Platform/FinancialLedger/`, one in this
   module's exception) still resolve.
4. **Plan §6.1** — the deployment note now carries a per-flow table naming which roles must be
   granted for the verification flow specifically, including the explicit warning that granting
   plain `admin` will not unblock it and that this is deliberate.

`PaymentActionNotAuthorisedException`'s doc block was updated to cite the row under its new name.

---

## 4. SF-5 — severity, corrected in the direction of worse

Both controller doc blocks and plan §1 said the pre-fix exposure required a user "who had enrolled
MFA". False. Re-verified independently in this worktree before writing the correction:

- `EnforceMfaChallenge` is attached in exactly two places — `app/Providers/Filament/AdminPanelProvider.php:162`
  (the Filament panel's own middleware array) and inline on the standalone `/admin/finance/exports`
  route (`routes/web.php:282`). Confirmed by grepping `app/`, `routes/`, `bootstrap/`, `config/`:
  every other hit is a doc comment or the `MfaChallenge` page itself.
- Both payment routes are standalone `Route::post` declarations (`routes/web.php:342-344` and
  `371-374`) whose middleware arrays are only `['web', 'auth', RequireRecentAuthentication::class.':…']`.
  Neither is inside any group carrying `EnforceMfaChallenge`.
- `config/reauthentication.php:61` — `'freshness_seconds' => (int) env('REAUTHENTICATION_FRESHNESS_SECONDS', 900)`.

True pre-fix precondition: **authenticated + logged in within the last 15 minutes. No MFA at all.**
All three places now say so, and all three state that the adjacent finance-export route DOES carry
`EnforceMfaChallenge`, which makes the omission conspicuous rather than merely uniform, plus an
explicit "do not credit this path with a compensating control it does not have."

**Correction, 12 Aug 2026 (whole-branch review finding SF-1) — original text above kept unedited.**
"All three places" was wrong: there were **four**. Plan §6 line 120 carried the same
"MFA-enrolled user" claim in the impact section and was not updated by this round. It was corrected
in the final fix round; a repo-wide grep now finds no surviving claim that these two routes were
ever gated by MFA.

---

## 5. Remaining findings, briefly

- **SF-1.** One sentence added after `rbac-matrix.md`'s "a role **and** a scope grant" line,
  recording the narrow exception (`Payment\FinanceOrRestrictedAdminPaymentAuthorizer` is
  role-only), its basis (neither payment table has a column in the `scope_assignments.entity_id`
  value space; the one candidate, `reference`, is caller-supplied free text an attacker could
  forge), and a pointer to the class doc block. The general rule is restated as unchanged and
  still governing every record that has a scopeable key — **not weakened**.
- **SF-2.** One clause appended to `.kiro/specs/platform-payment-adapter/tasks.md:54` naming
  `Payment\Contracts\PaymentActionAuthorizer` and the `finance` / `restricted_admin` requirement,
  pointing at the hotfix plan. No role list beyond the two names.
- **SF-3.** The authorizer's "Two stale claims" paragraph is now "One stale claim" — the
  `FinancialLedger` clause (still true) is kept, the plan §6 clause is dropped, and a parenthetical
  records that `f548b53` corrected the plan and that it is now safe to follow. Implementation
  report §9.1's closing sentence now records the correction as **already landed** rather than
  asking for one.
- **N-1.** `->where('reversalType', …)` → `->whereIn('reversalType', ['refund', 'chargeback'])` in
  the route test comment, the reversal controller's own `match` comment (same wrong phrasing, not
  listed in the review), and implementation report §9.3.
  **Plan D3 did NOT carry this phrasing** — the review says it does; grepping the plan for
  `where` and `reversalType` returns only the route path in the §1 table and an unrelated line in
  §6.1. Nothing to fix there, so nothing was changed.
- **N-2.** `assertStringNotContainsString(ActorRole::CUSTOMER, …)` replaced with an exact
  `assertSame` on the whole expected message, and the test parameterised over `unauthorizedRoles()`
  — which is what the old form could not survive, since the message literally contains "finance"
  and "restricted-admin".
- **N-4.** `test_an_unknown_verification_id_is_still_404_for_an_authorized_actor` added next to the
  unauthorized-side test, asserting the ordinary `findOrFail()` 404 survives the reordering, plus
  zero refusal rows (so the 404 is provably the lookup's, not the authorizer's in disguise).
- **N-5.** `if ($actorReference === null)` → `if ($actorReference === null || $actorReference === '')`,
  with the reasoning in a comment. `0` is deliberately still treated as a present identity: it is a
  legitimate integer key shape, and refusing on falsiness rather than emptiness is how a real actor
  gets locked out. Both directions are pinned by new unit tests
  (`test_an_empty_string_identity_reference_is_refused`, which carries a populated `roles` array so
  it must refuse at the identity check rather than fall through to the role lookup; and
  `test_a_zero_identity_reference_is_a_present_identity`).
- **N-6.** Implementation report §8 item 1 keeps its original NOT TESTED text verbatim and gains a
  "RESOLVED 12 Aug 2026" line recording the lane driver's PostgreSQL 18.4 run (218 tests, 928
  assertions, disposable container), plus the caveat that the run predates this fix round.
- **N-3.** Not done, by instruction. Recorded in plan §7 as explicitly deferred and tracked
  separately, **not waived**, with the note that refusal telemetry is not a substitute for a
  throttle.

---

## 6. Verification executed

Every command below was run in this worktree and its output observed. Nothing is reported as PASS
that was not executed.

| # | Check | Command | Result |
| --- | --- | --- | --- |
| 1 | AC9 payout-vocabulary guard | `php vendor/bin/phpunit tests/Feature/FinancialLedger/NoAutomatedPayoutPathTest.php` | **PASS** — `OK (10 tests, 42 assertions)` |
| 2 | Payment module suite | `php vendor/bin/phpunit tests/Feature/Payment/ tests/Unit/Platform/Payment/` | **PASS** — `OK (227 tests, 1030 assertions)` (was 218/928 before this round) |
| 3 | SF-6 mutation test | see §7 | **PASS both directions** — 9 tests killed by the mutation, all green on restore |
| 4 | Style | `php vendor/bin/pint --test app/Platform/Payment tests/Feature/Payment tests/Unit/Platform/Payment` | **PASS** — `{"tool":"pint","result":"passed"}` |
| 5 | Static analysis | `php -d memory_limit=512M vendor/bin/phpstan analyse app/Platform/Payment tests/Feature/Payment tests/Unit/Platform/Payment` | **PASS** — `[OK] No errors` (78 files) |
| 6 | Doc gates | `bash ci/verify-docs.sh` | **PASS** — `RESULT: ALL DOC GATES PASS` (13 gates) |

Check 1 is the one that matters for the doc-block edits: no payout-vocabulary token was
reintroduced into `app/Platform/Payment/`. The doc block in
`FinanceOrRestrictedAdminPaymentAuthorizer` explaining the spaced-prose convention was **not**
removed, and the new prose in that file follows it (the one bare class name it still carried,
`FinancialLedger\FinanceOrRestrictedAdminPayoutAuthorizer`, was reworded to "the ledger module's
payout authorizer" for consistency with the surrounding style — it did not match the regex either
way, since `\bPayout` does not match inside `AdminPayoutAuthorizer`).

---

## 7. SF-6 mutation test — both directions, exact output

**Mutation applied:** the `app(RecordPaymentActionRefusal::class)->record($actorContext, self::REASON);`
line deleted from the `catch` block of BOTH controllers (verified by
`grep -c "RecordPaymentActionRefusal::class" …` returning `0` for both files). Nothing else
touched.

### 7.1 MUTATED — `php vendor/bin/phpunit tests/Feature/Payment/RecordPaymentReversalRouteTest.php tests/Feature/Payment/VerifyManualPaymentRouteTest.php`

```
ERRORS!
Tests: 44, Assertions: 146, Errors: 4, Failures: 5.
```

Nine tests killed by the mutation — four in the reversal file, five in the verification file.

Reversal file (`sole()` finds no row, so these surface as errors):

```
2) Tests\Feature\Payment\RecordPaymentReversalRouteTest::test_a_real_but_unauthorized_role_is_refused
Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Platform\Audit\Models\AuditEvent].

/…/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:827
/…/tests/Feature/Payment/RecordPaymentReversalRouteTest.php:133
/…/tests/Feature/Payment/RecordPaymentReversalRouteTest.php:256

3) Tests\Feature\Payment\RecordPaymentReversalRouteTest::test_a_revoked_role_grant_no_longer_authorizes
Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Platform\Audit\Models\AuditEvent].

/…/tests/Feature/Payment/RecordPaymentReversalRouteTest.php:133
/…/tests/Feature/Payment/RecordPaymentReversalRouteTest.php:271

4) Tests\Feature\Payment\RecordPaymentReversalRouteTest::test_an_unauthorized_actor_gets_403_not_a_validation_error
Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Platform\Audit\Models\AuditEvent].

/…/tests/Feature/Payment/RecordPaymentReversalRouteTest.php:133
/…/tests/Feature/Payment/RecordPaymentReversalRouteTest.php:369
```

(the fourth, `test_an_authenticated_actor_with_no_role_is_refused_and_writes_nothing`, is error 1,
same `ModelNotFoundException` at `RecordPaymentReversalRouteTest.php:133` from
`RecordPaymentReversalRouteTest.php:239`.)

Verification file (exact `assertCount`, so these surface as failures):

```
There were 5 failures:

1) Tests\Feature\Payment\VerifyManualPaymentRouteTest::test_an_authenticated_actor_with_no_role_is_refused_and_writes_nothing
Failed asserting that actual size 0 matches expected size 1.

/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:147
/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:256

2) Tests\Feature\Payment\VerifyManualPaymentRouteTest::test_a_real_but_unauthorized_role_is_refused
Failed asserting that actual size 0 matches expected size 1.

/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:147
/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:275

3) Tests\Feature\Payment\VerifyManualPaymentRouteTest::test_a_revoked_role_grant_no_longer_authorizes
Failed asserting that actual size 0 matches expected size 1.

/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:147
/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:292

4) Tests\Feature\Payment\VerifyManualPaymentRouteTest::test_an_unknown_verification_id_is_403_not_404_for_an_unauthorized_actor
Failed asserting that actual size 0 matches expected size 2.

/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:147
/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:336

5) Tests\Feature\Payment\VerifyManualPaymentRouteTest::test_an_unauthorized_actor_gets_403_not_a_validation_error
Failed asserting that actual size 0 matches expected size 1.

/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:147
/…/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:472
```

Note finding 4: the existence-oracle test expected **2** and got 0, so the helper's per-refusal
counting is real and not a "was anything written" check.

### 7.2 RESTORED — same command

```
............................................                      44 / 44 (100%)

Time: 00:02.915, Memory: 79.00 MB

OK (44 tests, 237 assertions)
```

(146 assertions under the mutation vs 237 restored: the killed tests abort at the helper, before
their remaining assertions run.)

### 7.3 Second mutation, N-5

Also mutation-tested, since it is a new guard: reverting
`if ($actorReference === null || $actorReference === '')` to `if ($actorReference === null)` gives

```
1) Tests\Unit\Platform\Payment\FinanceOrRestrictedAdminPaymentAuthorizerTest::test_an_empty_string_identity_reference_is_refused
Failed asserting that exception of type "App\Platform\Payment\Exceptions\PaymentActionNotAuthorisedException" is thrown.

Tests: 23, Assertions: 24, Failures: 1.
```

Restored, and the file is green.

### 7.4 Residue

`git status --porcelain` after both mutation cycles shows only the intended edits; the two
controller backups and the authorizer backup live in the session scratchpad, not in the worktree.
No mutation artefact remains.

---

## 8. NOT TESTED / BLOCKED — read this before approving

1. **Full test suite — NOT TESTED.** Only `tests/Feature/Payment/`, `tests/Unit/Platform/Payment/`
   and `tests/Feature/FinancialLedger/NoAutomatedPayoutPathTest.php` were run, per the
   memory-pressure instruction; the lane driver owns the full run. Mitigating evidence, not proof:
   the only non-test file added is `app/Platform/Payment/RecordPaymentActionRefusal.php`, which is
   referenced from exactly the two controllers in this module, and the only shared file touched
   outside `app/Platform/Payment/` is documentation.

   **RESOLVED 12 Aug 2026 (whole-branch review finding SF-3) — original NOT TESTED text above kept
   unedited, per this repository's correction convention.** The lane driver has since run the full
   suite at `51d6a85`'s content: **1859 tests, 7201 assertions, 0 failures.** Two errors were
   observed and are **not** attributable to this branch — both are pre-existing
   `DROP TABLE ... CASCADE` SQLite incompatibilities in other lanes' tests
   (`HomePageRouteTest`, `EloquentGateRegistrySourceTest`), on files this branch does not touch and
   in code paths it does not reach. This item is closed as a gate; those two errors belong to the
   lanes that own them.

   Reported second-hand: this entry records the lane driver's observed output, not a run performed
   by the fix-round agent that wrote §8. The final fix round did not re-run the full suite either.
2. **PostgreSQL — NOT TESTED for this fix round.** `phpunit.xml` pins
   `DB_CONNECTION=sqlite`/`:memory:`, so every run above was SQLite. The PostgreSQL 18.4 run
   recorded in the implementation report (N-6) predates this round and does not cover the new
   refusal-audit assertions. Two specifics a PostgreSQL run should confirm, neither of which SQLite
   can: that `audit_events.subject_id`/`reason`/`metadata` accept the new row's shapes under the
   real column types, and that
   `test_a_failed_refusal_audit_still_returns_403_and_never_500`'s `Schema::drop('audit_events')`
   inside the test transaction behaves the same on PostgreSQL's transactional DDL as it does on
   SQLite.

   **RESOLVED 12 Aug 2026 (whole-branch review finding SF-3) — original NOT TESTED text above kept
   unedited, per this repository's correction convention.** The lane driver has since run the
   payment module suite (`tests/Feature/Payment/ tests/Unit/Platform/Payment/`) green on real
   **PostgreSQL 18.4** in a disposable container on port 55572, at `51d6a85`'s content:
   **227 tests, 1028 assertions** — the same test and assertion counts as the SQLite run of the
   same content. Both PostgreSQL-specific risks named above are therefore exercised rather than
   argued: the new refusal row's `subject_id`/`reason`/`metadata` shapes were accepted by the real
   column types, and `test_a_failed_refusal_audit_still_returns_403_and_never_500` passed under
   PostgreSQL's transactional DDL.

   Reported second-hand: this entry records the lane driver's observed output, not a run performed
   by the fix-round agent that wrote §8.

   **Still NOT TESTED on PostgreSQL:** the tests added by the *final* fix round (12 Aug 2026 —
   whole-branch findings SF-4 and SF-5, pinning the refusal row's `actor_ref` and its real
   `actor_role`) postdate the run above and have been exercised on SQLite only. The lane driver
   owns the PostgreSQL re-run for that content; see
   `2026-08-12-payment-auth-final-fix-round.md` §Verification.
3. **No live HTTP exercise.** No login flow exists in this repo, so nothing here was driven through
   a real browser session. Standing gap, unchanged.
4. **The refusal row is not yet monitored by anything.** This round makes refusals *observable*; no
   alert, dashboard, or metric consumes `PAYMENT_ADMIN_ACTION_DENIED` yet, and none was added.
   Pairing it with N-3's rate limit and a monitor is the follow-up.
5. **The D2 residual risk is still open**, unchanged by this round: `ReversalService::record()` and
   `VerifyManualPayment::verify()` still accept a caller-supplied `actorRole`.

---

## 9. Judgement calls a reviewer should look at specifically

1. **A new shared class rather than a private method duplicated in each controller.** Reasoning in
   §2.2. The cost is one more file in the module; the benefit is that the two endpoints cannot
   drift into leaving different evidence.
2. **Swallowing the audit failure.** This is the one place in the change where something is
   deliberately not fail-closed, and it deserves the scrutiny. The argument is in §2.2 item 6 and
   in the class doc block: there is no state to protect on a refusal path, and propagating would
   move the authorization surface (flat 403 → 500 under an audit outage). If a reviewer disagrees,
   the alternative is a one-line change (drop the `try`/`catch`) and one test to delete.
3. **`0` is still a present identity** (N-5). Refusing it would be refusing on falsiness rather
   than emptiness, which is how a legitimate actor gets locked out. Both directions are pinned by
   tests so the decision is visible rather than implicit.
4. **Renaming the RBAC row** rather than adding a footnote. The `Payout/refund` prefix is preserved
   so the four existing citations still resolve, but four files in two modules cite that row by
   name and only this module's two were updated to the new label — the three in
   `app/Platform/FinancialLedger/` are another lane's files and were deliberately left alone, per
   the same scoping the original hotfix used.
5. **The review's claim that plan D3 carries the `->where(…)` phrasing is wrong** (§5, N-1). Stated
   rather than silently ignored.
