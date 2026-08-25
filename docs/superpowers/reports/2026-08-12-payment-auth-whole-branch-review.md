# Whole-branch review — payment controller authorization hotfix

**Branch:** `fix/payment-controller-authorization`
**Range reviewed:** `d9fea9f..51d6a85` (5 commits: `83d6398`, `f548b53`, `c60c49c`, `8be7e49`, `51d6a85`)
**Reviewed at:** `51d6a855dd03689d3016f14301de323d951599cb`
**Date:** 12 Aug 2026
**Lens:** independent, read-only, whole-branch — the tier `AGENTS.md` §Development methodology requires before merge.
**Reviewer:** independent review agent. No file in this branch was modified except this report.

---

## 0. Verdict

**0 BLOCKER · 9 SHOULD-FIX · 9 NIT.**

**Recommended for human merge sign-off**, with the nine SHOULD-FIX items addressed or explicitly
waived by the signer first. **None of them is a security defect in the fix itself.**

The security change is correct. I attacked it from the angles the task-scoped review could not
reach — the entirely new `RecordPaymentActionRefusal`, cross-artifact coherence after four
mid-flight edits, and the shipping consequence — and found no bypass, no ordering leak, no
existence oracle, no log-injection path, and no restricted data on any new path.

Every SHOULD-FIX below falls into one of three buckets: **documentation drift that survived a
correction round** (five items), **a property the new code's value depends on that no test pins**
(one item), or **a gap the branch newly creates and does not record** (three items). Seven are
text edits. One is a few lines of test. One is a decision.

The dominant pattern is worth naming up front, because prior rounds already caught it twice and it
recurred: **corrections on this branch were applied to one instance and not its twin.** SF-1
(plan §1 corrected, plan §6 not), SF-2 (`tasks.md` manual-verification entry corrected, reversal
entry not), and SF-8 (the fix round falsified a claim in the review that authorised it, and nobody
revisited the review) are three fresh instances of exactly that shape.

`AGENTS.md` §Infrastructure-agent execution requires human review before authorization and
financial changes. This branch is both. This report is an input to that review, not a substitute
for it.

---

## 1. What I executed (and what I did not)

The lane driver's verification was not re-run wholesale — per the review brief, effort went into
reading code. Four checks were executed here anyway, because each settles a question this review
raised rather than repeating one already answered.

| # | Check | Command | Result |
| --- | --- | --- | --- |
| 1 | Working tree clean at `51d6a85`, no mutation-test residue | `git status --porcelain --untracked-files=all`; `git diff HEAD` | **PASS** — both empty; `HEAD` is `51d6a85` |
| 2 | Payment module suite at branch tip | `php vendor/bin/phpunit tests/Feature/Payment/ tests/Unit/Platform/Payment/` | **PASS** — `OK (227 tests, 1028 assertions)`, PHPUnit 12.5.31 / PHP 8.5.9, SQLite. See NIT-5: the committed report says 1030. |
| 3 | AC9 payout-vocabulary guard | `php vendor/bin/phpunit tests/Feature/FinancialLedger/NoAutomatedPayoutPathTest.php` | **PASS** — `OK (10 tests, 42 assertions)` |
| 4 | Repository doc gates | `bash ci/verify-docs.sh` | **PASS** — `RESULT: ALL DOC GATES PASS` (13 gates) |

**NOT TESTED by this review**, stated explicitly per `AGENTS.md` §Infrastructure-agent execution:

- **PostgreSQL.** Every run above was SQLite (`phpunit.xml` pins `DB_CONNECTION=sqlite`,
  `:memory:`). The lane driver reports a green PostgreSQL 18.4 run of the payment module at this
  tip; I did not reproduce it, and **nothing on the branch records it** — see SF-3.
- **Full suite.** Not run here. The lane driver reports 1859 tests, 0 failures.
- **Mutation tests.** Not re-run. The fix round's transcript (fix-round report §7) was read and
  its claimed kill set checked against the test files for consistency; it is consistent. The one
  mutation nobody ran is the one SF-4 is about.
- **Live HTTP.** No login flow exists in this repository; nothing was driven through a browser.

---

## 2. What I verified positively — the fix itself is sound

Recorded because a review that lists only findings misrepresents the branch.

**The authorization is real, fail-closed, and correctly ordered.**
`FinanceOrRestrictedAdminPaymentAuthorizer::authorize()`
(`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/FinanceOrRestrictedAdminPaymentAuthorizer.php:143-168`)
refuses a null identity, refuses `''`, admits `0`, and returns a role only on a real
`in_array($role, $actor->roles, true)` hit against a two-entry allow-list taken from `ActorRole`
rather than redeclared. Both controllers call it as their **first** statement, before
`ReauthenticationService::satisfy()`, before `$request->validate()`, and — in
`VerifyManualPaymentController` — before `PaymentVerification::findOrFail()`
(`.../Http/Controllers/RecordPaymentReversalController.php:101-113`;
`.../Http/Controllers/VerifyManualPaymentController.php:102-117`). The existence-oracle defence
holds on both the response **and** the audit row.

**No second entrance.** A repo-wide grep finds exactly one production caller of each write API:
`ReversalService::record()` from `RecordPaymentReversalController.php:154`, and
`VerifyManualPayment::verify()` from `VerifyManualPaymentController.php:142`. Nothing else in
`app/` reaches either. The D2 residual (caller-supplied `actorRole` on the write APIs) is
therefore still unexploitable, exactly as documented.

**The binding cannot silently disappear.** `PaymentAuthorizerBindingTest` pins provider
registration in `bootstrap/providers.php`, the interface→implementation resolution, and the
transient (non-singleton) lifetime. An unbound interface is the failure mode that would make a
controller stop checking anything; it is covered.

**The refusal row carries nothing the caller supplied.** `RecordPaymentActionRefusal::record()`
(`.../app/Platform/Payment/RecordPaymentActionRefusal.php:121-136`) passes a fixed class constant
as `reason`, the calling controller's own server-side `REASON` constant as `subject->id`, a
literal `'payment_admin_action'` as `subject->type`, and no metadata. `Audit::record()`
(`app/Platform/Audit/Audit.php:93-125`) writes those verbatim. Every value is bounded and
server-chosen, so the `audit_events.subject_id` over-length `QueryException` the class doc block
worries about cannot occur, and the row is not an existence oracle over verification ids —
independently pinned by
`VerifyManualPaymentRouteTest::test_an_unknown_verification_id_is_403_not_404_for_an_unauthorized_actor`,
which asserts the fabricated UUID appears in neither `subject_id` nor `reason`.
`PAYMENT_ADMIN_ACTION_DENIED` is correctly absent from `app/Platform/Audit/SensitiveActions.php`,
so `Audit::record()`'s mandatory-reason check does not apply and the supplied fixed reason is a
courtesy to the human reader, as documented.

**Nothing restricted can reach the error tracker.** `report($failure)` is reached only from the
`catch` around that same call. On PostgreSQL a failing insert raises a `QueryException` whose
message interpolates the bindings — and every binding on this path is a fixed server-side string
or the server-resolved actor reference. The class doc block's claim at lines 86-91 is true as
written. `AGENTS.md` §Observability is satisfied.

**The best-effort swallow does not move the authorization surface.** The write runs inside the
`catch`, before `abort(403)`, so the row cannot be lost to a later failure; and a failure of the
write cannot convert the flat 403 into a 500. Pinned by
`test_a_failed_refusal_audit_still_returns_403_and_never_500` in both route tests. (One narrow gap
in the absolute form of that claim — NIT-2.)

**The denial assertions did not go vacuous when they were narrowed.** Called out specifically in
the review brief. Both helpers still pin the no-domain-write and no-reauthentication-event
properties, and both are exact rather than `>= 1`:

- `RecordPaymentReversalRouteTest::assertTheRefusalWasAuditedExactlyOnce()`
  (`tests/Feature/Payment/RecordPaymentReversalRouteTest.php:129-152`) — `sole()` fails on both
  zero rows and two; `PaymentReversal::count() === 0`; `ReauthenticationEvent::count() === 0`;
  `Allowed` audit count `=== 0`; `metadata === []`.
- `VerifyManualPaymentRouteTest::assertTheRefusalWasAuditedExactly()`
  (`tests/Feature/Payment/VerifyManualPaymentRouteTest.php:141-183`) — `assertCount($expected, …)`
  is exact; `PaymentVerification::whereNotNull('decided_at')->count() === 0`;
  `ReauthenticationEvent::count() === 0`; `Allowed` count `=== 0`.

Neither is self-satisfying: the assertion that the refusal produced a `Denied` row is separate
from, and cannot be satisfied by, the assertion that it produced no `Allowed` one. The one
property they do **not** pin is SF-4.

**The `RequireRecentAuthentication` ordering fix is pinned, not incidental.** Both
`test_..._redirects_to_the_challenge_and_changes_nothing` tests grant the role deliberately, so
the redirect cannot be the authorization refusal wearing the middleware's clothes; and
`test_an_unknown_verification_id_is_still_404_for_an_authorized_actor` pins that moving
`authorize()` above the lookup did not quietly break the legitimate not-found path. Both are the
right shape.

**The corrected MFA statement is itself true** — the review brief's item 3. Verified against
source rather than against the reports, clause by clause:

- `EnforceMfaChallenge` is attached in exactly **two** places —
  `app/Providers/Filament/AdminPanelProvider.php:162` (the panel's own middleware array) and
  inline at `routes/web.php:282` on `/admin/finance/exports`. Confirmed by repo-wide grep.
- Both payment routes are standalone `Route::post` declarations (`routes/web.php:342-344`,
  `routes/web.php:371-374`) whose middleware arrays are exactly
  `['web', 'auth', RequireRecentAuthentication::class.':<reason>,filament.admin.pages.mfa-challenge']`
  — neither is in any group carrying `EnforceMfaChallenge`, and `bootstrap/app.php` appends only
  `AssignCorrelationId` to the `web` group.
- `RequireRecentAuthentication::isFresh()`
  (`app/Http/Middleware/RequireRecentAuthentication.php:172-181`) reads only
  `ActorContext::$lastAuthenticatedAt` against `config('reauthentication.freshness_seconds')`. It
  never consults MFA state; the file contains no `mfaState` reference.

So "no MFA was involved on these routes" is accurate wherever the **corrected** wording appears:
plan §1 lines 21-27, both controller doc blocks, and the fix-round report. Two artifacts still
carry pre-correction or wrong-premise versions — SF-1 and SF-9.

**Working tree is clean and free of mutation residue.** `git status --porcelain
--untracked-files=all` is empty and `git diff HEAD` is empty at `51d6a85`. Both mutation rounds
were fully reverted. (A historical inconsistency about the tree state at `8be7e49` — NIT-8 —
does not affect this.)

---

## 3. BLOCKER

**None.** Stated plainly rather than padded: I found nothing on this branch that should stop the
merge on security grounds. The bypass is genuinely closed, it is closed fail-closed, and the fix
round's new code introduces no bypass, oracle, injection path, or restricted-data leak.

---

## 4. SHOULD-FIX

### SF-1 — the plan still carries the pre-correction MFA claim in the section a signer reads

**File:** `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md:120`

> `- **The bypass being closed is exploitable today, not theoretical.** Any authenticated, MFA-enrolled user can currently record a reversal or approve a manual payment.`

**What is wrong.** This is verbatim the claim SF-5 of the task-scoped review corrected. Line 21 of
the same document retracts it explicitly: *"This paragraph originally said 'any authenticated user
**who has enrolled MFA** and satisfies the recency window'. That credited a compensating control
these two routes never had, and it understated the severity."* The correction was applied to §1
(lines 21-27) and to both controller doc blocks. Line 120 was missed, leaving the plan
self-contradicting a hundred lines apart.

It also falsifies a claim in the fix-round report
(`docs/superpowers/reports/2026-08-12-payment-auth-fix-round.md:186`, *"All three places now say
so"*) — there were four places, and the fourth was not updated.

**Why it matters.** It **understates the severity** of the vulnerability being fixed: it tells the
signer that MFA enrolment was a precondition of the bypass. It was not. This is the sentence a
human reads to size what shipped broken, and it is the one place on the branch where the record is
more flattering than the truth. Severity language on a live authorization bypass is exactly the
thing that must not drift.

**Fix.** Replace *"Any authenticated, MFA-enrolled user"* with *"Any authenticated user whose last
login falls inside the 900 s freshness window — no MFA gate existed on these routes"*, matching §1
lines 21-27.

---

### SF-2 — the reversal route's spec entry never received the "Superseded" note its twin got

**File:** `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/.kiro/specs/platform-payment-adapter/tasks.md:60`
(Task 6, Wave 1d Append-Correction, item 2)

> `2. **The re-authentication gate is real for reversals too**, wired at `POST /admin/payments/reversals/{reversalType}` behind `App\Http\Middleware\RequireRecentAuthentication` … following that route's exact precedent.`

**What is wrong.** The branch's only change to `tasks.md` is a one-line edit at line 54 appending
**"Superseded 12 Aug 2026: the route is no longer gated by re-authentication alone — it now also
requires `Payment\Contracts\PaymentActionAuthorizer` to approve a real `finance` or
`restricted_admin` grant before anything else runs"** to the *manual verification* entry. The
reversal entry — the sibling item for the other route this branch changed — is untouched and still
describes that endpoint's authorization model as it was before the fix.

The task-scoped review's SF-2 named only line 54, so the gap was never in the fix round's scope;
the fix-round report correctly describes what it did. The obligation is nonetheless not
discharged.

**Why it matters.** `AGENTS.md` §Development methodology names Kiro specs the *"'what to build'
authority — acceptance criteria, traceability, durable per-spec progress"*, and §Documentation
requires the spec be updated when behaviour changes. The authority document now describes one of
the two hotfixed routes incorrectly. The asymmetry is the tell: the author judged the note
necessary for one route and did not apply it to the identical case.

**Fix.** Append the same dated `**Superseded 12 Aug 2026:**` clause to item 2 of the Task 6
correction, naming `POST /admin/payments/reversals/{reversalType}`, the authorizer, and the same
role pair.

---

### SF-3 — the committed verification record both over-claims and under-claims, in opposite directions

**Files:**
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/docs/superpowers/reports/2026-08-12-payment-auth-implementation.md:201`
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/docs/superpowers/reports/2026-08-12-payment-auth-fix-round.md:378-393`

**What is wrong — two halves of one problem.**

*Over-claim.* Implementation report line 201 reads:

> `**RESOLVED 12 Aug 2026 (review nit N-6) …** The lane driver has since run the payment module suite green on real PostgreSQL 18.4 …: 218 tests, 928 assertions. **The plan §5 requirement is met; this line is no longer an open gate.**`

Plan §5 (line 108) requires *"Real PostgreSQL 18 run before done, since this changes authorization
and audit behaviour."* That 218-test run predates the SF-6 fix round, which added an entirely new
audit write. The bolded *"requirement is met / no longer an open gate"* is therefore false for
`51d6a85`, and it is the sentence a skimmer takes. The trailing caveat in the same paragraph does
not undo it.

*Under-claim.* The fix-round report's §8, headed **"NOT TESTED / BLOCKED — read this before
approving"**, correctly records that PostgreSQL and the full suite were not run for that round,
and names the two PostgreSQL-specific risks precisely (`audit_events` column types accepting the
new row shape; `Schema::drop('audit_events')` under PostgreSQL's transactional DDL). Both gaps
have since been closed by the lane driver — payment module green on PostgreSQL 18.4 at this tip,
full suite 1859 tests / 0 failures — and **neither result is recorded anywhere on the branch.**

**Why it matters.** This is the merge sign-off packet, and it currently tells the signer the
opposite of the truth in both directions: that a gate is closed when it is open, and that two
risks are unverified when they have been verified. Evidence that lives only in an agent transcript
is not evidence a human can act on, and `AGENTS.md` §Testing requires test evidence for anything
claimed covered. The repository already has the right convention — implementation report §8 item 1
keeps its original NOT TESTED text verbatim and appends a dated RESOLVED line.

**Fix.** (a) Narrow line 201 to *"the §5 requirement is met for `8be7e49`; the SF-6 refusal-audit
write remains NOT TESTED on PostgreSQL — see fix-round §8.2."* (b) Append a dated RESOLVED line to
fix-round §8 items 1 and 2, quoting the observed output of the lane driver's PostgreSQL and
full-suite runs, keeping the original NOT TESTED text unedited per the convention already in use.

---

### SF-4 — no test pins the refusal row's actor reference, the one field that gives the row its purpose

**Files:**
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/tests/Feature/Payment/RecordPaymentReversalRouteTest.php:129-152`
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/tests/Feature/Payment/VerifyManualPaymentRouteTest.php:141-183`
Code under test:
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/RecordPaymentActionRefusal.php:128`

**What is wrong.** The two refusal helpers pin `outcome`, `subject_type`, `subject_id`, a non-null
`reason` with a substring exclusion, and `metadata === []`. They do not read `actor_ref` — and
neither does any other test. A grep for `actor_ref` across both route test files returns nothing;
the only `actor_role` assertions in either file (lines 319-325 and 420-426) are on **success**-path
rows.

The consequence is a live mutation the suite does not kill. Changing

```php
actorRef: $actor->identityReference,
```

to `actorRef: null` on `RecordPaymentActionRefusal.php:128` leaves all 227 payment tests green.
The same is true of the `actorRole` argument on line 129.

**Why it matters.** The entire justification for the fix round is that a refusal must be
observable — the class doc block at lines 18-23 says *"an authorization control whose refusals are
not observable cannot be monitored"* — and lines 61-63 identify the actor reference as *"the only
caller-derived value written."* A refusal row with a null actor reference is a counter, not a
monitoring signal: it records that something was refused and nothing about who. That field is
load-bearing and unpinned, on the one code path no independent reviewer had previously seen. The
fix round's own mutation test (deleting the whole `record(...)` call, 9 kills) cannot reach it,
because it removes the row entirely rather than hollowing it out.

**Fix.** Pass the acting user into both helpers and assert the reference, e.g.
`$this->assertSame((string) $user->id, (string) $denial->actor_ref);`. Pin `actor_role` in the same
place — see SF-5 for what it should be.

---

### SF-5 — the refusal row records `authenticated_actor` even when the refused actor holds a real role

**File:** `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/app/Platform/Payment/RecordPaymentActionRefusal.php:129`

> `actorRole: $actor->isAuthenticated() ? 'authenticated_actor' : 'guest',`

**What is wrong.** The class doc block at lines 107-110 justifies this as *"the audit sentinel
meaning 'no role applies', which is precisely true of a refused actor: the authorizer just
established that they hold neither authorized role."* The premise does not follow. The authorizer
established that the actor holds neither *authorized* role — not that the actor holds **no** role.
A refused `admin` and a refused `customer` both hold a real, granted, server-resolved role, and
both are written to the trail as `authenticated_actor`.

**Why it matters.** Two reasons, and the first is the branch's own argument turned around.

1. **It contradicts the principle this hotfix exists to assert.** The branch replaced exactly four
   hardcoded `actorRole: 'authenticated_actor'` sentinels (confirmed against the diff), and both
   controllers explain why at length: *"`Roles\ActorRole`'s own doc block defines that sentinel as
   meaning 'no role applies' … so the audit trail for these reversals previously recorded the
   ABSENCE of a role on every one of them"* (`RecordPaymentReversalController.php:128-133`). Both
   route tests enshrine it as `test_the_audit_trail_records_the_real_role_never_the_sentinel`. The
   newest code on the branch then writes the sentinel back, on the one path where the actor's real
   role is the most operationally interesting datum available.
2. **It costs the operator the distinction that matters most after this merge.** "A `customer`
   account is POSTing to the reversal endpoint" and "a plain `admin` tried to approve a manual
   verification and could not" call for opposite responses — one is probing, the other is very
   likely someone who needs a grant, which is by far the most probable real refusal once this
   branch ships (see §6). The row as written cannot tell them apart.

There is no leak argument on the other side. `ActorContext::$roles` is server-resolved through
`ActorRoleReader` from non-revoked `actor_role_assignments` rows; it is never caller-supplied, and
the same values are already written to `audit_events.actor_role` on every allowed path.

**Fix.** Write the refused actor's highest known role, falling back to `'authenticated_actor'`
only for an actor who genuinely holds none — and pin it in the tests alongside SF-4. If the
uniform sentinel is preferred anyway that is a defensible call, but the class doc block must then
say *"even when the actor holds a real, unauthorized role"* rather than asserting a premise that
is false for `admin`, `operator`, `case_manager`, `vendor`, `customer`, and `system`.

---

### SF-6 — the branch makes both routes privileged-role routes with no MFA gate, and does not record that gap

**Files:**
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/routes/web.php:342-344` and `:371-374`
`/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md:146-149` (§7 Out of scope)

**What is wrong.** `AGENTS.md` §Authentication and uploads: *"Use same-origin session auth for MVP
and **mandatory TOTP MFA for privileged roles**."* Before this branch these two routes required no
role at all, so the rule's applicability was arguable. After it they require `finance` or
`restricted_admin` — privileged by any reading, and by the branch's own framing. Neither route
carries `EnforceMfaChallenge`.

The comparison is one screen away in the same file. `/admin/finance/exports`
(`routes/web.php:277-285`) carries `'web', 'auth', 'throttle:financial-export',
EnforceMfaChallenge::class, RequireRecentAuthentication::class.':bulk_financial_export,…'`. The
two money-moving payment routes carry `'web', 'auth', RequireRecentAuthentication::class.':…'` and
nothing else — no MFA, no throttle.

Plan §7 lists three deferrals: the `actorRole` passthrough, the stale `FinancialLedger` doc
blocks, and the rate limit (N-3). The MFA gap is not among them.

**Why it matters.** The controller doc blocks are commendably honest about it — *"Do not credit
this path with a compensating control it does not have"* — but honesty in a doc block is not a
tracked deferral. `AGENTS.md` §Development methodology sends unaddressed findings to a ledger, and
a signer comparing these routes against the adjacent finance-export route will reasonably ask why
one privileged money route has MFA and two do not. Right now the branch has no answer on record.
This is the one gap where the branch arguably leaves the routes short of a **binding**
`AGENTS.md` rule rather than merely short of an improvement.

**Fix.** Either add `EnforceMfaChallenge::class` to both middleware arrays — a one-line change per
route, with an established in-repo precedent and the `filament.admin.pages.mfa-challenge`
destination already wired — or add a dated, explicit deferral to plan §7 beside N-3 stating that
these routes remain privileged-role routes without a TOTP gate, and naming who owns closing it.
Silence is the only option that is not acceptable.

---

### SF-7 — the post-merge grant step is absent from every document an operator reads

**Where it is recorded:** `docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md:125-143`
(§6.1, including the per-flow table added by `c60c49c`), echoed at
`docs/superpowers/reports/2026-08-12-payment-auth-fix-round.md:163-165`. That is all: a repo-wide
grep for `identity:grant-role` outside code and tests returns only those hotfix-plan hits and the
unrelated L5 identity-seam plan.

**Where it is absent:**

- `docs/operations/ci-cd-and-release.md` — §5 "Deployment sequence" and §8 "Deployment checks".
- `docs/operations/deployment.md` — §5/§6 non-prod and production deployment procedures.
- `docs/operations/runbooks/` — contains only `deploy-stg-vhost.md`.
- `docs/security/rbac-matrix.md` — **this branch edited this file** and still did not record that
  the Payout/refund row is now enforced in code and requires an explicit grant to function.

**What is verified true.** The command name and signature are correct —
`identity:grant-role {actor} {role} {--reason=}` at
`app/Console/Commands/IdentityGrantRoleCommand.php:41`, with `--reason` enforced non-blank in
`handle()`. "No seeder grants roles" is also true: `database/seeders/` holds only
`DatabaseSeeder.php`, and grep across `database/` (excluding the migration) returns no
`ActorRoleAssignment` reference.

**Why it matters.** On merge both endpoints refuse everyone, including every existing admin, until
a grant is issued — exactly as the plan predicts at line 132. The person running the deploy has no
document in their path that says why or what to run. A fail-closed authorization change is the
right design; a fail-closed change whose remedy is documented only in an SDD plan is an
operational trap.

**Mitigating, and the reason this is SHOULD-FIX rather than BLOCKER:** the blast radius is empty
today. A repo-wide grep of `resources/` and `app/` for `admin.payments.reversals.record` and
`admin.payments.manual-verifications.verify` returns no caller outside `routes/web.php` and the
tests. No screen, Blade view, Filament resource, or Livewire component can reach either endpoint,
so nothing user-visible breaks when they fail closed.

**Fix.** One line in `docs/operations/ci-cd-and-release.md` §5 or §8's checklist naming
`php artisan identity:grant-role {actor} finance --reason="…"` as a required same-window step for
this release, and one sentence after `docs/security/rbac-matrix.md:43` stating that the
Payout/refund row is now enforced in code and requires an explicit grant.

---

### SF-8 — the fix round falsified a verified claim in the review that authorised it, and the review was never revisited

**File:** `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/docs/superpowers/reports/2026-08-12-payment-auth-task-scoped-review.md:240` and `:570`

> `| **§3.5** both controllers, all four sentinel occurrences replaced | Verified: zero \`actorRole: 'authenticated_actor'\` call sites remain in \`app/Platform/Payment/\`; the only three remaining occurrences of the string are explanatory comments. |`

> `- Zero \`actorRole: 'authenticated_actor'\` call sites remain in \`app/Platform/Payment/\`.`

**What is wrong.** Both claims were true at `8be7e49` and are false at `51d6a85`. Grep across
`app/Platform/Payment/` now returns **five** occurrences, one of which is a genuine `actorRole:`
call site:

- `RecordPaymentActionRefusal.php:129` — `actorRole: $actor->isAuthenticated() ? 'authenticated_actor' : 'guest',` (**a call site**)
- `RecordPaymentActionRefusal.php:107`, `Http/Controllers/RecordPaymentReversalController.php:128`,
  `Http/Controllers/VerifyManualPaymentController.php:121`,
  `Contracts/PaymentActionAuthorizer.php:27` (four comments, not three)

The falsifying change is the review's own SF-6 fix. The fix-round report is accurate about this
(*"this is now the ONE place in the module the sentinel is still written"*); only the review is
stale.

**Why it matters.** This is the third instance on this branch of a correction applied in one place
and not its twin, and it is the most consequential kind: a **review conformance table** that a
signer reads as independent verification now certifies a property the branch does not have. A
future reader grepping to confirm "zero sentinels" will find one and have no way to tell whether
it is a regression or a deliberate later decision.

**Fix.** Append a one-line supersession to both lines — *"Superseded by the SF-6 fix round: one
deliberate sentinel write now exists at `RecordPaymentActionRefusal.php:129`; see that class's doc
block"* — following the correction convention the reports already use elsewhere.

---

### SF-9 — the task-scoped review's MFA premise is wrong in three places, including the text it dictated

**File:** `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix/docs/superpowers/reports/2026-08-12-payment-auth-task-scoped-review.md:84-86`, `:443-444`, `:461`

> `…\`EnforceMfaChallenge\` is attached only in \`app/Providers/Filament/AdminPanelProvider.php:162\`, i.e. to the Filament panel's middleware array.` (:84-86)

> `…\`EnforceMfaChallenge\` is registered only in \`app/Providers/Filament/AdminPanelProvider.php:162\`…` (:443-444)

> `"…is attached to the Filament panel only, so these plain \`web\` routes carried no MFA gate at all."` (:461 — the replacement text the review prescribed)

**What is wrong.** All three are false. `EnforceMfaChallenge` is attached in **two** places: the
panel middleware array *and* inline at `routes/web.php:282` on `/admin/finance/exports`. Verified
by repo-wide grep.

**Why it matters.** The review's SF-5 *conclusion* — no MFA on these two routes — is correct, and
the implementers noticed the error and shipped the accurate two-place wording in both controllers
instead of the review's dictated text. So the code is right and the **review** is the artifact
carrying the defect. But the wrong premise is precisely the one that makes the finance-export
contrast possible: "attached to the panel only" would mean the omission on these routes was
uniform and unremarkable, when in fact an adjacent standalone route does carry it, which is what
makes the omission conspicuous. A signer reading the review bundle gets a false fact about the
middleware stack and a weaker version of the finding than the evidence supports.

**Fix.** Correct all three to *"attached in exactly two places —
`AdminPanelProvider.php:162` and inline at `routes/web.php:282`"*, and note that the implementers'
shipped wording is the accurate one.

---

## 5. NIT

### NIT-1 — the refusal write turns unthrottled endpoints into unbounded audit-row writers, and no document says so

`RecordPaymentActionRefusal.php:20-23` notes that neither route carries a rate limit; plan §7's
N-3 entry says *"Refusal telemetry without a rate limit is a monitoring improvement, not a
throttle"*; fix-round §8 item 4 says the row is not yet monitored. All correct, and none states
the new consequence: **each refused request now costs a durable `audit_events` INSERT**, unbounded,
on a route with no `throttle`. The signal the fix round exists to create can be drowned by the
party being monitored, and the table grows without bound under a probe.

Kept at NIT for two reasons. The attacker must already hold an authenticated session; and
per-request write amplification on these routes is not new —
`RequireRecentAuthentication` (`app/Http/Middleware/RequireRecentAuthentication.php:143-160`)
already wrote a `reauthentication_events` row plus an audit row per request for any actor outside
the freshness window, which an attacker can trivially be.

**Fix.** One sentence appended to plan §7's N-3 entry, so the trade-off is on record with the
deferral rather than discovered later.

### NIT-2 — `report()` can itself convert the 403 into the 500 the class promises to prevent

`RecordPaymentActionRefusal.php:133-135` catches `Throwable` from the audit write and calls
`report($failure)` — but that call is not itself protected. Laravel's
`Illuminate\Foundation\Exceptions\Handler::reportThrowable()`
(`vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:459-479`) rethrows the
original exception when the logger cannot be constructed, and wraps the actual `$logger->log(…)`
call in `try { … } finally { … }` with **no** `catch`. A failing log handler therefore propagates
out of the `catch` block and out of the controller.

The class's claim at lines 65-68 is absolute — *"A failed audit write must never turn a 403 into a
500"* — and it holds against an audit-database outage, which is the realistic case. It does not
hold against a log-subsystem failure. Narrow, but the claim is stronger than the code.

**Fix.** `rescue(fn () => report($failure));`, or a second `try`/`catch` around the `report()` call.

### NIT-3 — the `guest` branch is unreachable

`RecordPaymentActionRefusal.php:129`. Both routes carry `auth`, and `ActorContext` is a per-request
`scoped` binding resolved from the authenticated guard
(`app/Platform/IdentityAccess/Providers/IdentityAccessServiceProvider.php:53`), so
`isAuthenticated()` is always `true` by the time the controller runs. Harmless defensive code that
mirrors `RequireRecentAuthentication:154`'s precedent deliberately; recorded only so nobody reads
the `guest` arm as a covered path. No change required.

### NIT-4 — the two refusal helpers check different leak fields

`RecordPaymentReversalRouteTest.php:142` asserts the recorded reason does not contain `'TRX-'` —
the caller's **reference**. `VerifyManualPaymentRouteTest.php:158` asserts it does not contain
`'Proof matched'` — the caller's **reason**. Neither file checks both. Each therefore proves
slightly less than the pair reads as proving, and a regression leaking the *other* field would be
caught at only one of the two endpoints.

**Fix.** Assert both caller-supplied strings in both helpers.

### NIT-5 — one row of the fix round's verification table does not reproduce

`docs/superpowers/reports/2026-08-12-payment-auth-fix-round.md:245` records
`**PASS** — OK (227 tests, 1030 assertions)`. Executed at `51d6a85` for this review, same command,
same pinned SQLite connection: `OK (227 tests, 1028 assertions)`. 227 tests match; 1030 assertions
do not. The report and the code landed in the **same commit**, so no later edit explains the gap —
the recorded output was not transcribed from a run of the state that shipped.

Kept at NIT because the check genuinely was executed and genuinely passed; only the transcription
is wrong, and two assertions change nothing. Noted anyway because the table's preamble (lines
238-239) reads *"Every command below was run in this worktree and its output observed"*, and this
is the one row in the bundle a reviewer can cheaply falsify.

**Fix.** Correct `1030` to `1028`.

### NIT-6 — the audit reason string restates the role pair without linking the canonical constant

`RecordPaymentActionRefusal.php:104`:

```php
private const string REASON = 'Refused: the actor holds no finance or restricted-admin authority for this payment action.';
```

This restates the authorized pair in free text, not derived from `AUTHORISED_ROLES` or
`ActorRole`. If the allow-list ever changes, the string silently becomes a false statement written
into `audit_events.reason` — the one copy a human reads and cannot cross-check. Not a catalogue
duplication in the strict `AGENTS.md` §Documentation sense, but it is the only place on the branch
where the role pair is asserted without a path back to the canonical constant. (The class's
`AUTHORISED_ROLES` at
`FinanceOrRestrictedAdminPaymentAuthorizer.php:138-141` does this correctly, and says why.)

**Fix.** Build the sentence from `ActorRole::FINANCE`/`ActorRole::RESTRICTED_ADMIN`, or drop the
role names from it.

### NIT-7 — "each of the four `FinancialLedger` authorizers" over-attributes a quoted phrase

`FinanceOrRestrictedAdminPaymentAuthorizer.php:98-100` and
`docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md:123` attribute the literal
claim that `ActorContext::$roles` *"is always `[]`"* to all four ledger authorizers. Only three
carry that phrasing (`FinanceLedgerReadAuthorizer.php:50`,
`FinanceReconciliationAuthorizer.php:43`, `FinanceVendorPayableAuthorizer.php:61`); the fourth,
`FinanceOrRestrictedAdminPayoutAuthorizer.php:20`, makes the equivalent claim in different words.
Substantively the same staleness — which remains out of scope as another lane's files — but the
quoted string is attributed to a file that does not contain it.

**Fix.** *"Three carry the literal `is always []` phrasing and the fourth the equivalent claim."*

### NIT-8 — two reports contradict each other on the working-tree state at `8be7e49`

`docs/superpowers/reports/2026-08-12-payment-auth-task-scoped-review.md:7` — *"working tree clean
at `8be7e49`, `git status --porcelain` empty — no mutation-test residue"* (repeated at `:573`)
versus `docs/superpowers/reports/2026-08-12-payment-auth-fix-round.md:6` — *"**Base:** `8be7e49`
plus the pre-existing uncommitted doc-comment rewording, which was left in place"*.

Both describe the same tree at the same commit and cannot both be right, and the reviewer used
tree cleanliness as affirmative evidence that no mutation residue leaked. **No live risk:** I
verified independently that the tree is clean at `51d6a85` (§1 check 1). Historical record only.

**Fix.** One line reconciling which was the case, in whichever report was wrong.

### NIT-9 — a reviewer's conformance table asserts a run the same reviewer states they did not perform

`docs/superpowers/reports/2026-08-12-payment-auth-task-scoped-review.md:48` — *"I did **not**
re-run the suites, Pint, PHPStan, or the mutation test — per instruction, the lane driver's results
are taken as given"* — against `:248`, which states as fact in a conformance table: *"Executed by
the lane driver after the report was written (218 tests green on PostgreSQL 18.4). Report §8.1
still says NOT TESTED — stale but harmless."*

Second-hand and asserted without an artifact reference. It is attributed, which is the mitigating
half; *"stale but harmless"* is the problematic half, since SF-3 shows the staleness was not
harmless.

**Fix.** Mark the row `NOT VERIFIED BY REVIEWER — lane driver report only`.

---

## 6. Operational consequence — what the signer is actually approving

Stated plainly, because item 5 of the review brief asks for it and because it is the part a code
diff does not show.

**On merge, both money-moving admin routes return 403 to every actor in the system** until someone
runs `identity:grant-role <actor> finance --reason=…` or the `restricted_admin` equivalent. This is
correct, deliberate (plan D1), and the only safe direction for an authorization fix — but it takes
effect at deploy, not at first use.

Three facts that bound it:

1. **Nothing in the UI reaches these routes** (see SF-7), so no user-visible flow breaks.
2. **A plain `admin` grant does not unblock either flow.** Plan §6 says so explicitly, the
   authorizer's doc block explains why, and
   `FinanceOrRestrictedAdminPaymentAuthorizerTest`'s `unauthorizedRoles` provider pins `admin` in
   the refused set. The first operator to hit this will very likely be someone holding `admin` and
   expecting it to be enough — the case SF-5 makes invisible in the audit trail.
3. **The refusal is now recorded**, which is the fix round's contribution — but nothing consumes
   `PAYMENT_ADMIN_ACTION_DENIED` yet (fix-round §8 item 4), so in practice the first symptom will
   be a human reporting a 403, not an alert.

None of this argues against merging. It argues for the grant step travelling with the deploy
rather than with a plan document (SF-7).

---

## 7. Known-and-accepted items — checked, not re-litigated

Each was verified to be no worse than described.

| Item | Status |
| --- | --- |
| Role-only, no scope grant (D1) | **As described.** `payment_reversals` and `payment_verifications` genuinely carry no column in the `scope_assignments.entity_id` value space, and `reference` is caller-supplied — scoping on it would be strictly worse than not scoping. The reasoning in the authorizer's doc block holds. |
| Caller-supplied `actorRole` on the write APIs (D2) | **As described, still unexploitable.** Exactly one production caller each, both of which now pass the authorizer's return value. |
| No rate limit on either route (N-3) | **As described, with one addition** — see NIT-1 for the new write-amplification consequence. |
| Four stale `FinancialLedger` authorizer doc blocks | **Untouched, out of scope.** Confirmed not modified by this branch. One attribution nit about them appears in *this* branch's files — NIT-7. |
| Spaced-prose ledger citations in `app/Platform/Payment/` | **Intentional and load-bearing.** `NoAutomatedPayoutPathTest` executed green here (10 tests, 42 assertions). Not recommended for tidying. |

---

## 8. `AGENTS.md` compliance

| Rule | Finding |
| --- | --- |
| §Documentation — do not duplicate canonical catalogue data | **PASS.** `FinanceOrRestrictedAdminPaymentAuthorizer::AUTHORISED_ROLES` references `ActorRole::RESTRICTED_ADMIN`/`FINANCE` rather than redeclaring literals, and cites `AGENTS.md` as the reason. The `rbac-matrix.md` addition back-references the class and keeps `ActorRole::KNOWN_ROLES` as the canonical vocabulary; it creates no rival list. `tasks.md:54` names the pair once, pointing at the plan. One soft exception: NIT-6. |
| §Observability — no restricted data in logs/audit/trackers | **PASS.** No caller-supplied value reaches the refusal row, the exception message, or `report()`. Verified against `Audit::record()`'s actual write. |
| §Documentation — update spec when behaviour changes | **SF-2.** One of the two changed routes updated in `tasks.md`; the other not. No API contract is implicated — `docs/contracts/openapi.yaml` contains no `/admin/payments/*` path. Screen inventory not implicated — no admin UI exists for either flow. |
| §Infrastructure-agent execution — never report `PASS` for an unexecuted check | **PASS in intent; SF-3, NIT-5 and NIT-9 in execution.** All three reports use `NOT TESTED` correctly and conspicuously, and the fix-round report's §6 preamble and §8 are exemplary. The defects are one affirmative "gate met" that outran its evidence, one figure that does not reproduce, and one second-hand result asserted in a conformance table. None is a false PASS on an unexecuted check. |
| §Infrastructure-agent execution — human review mandatory before authorization/financial changes | **Applies.** This report is an input to that review, not a substitute. |
| §Authentication and uploads — mandatory TOTP MFA for privileged roles | **SF-6.** Both routes now require privileged roles and carry no MFA gate; the gap is stated in the controller doc blocks but is not a tracked deferral. |
| §Testing — `Covered` needs test evidence; every bug fix needs a regression test | **PASS.** The vulnerability is inverted into a test in both route files, plus revoked-grant, wrong-role, ordering, oracle, and audit-outage cases. One unpinned property — SF-4. |
| §Development methodology — plan first, worktree isolation, two-tier review, one PR | **PASS.** Plan committed at `83d6398` before implementation at `8be7e49`; work isolated in `.worktrees/platform-payment-auth-hotfix`; task-scoped review then this whole-branch review. |

---

## 9. Merge recommendation

**Recommended for human merge sign-off**, conditional on the signer disposing of the nine
SHOULD-FIX items — by fixing them or by explicitly waiving each on the record.

Suggested disposition:

- **Fix before merge — text edits, each a correctness-of-record issue in the sign-off bundle:**
  SF-1, SF-2, SF-3, SF-7, SF-8, SF-9. Six documentation corrections, none of which touches code.
- **Fix before merge — test:** SF-4. A few lines, and it protects the field the fix round's whole
  value rests on.
- **Decide before merge, implement either way:** SF-5 and SF-6. Both are judgement calls with a
  defensible "no" — but the "no" must be written down, and for SF-6 the rule it sits against is
  binding.
- **Ledger and park:** all nine NITs.

The security defect this branch was opened to close **is** closed, correctly and fail-closed, and
the fix round's new code is sound. Nothing found here warrants blocking the merge.
