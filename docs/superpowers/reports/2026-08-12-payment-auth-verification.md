# Verification — payment controller authorization hotfix

**Date:** 12 Aug 2026
**Branch:** `fix/payment-controller-authorization`
**Worktree:** `/home/ubuntu/makam-app/.worktrees/platform-payment-auth-hotfix`
**Role:** fresh lane driver, resumed after the final fix round's driver stopped before verify/commit.
**Input:** `2026-08-12-payment-auth-final-fix-round.md` (nine SHOULD-FIX items disposed), `2026-08-12-payment-auth-whole-branch-review.md`.
**State on arrival:** 12 uncommitted files (9 modified, 3 untracked) at HEAD `51d6a85`; nothing committed since the final fix round.

This report records what a **fresh** verification run observed. Everything below was executed in
this worktree during this session; nothing is reported as PASS that was not run. The work itself
was not re-planned or re-litigated — the final fix round's dispositions were taken as given and
independently diff-checked against the tree.

---

## 1. Fresh state check — the uncommitted set matches the final fix round's scope

`git status --short` on arrival: 12 files. The nine modified files are the SHOULD-FIX dispositions
and their test changes; the three untracked files are the final-fix-round report, the whole-branch
review, and the new `RecordPaymentActionRefusalTest`:

| File | Diff-checked against | Match |
| --- | --- | --- |
| `.kiro/specs/platform-payment-adapter/tasks.md` | SF-2 (reversal entry supersession) | Yes |
| `app/Platform/Payment/RecordPaymentActionRefusal.php` | SF-4 (actor_ref), SF-5 (`auditRoleFor`) | Yes |
| `docs/operations/ci-cd-and-release.md` | SF-7 (§5.1 + §8 line) | Yes |
| `docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md` | SF-1 (§6 correction), SF-6 (§7 deferral) | Yes |
| `docs/superpowers/reports/2026-08-12-payment-auth-fix-round.md` | SF-1, SF-3 (RESOLVED lines) | Yes |
| `docs/superpowers/reports/2026-08-12-payment-auth-implementation.md` | SF-3 (NARROWED) | Yes |
| `docs/superpowers/reports/2026-08-12-payment-auth-task-scoped-review.md` | SF-8, SF-9 (dated corrections) | Yes |
| `tests/Feature/Payment/RecordPaymentReversalRouteTest.php` | SF-4/SF-5 (helper signature, admin test) | Yes |
| `tests/Feature/Payment/VerifyManualPaymentRouteTest.php` | SF-4/SF-5 (helper signature, admin test) | Yes |
| `docs/.../payment-auth-final-fix-round.md` (untracked) | Whole-branch review's input report | — |
| `docs/.../payment-auth-whole-branch-review.md` (untracked) | 9 SHOULD-FIX / 9 NIT record | — |
| `tests/Unit/Platform/Payment/RecordPaymentActionRefusalTest.php` (untracked) | SF-4/SF-5 unit pins | Yes |

No stray edits: `git diff --check` passes and no file outside the reported set is modified.

## 2. Standing constraints honored

- No `domain-builder` / `slice-builder` used; all nested work was `general-purpose`.
- No authorization check weakened, no `authorize()`-before-`findOrFail` ordering changed, no AC9
  guard token matching touched — this branch made no code change to any of those in this session.
- The pre-fix severity correction (no MFA at all; `EnforceMfaChallenge` is panel + `/admin/finance/exports`
  only, never these two routes) is preserved in every corrected artifact.

## 3. Verification executed

All commands ran in this worktree with output observed.

### 3.1 Full test suite — pre-rebase (at `51d6a85` content + uncommitted final-round work)

Command: `php vendor/bin/phpunit` (pinned `DB_CONNECTION=sqlite`, `:memory:`)

**Result:** `Tests: 1876, Assertions: 7264, Errors: 2, Skipped: 59.`

- Test count = 1859 (whole-branch-review baseline) + 17 (final round) = 1876. ✓
- Assertion count = 7201 (baseline) + 63 (final round) = 7264. ✓
- The two errors are the **documented pre-existing** `DROP TABLE ... CASCADE` SQLite
  incompatibilities, by exact name: `Tests\Feature\FeatureGate\EloquentGateRegistrySourceTest::test_a_total_registry_load_failure_denies_every_gate_instead_of_throwing` and `Tests\Feature\Livewire\Public\HomePageRouteTest::test_faq_highlights_degrade_gracefully_when_the_faq_query_fails`. Neither file is touched by this branch.
- The 59 skips are the standing skip set; none is branch-added.

Note on the artisan runner: `php artisan test` flags the same suite's tests as "warnings" because a
fresh worktree has no `.env` and the bootstrap's `file_get_contents(.../.env)` raises a PHP
warning that the artisan runner reclassifies per-test. The identical tests pass under
`vendor/bin/phpunit` (`GraveNameNormalizerTest`: artisan reports 13 warnings, phpunit reports
`OK (13 tests, 18 assertions)`). This is environmental, present on untouched files, and not a
branch defect; the `vendor/bin/phpunit` run above is the comparable one.

### 3.2 Full test suite — post-rebase (after rebasing onto `origin/docs/design-system-and-planning`)

Per the handoff, verification ran first, then the rebase, then the suite again. Rebase was clean
(`git rebase origin/docs/design-system-and-planning`, no conflicts); the branch now includes the
two trunk commits it was missing (`2c3ca8f` dockerignore fix, `95656fb` portable `dropIfExists`
CASCADE test fix — unrelated files).

**Result:** `Tests: 1876, Assertions: 7272, Errors: 0, Skipped: 59.` The two pre-existing CASCADE
errors are gone (fixed by trunk's `95656fb`), assertion count rose by the 8 assertions those two
tests contribute. **The rebase introduced no regression.**

### 3.3 Static analysis

- `vendor/bin/pint --test`: **PASS** — `{"tool":"pint","result":"passed"}` (whole repo).
- `php -d memory_limit=512M vendor/bin/phpstan analyse app/Platform/Payment tests/Feature/Payment tests/Unit/Platform/Payment`: **PASS** — `[OK] No errors` (79 files). This is the scoped gate the final fix round used and the one this branch's code is subject to.
- `php -d memory_limit=512M vendor/bin/phpstan analyse` (whole repo): **1 error**, in
  `app/Domain/GraveRegistry/GraveRegistryPublicQuery.php:166` (`Call to an undefined method ... ::published()`), committed `2026-08-09` (`f28bb7b`), present identically at the fork point `d9fea9f`, and **not touched by this branch** (0 files under `app/Domain/` in the branch diff). Pre-existing, not a regression.

### 3.4 Documentation gates and hygiene

- `bash ci/verify-docs.sh`: **PASS** — `RESULT: ALL DOC GATES PASS` (13 gates).
- `git diff --check`: **PASS** — no whitespace errors.

### 3.5 Real PostgreSQL 18 — FRESH, current content (the run the final fix round could not do)

The final fix round explicitly recorded that the tests it added (SF-4/SF-5 pins) had been exercised
on SQLite only, and that PostgreSQL re-verification was owned by the lane driver. That gap is
closed here.

Disposable container **`payment-auth-pg-55572`** (`postgres:18`, PostgreSQL **18.4**,
`POSTGRES_DB=makam_payment_auth`, password `verify`), port 55572, dedicated to this lane — the
shared `makam-nonprod-postgres-1` was **not** touched. PHPUnit's `<env>` pins default to
`force="false"`, so pre-set shell env vars (`DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`,
`DB_PORT=55572`, `DB_DATABASE=makam_payment_auth`, `DB_USERNAME=postgres`, `DB_PASSWORD=verify`)
override the sqlite defaults; `php artisan tinker --execute="echo config('database.default')"`
confirmed the default resolves to `pgsql`.

- Payment module (`tests/Feature/Payment/ tests/Unit/Platform/Payment/`):
  **PASS** — `OK (244 tests, 1091 assertions)` on PostgreSQL 18.4, identical counts to the SQLite
  run of the same content (244/1091). Includes the full final-round set.
- The final round's new tests specifically (SF-4 `actor_ref` pin, SF-5 `auditRoleFor` real-role
  pins, refused-admin case) via `--filter="RecordPaymentActionRefusalTest|RefusedAdmin|refused_admin|RealRole"`:
  **PASS** — `OK (17 tests, 43 assertions)` on PostgreSQL 18.4.

This exercises the two PostgreSQL-specific risks the final fix round named:
`audit_events.actor_role` accepts the real role values now written on the refusal path (previously
only the two sentinels reached that column on this path), and the SF-4/SF-5 `actor_ref` comparisons
behave identically under the real column type.

## 4. Summary

| Check | Result |
| --- | --- |
| Uncommitted set matches final-fix-round scope | PASS (diff-checked file by file) |
| Full suite (pre-rebase, stale base) | 1876 tests, 7264 assertions, 2 pre-existing CASCADE errors, 59 skipped |
| Full suite (post-rebase) | **1876 tests, 7272 assertions, 0 errors, 0 failures, 59 skipped** |
| Pint (`--test`, whole repo) | PASS |
| PHPStan (payment module scope) | PASS (79 files, no errors) |
| PHPStan (whole repo) | 1 pre-existing error in `GraveRegistryPublicQuery.php` (untouched by branch) |
| `ci/verify-docs.sh` | PASS (13 gates) |
| `git diff --check` | PASS |
| **PostgreSQL 18.4** (disposable `payment-auth-pg-55572`) | **PASS** — payment module 244/1091, new SF-4/SF-5 tests 17/43 |

No BLOCKED items. The branch is verified at its rebased tip with the final round's content, on both
SQLite and real PostgreSQL 18.4, and is ready for human merge sign-off and commit.
