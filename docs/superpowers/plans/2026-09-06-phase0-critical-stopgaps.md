# Phase 0 Critical Stop-Gaps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Neutralize the two most time-critical Critical findings from the 6 Sep 2026 full audit (`docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` — master remediation program; this plan implements only its Phase 0) with the lowest-risk possible changes, ahead of a real ~19 Sep 2026 data-loss deadline, without touching production infrastructure directly.
**Architecture:** Laravel scheduler (`routes/console.php`) + one new read-only Artisan command for observability.
**Tech Stack:** Laravel 13, PHPUnit against PostgreSQL 18 (pinned CI-parity image).
**Spec:** Audit findings DOM-01 (Critical), COORD-07 (Critical), COORD-15 (Medium) — full evidence in the audit's `report-data.json` and artifact `https://claude.ai/code/artifact/d331e5e4-456b-444d-b2c4-771a3be7ff61`.

## Global Constraints

- This is a stop-gap only. The permanent fix for DOM-01 (dependency-aware purge predicate) and COORD-07 (a real `media` queue consumer) land separately in Phase 1 of the master remediation plan — do not attempt the permanent fixes here.
- No production/host changes in this PR. Everything here ships through the normal CI → dev → beta pipeline.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `bash ci/verify-docs.sh` clean.
- Tests run against real PostgreSQL 18 via the pinned CI-parity image, not SQLite, since the alert commands query `documents.state` and `failed_jobs` — both real Postgres tables.

---

### Task 1: Disable the destructive booking-draft purge schedule

**Files:**
- Modify: `routes/console.php:14`
- Test: `tests/Feature/Console/SchedulerCriticalGuardsTest.php` (create)

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new (this task only stops a schedule entry from firing).

- [ ] **Step 1: Write the failing test**
  Assert `booking:purge-stale-drafts` no longer appears in `schedule:list` output, following the exact pattern of `tests/Feature/DocumentVault/DocumentScheduleTest.php`.
  Run: `docker run --rm --user 1000:1000 -v <worktree>:/var/www/html -w /var/www/html -e APP_ENV=testing -e APP_KEY=base64:RKxTuGlM4MNUB65volwGUsTfCiDumShAS0GGdu5zXn4= --entrypoint php <image> vendor/bin/phpunit tests/Feature/Console/SchedulerCriticalGuardsTest.php`
  Expect: FAIL (the schedule entry is still registered).

- [ ] **Step 2: Comment out the schedule entry**
  In `routes/console.php:14`, replace the live `Schedule::command('booking:purge-stale-drafts')->dailyAt('03:15');` with a commented-out line plus an explanatory block citing finding DOM-01, the ~19 Sep 2026 deadline, and the Phase 1 task that will re-enable it with a safe predicate.
  Run the same test again. Expect: PASS.

---

### Task 2: Alert on stale quarantined documents and on failed critical/urgent jobs

**Files:**
- Create: `app/Console/Commands/AlertCriticalOperationalGapsCommand.php`
- Modify: `routes/console.php` (register the new command)
- Test: `tests/Feature/Console/AlertCriticalOperationalGapsCommandTest.php` (create), extend `tests/Feature/Console/SchedulerCriticalGuardsTest.php`

**Interfaces:**
- Consumes: `documents` table (`state` column, read-only), `failed_jobs` table (`queue` column, read-only).
- Produces: log warnings (via the standard `Log` facade at `warning` level) — no new outbox events, no state mutation.

- [ ] **Step 1: Write the failing tests**
  - A documents-side case: seed one document with `state` != `accepted` and `created_at` older than 1 hour, and one fresh one; assert the command reports exactly 1 stale document and logs a warning containing its count.
  - A failed_jobs-side case: seed 2 rows in `failed_jobs` with `queue = 'critical'` and 1 with `queue = 'default'`; assert the command reports exactly 2 (only critical/urgent counted).
  - A clean case: no stale documents, no failed jobs on critical/urgent; assert the command reports zero of both and does not log a warning.
  Run against the pinned image with a real Postgres connection (documents/failed_jobs schema is Postgres-specific — do not use SQLite for this test per the repo's own established rule).

- [ ] **Step 2: Implement the command**
  `php artisan alert:critical-operational-gaps` — two read-only queries, `Log::warning()` when either count is non-zero, exit code 0 always (this is observability, never a hard failure). Follow the existing `spine:watchdog` command's structure/doc-comment style as the nearest precedent for a scheduled health-check command.

- [ ] **Step 3: Register on the scheduler**
  `routes/console.php` — `Schedule::command('alert:critical-operational-gaps')->everyFiveMinutes()->withoutOverlapping();`, immediately after the existing `spine:watchdog` entry, with a comment citing findings COORD-07 and COORD-15 and noting these are visibility-only stop-gaps.
  Extend `SchedulerCriticalGuardsTest.php` to assert this command IS registered.

## After all tasks: whole-branch verification

```bash
IMAGE=149ac33766fb   # pinned ghcr.io/andrianm28/makam-app CI-parity image, current as of this plan
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint php "$IMAGE" vendor/bin/pint --test
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint php "$IMAGE" -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
bash ci/verify-docs.sh
# Full Postgres run for the new/changed tests specifically (see project_worktree_test_env memory
# for the disposable postgres+redis container recipe):
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  -e APP_ENV=testing -e APP_KEY=base64:RKxTuGlM4MNUB65volwGUsTfCiDumShAS0GGdu5zXn4= \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<port> -e DB_DATABASE=testdb \
  -e DB_USERNAME=testuser -e DB_PASSWORD=testpass \
  --network host --entrypoint php "$IMAGE" -d memory_limit=512M vendor/bin/phpunit \
  tests/Feature/Console/SchedulerCriticalGuardsTest.php \
  tests/Feature/Console/AlertCriticalOperationalGapsCommandTest.php
php artisan schedule:list   # manual sanity check inside the container
```

Success criteria: `booking:purge-stale-drafts` absent from `schedule:list`; `alert:critical-operational-gaps` present; both new test files green against real Postgres; pint/phpstan/verify-docs clean.
