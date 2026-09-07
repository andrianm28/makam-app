# DOM-01 Purge Dependency Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permanent fix for finding DOM-01 (Critical) from the 6 Sep 2026 audit — implements Task 1.1 of `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`. `PurgeStaleBookingDrafts` deletes any booking draft past its retention window with no check for dependent records, so a draft behind a live order becomes permanently unquotable once purged (`IssueOrderQuote` throws when `order->bookingDraft` is null).
**Architecture:** One Domain Action, `App\Domain\Booking\Actions\PurgeStaleBookingDrafts`.
**Tech Stack:** Laravel 13, PHPUnit against PostgreSQL 18.
**Spec:** Audit finding DOM-01 — `report-data.json` and `https://claude.ai/code/artifact/d331e5e4-456b-444d-b2c4-771a3be7ff61`.

## Global Constraints

- No new inverse Eloquent relation on `BookingDraft` — `BookingDraftQuery::openForUser()`'s own doc block documents why (a cross-domain model cycle between `Domain\Booking` and `Domain\OrderWorkflow`/`Domain\FuneralCase`/`Domain\PreNeed`). Follow its exact `whereNotIn` subquery pattern.
- `PlotReservation` is deliberately excluded from the new guard. Its `booking_draft_id` FK's `nullOnDelete` severs cleanly with no functional loss (the reservation chain is append-only history, not a live read dependency) — this is the EXISTING, already-tested-correct behaviour (`test_a_stale_draft_with_a_live_plot_hold_is_still_purged`), and must not be broken.
- Tests run against real PostgreSQL 18 (uuid FK behaviour, `nullOnDelete` semantics).

---

### Task 1: Guard the purge against Order/FuneralCase/PreNeedInterest dependents

**Files:**
- Modify: `app/Domain/Booking/Actions/PurgeStaleBookingDrafts.php`
- Modify: `tests/Feature/Domain/Booking/Actions/PurgeStaleBookingDraftsTest.php`

**Interfaces:**
- Consumes: `orders`, `funeral_cases`, `pre_need_interests` (read-only subqueries on `booking_draft_id`).
- Produces: nothing new.

- [x] **Step 1: Write the failing tests**
  Four new cases: a draft behind a live `Order` survives and the FK link stays intact; a draft behind a `FuneralCase` survives; a draft behind a `PreNeedInterest` survives; one guarded draft does not block the rest of the sweep from deleting a truly-abandoned one. Confirmed RED against real Postgres before implementing.

- [x] **Step 2: Add the three `whereNotIn` guards**
  Mirrors `BookingDraftQuery::openForUser()`'s exact subquery shape. Confirmed GREEN — all 14 tests in the file pass (10 pre-existing + 4 new), including the plot-reservation tests proving that behaviour is unchanged.

## After all tasks: whole-branch verification

```bash
IMAGE=149ac33766fb
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint php "$IMAGE" vendor/bin/pint --test
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint php "$IMAGE" -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
bash ci/verify-docs.sh
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:RKxTuGlM4MNUB65volwGUsTfCiDumShAS0GGdu5zXn4= \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<port> -e DB_DATABASE=testdb \
  -e DB_USERNAME=testuser -e DB_PASSWORD=testpass \
  --entrypoint php "$IMAGE" vendor/bin/phpunit tests/Feature/Domain/Booking/Actions/PurgeStaleBookingDraftsTest.php
```

Result (this pass): pint clean, phpstan 0 errors, verify-docs all 13 gates pass, 14/14 tests green against real Postgres 18.

## Note on sequencing with Phase 0

`docs/superpowers/plans/2026-09-06-phase0-critical-stopgaps.md` (PR #240) disabled the scheduler entry as an interim stop-gap. This PR does not touch `routes/console.php` — the underlying Action is now safe to run, so once this PR merges the scheduler entry from PR #240 can simply be re-enabled (uncommented) in a small follow-up, in either merge order, since the two PRs touch non-overlapping lines.
