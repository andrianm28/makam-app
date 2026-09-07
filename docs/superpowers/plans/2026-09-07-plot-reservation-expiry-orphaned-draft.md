# Plot reservation expiry: a held plot never returns to "Tersedia" once its draft is purged

## Context

Real customer report, 7 Sep 2026 (via WhatsApp): a customer said a plot they held stayed unavailable well past its hold window ("sudah lewat dari waktu yang ditahan namun tidak kembali tersedia").

## Investigation

`PlotReservationExpiryScheduler::expireStaleDraftHolds()` sweeps every minute (`routes/console.php`) for `plot_reservations` rows still `held` past their `expires_at`. Two real bugs, found while tracing this end to end:

1. The scheduler's original candidate loop did `BookingDraft::query()->find($draftId)` and silently `continue`d when the draft was `null` — but this branch could never actually close the reported bug, because...
2. `plot_reservations.booking_draft_id` is declared `nullOnDelete()` (`2026_08_29_100000_add_booking_draft_hold_to_plot_reservations_table.php`). Once `PurgeStaleBookingDrafts` deletes a stale draft (its own, unrelated 30-day retention window — the hold TTL default is 15 minutes), Postgres itself sets that row's `booking_draft_id` to `NULL` as part of the same delete. The scheduler's candidate query (`whereNotNull('booking_draft_id')`) then excludes that row **forever** — it can never again be grouped by a draft id it no longer has, even though its `state` column still reads `held` and its `expires_at` is long past. The plot never returns to `available`.

This is only reachable when the sweep itself has not run in a very long time (long enough for `PurgeStaleBookingDrafts`'s 30-day window to also elapse for the same draft) — consistent with finding QUE-01 (6 Sep 2026 audit), which already flagged that `docs/operations/dev-staging-environment.md` §9 documents "no always-on scheduler required" for dev and only says staging/production cron "should" invoke it once a minute, with nothing in `ci/verify-infra.sh` actually verifying that cron entry exists on the real host. This PR does not attempt to verify or fix the host-level cron — that is exactly the kind of infrastructure change AGENTS.md §Infrastructure-agent execution reserves for human review. It closes the code-level bug that makes a sweep-outage of any length permanently unrecoverable for the plots affected during it, rather than merely delayed.

## Fix

`PlotReservationExpiryScheduler::expireStaleDraftHolds()` now runs two candidate passes:

1. **Draft-scoped** (unchanged in shape): distinct `booking_draft_id`s with a stale `held` row, re-derived via a new `PlotReservation::activeForDraftId(int|string $draftId)` (extracted from `activeForDraft(BookingDraft $draft)`, which now delegates to it) — this only ever needed the id, never the draft's content, so there was never a real reason to load the `BookingDraft` row first. Kept for the (much narrower, arguably now dead given `nullOnDelete()`'s atomicity) case where a draft is removed by some future path that doesn't go through this same FK.
2. **Plot-scoped, new**: distinct `plot_id`s with a stale `held` row whose `booking_draft_id` is already `NULL` — the orphaned case above — re-derived via a new `PlotReservation::activeForPlotId(int|string $plotId)` (extracted from the previously-unused `activeForPlot(GravePlot $plot)`, which now delegates to it). `expires_at IS NOT NULL` is what tells an orphaned draft-scoped hold apart from an operator-initiated (`order_id`-anchored) one, which never sets `expires_at` at all.

Both passes share the same per-candidate re-derivation-then-expire guard (extracted into `expireIfStillDue()`), preserving the existing idempotency and per-row isolation the class already had.

## Test

`tests/Feature/Domain/PlotReservation/PlotReservationExpiryTest.php` gained `test_a_stale_hold_is_still_expired_after_its_booking_draft_is_deleted`: holds a plot, backdates it stale, actually deletes the `BookingDraft` (triggering the real `nullOnDelete()` FK against a real PostgreSQL connection, not a mock), then asserts the sweep still expires it and the plot returns to `available`. Mutation-tested: temporarily disabled the new plot-scoped pass, confirmed this test goes red (`Failed asserting that actual size 0 matches expected size 1`), restored it, confirmed green again.

## Scope note

This PR does not address, and explicitly does not attempt to verify, whether the scheduler actually runs on any real deployed host — that's the human-executed cron/infrastructure question flagged by finding QUE-01. It only makes the sweep self-healing after an outage of any length, instead of the previous behavior where any outage long enough to overlap a draft purge left the affected plots stuck forever, unrecoverable even by a later healthy sweep.

## Verification

- `vendor/bin/pint --test` — PASS (23 files)
- `vendor/bin/phpstan analyse --no-progress` — no errors
- `bash ci/verify-docs.sh` — all 13 gates PASS
- `tests/Feature/Domain/PlotReservation/` (48 tests) — PASS, against real PostgreSQL 18 in Docker
- `tests/Feature/Livewire/Public/Booking/` + `tests/Feature/Domain/Booking/` (320 tests) — PASS, same real Postgres instance
- Mutation-tested the new fix as described above

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01V5HEWU9oWnDfM1kQTB9762
