<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation;

use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Exceptions\PlotReservationTransitionException;
use App\Domain\PlotReservation\Models\PlotReservation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Phase E (`docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md`
 * Task 4) — finds draft-scoped plot holds a customer abandoned and expires
 * them, mirroring `App\Domain\OrderWorkflow\QuoteExpiryScheduler`'s own
 * shape (read that class first): a domain-layer scheduler with a per-row
 * try/catch, backing a thin `Illuminate\Console\Command`.
 *
 * `ExpirePlotReservation` already exists and already does the real work
 * (plot-row lock, `held` -> `expired`, plot flip back to `available`,
 * audit + outbox) — this class is REUSE, not reimplementation: it only
 * finds the candidates and isolates a per-row failure.
 *
 * ---------------------------------------------------------------------------
 * Why the candidate query goes through `booking_draft_id`, not a raw
 * `state = 'held'` scan
 * ---------------------------------------------------------------------------
 * `plot_reservations` is append-only — a row's own `state` column NEVER
 * changes after insert (see `PlotReservation`'s class doc block). A naive
 * `where('state', HELD)->where('expires_at', '<', $now)` would therefore
 * match every draft-hold row that EVER became stale, forever, including
 * ones long since converted or expired by an earlier run — their OWN row
 * still reads `state = held` permanently; only a LATER, separate row in
 * the same plot's chain records what actually happened to it. Every run
 * would keep re-selecting that unboundedly growing historical set and
 * re-attempting (and catching a thrown exception for) each one, forever.
 *
 * Instead: find the DISTINCT `booking_draft_id`s that have any stale
 * `held` row at all (cheap, indexed), then re-derive each draft's TRUE
 * current hold via `PlotReservation::activeForDraftId()` — the same
 * incumbent-of-the-latest-row logic `HoldPlotForDraft`/
 * `ConvertDraftHoldToOrderReservation` already trust. Only a draft whose
 * ACTUAL current head is still `held` and still past its `expires_at` is
 * a real candidate; everything else (already converted, already expired)
 * is skipped before ever calling `ExpirePlotReservation`, not merely
 * caught after attempting it.
 *
 * ---------------------------------------------------------------------------
 * Why the candidate window is bounded below (whole-branch review I4)
 * ---------------------------------------------------------------------------
 * The `booking_draft_id` indirection above stops the sweep from ACTING on
 * historical rows, but not from SELECTING them: because the `state` column
 * never changes, a long-since-converted draft's original `held` row keeps
 * matching `state = held AND expires_at < now()` forever, so the candidate
 * set — and the per-draft `activeForDraftId()` re-derivation each candidate
 * costs — grew for the life of the table, on a sweep that runs every
 * minute. Bounded to the last day: the TTL default is 15 minutes
 * (`config/plot-reservation.php`), so a hold that expired more than a day
 * ago has, on any healthy schedule, been swept, converted or released
 * many times over.
 *
 * The accepted cost, stated rather than glossed: a hold can only age out
 * of this window while still live if the sweep itself has not run for
 * over 24 hours, and re-running the command afterwards will NOT pick
 * those rows up — they are outside the window for good. Their plots stay
 * `reserved` until an operator releases them from the Floor/Block Map,
 * which is the same manual override that already exists for a customer's
 * live draft hold. That is a deliberate trade: a bounded query that
 * needs operator recovery after a day-long outage, over an unbounded one
 * that degrades every minute for the life of the table.
 *
 * `(state, expires_at)` is indexed for exactly this predicate (see the
 * Task 1 migration).
 *
 * ---------------------------------------------------------------------------
 * The second candidate source: a hold whose `booking_draft_id` is gone
 * ---------------------------------------------------------------------------
 * Real customer report, 7 Sep 2026: a held plot did not return to
 * "Tersedia" after its hold window passed. `plot_reservations.
 * booking_draft_id` is `nullOnDelete()` (see `2026_08_29_100000_add_
 * booking_draft_hold_to_plot_reservations_table.php`'s own doc block for
 * why NOT restrict — `PurgeStaleBookingDrafts` deletes stale drafts nightly
 * on a 30-day retention window, unrelated to this sweep's 15-minute TTL),
 * so a hold that outlives a full run of BOTH this sweep AND the nightly
 * purge (only possible if this sweep itself has not run at all for a long
 * stretch — see the bounded-window note below) has its `booking_draft_id`
 * set to NULL out from under it. The `whereNotNull('booking_draft_id')`
 * candidate query above then excludes that row FOREVER: it can never be
 * grouped by a draft id it no longer has, so it stops being a "draft-scoped
 * hold" as far as that query is concerned, while its `state` column still
 * reads `held` and its plot never comes back to `available`.
 *
 * The fix mirrors the draft-scoped query exactly, keyed by `plot_id`
 * instead: find plots with a stale, now-orphaned `held` row, then
 * re-derive each plot's TRUE current head via
 * `PlotReservation::activeForPlotId()` — same incumbent-of-the-latest-row
 * logic, just grouped by the identifier that survives the draft's
 * deletion. `expires_at IS NOT NULL` alone is what tells an orphaned
 * draft-scoped hold apart from an operator-initiated (`order_id`-anchored)
 * one: the latter never sets `expires_at` at all (that migration's own doc
 * block — "only draft-scoped `held` rows ever set it").
 *
 * `AGENTS.md` §Queue and event reliability: "Consumers are idempotent" —
 * satisfied two ways here for EACH candidate source: the
 * `activeForDraftId()`/`activeForPlotId()` re-derivation skips most
 * already-resolved rows outright, and the remaining `ExpirePlotReservation`
 * call is itself idempotent against a row that moved on in the brief
 * window between that re-derivation and this write (a genuine concurrent
 * run) — it throws `PlotReservationTransitionException`, caught and
 * skipped below. The two sources can never name the same row twice (one
 * requires `booking_draft_id` non-null, the other requires it null), so
 * there is nothing to de-duplicate between them.
 */
final readonly class PlotReservationExpiryScheduler
{
    public function __construct(private ExpirePlotReservation $expirePlotReservation) {}

    /**
     * @return Collection<int, PlotReservation> the rows actually expired by
     *                                          this run.
     */
    public function expireStaleDraftHolds(?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $expired = new Collection;

        $candidateDraftIds = PlotReservation::query()
            ->whereNotNull('booking_draft_id')
            ->where('state', PlotReservationState::HELD)
            ->where('expires_at', '<', $now)
            ->where('expires_at', '>', $now->copy()->subDay())
            ->distinct()
            ->pluck('booking_draft_id');

        foreach ($candidateDraftIds as $draftId) {
            // Deliberately not gated on the draft still existing: a draft
            // deleted after its plot hold went stale (e.g. by
            // `PurgeStaleBookingDrafts`) still needs that hold released —
            // see `activeForDraftId()`'s own doc block. The wider gap this
            // alone does not close (`booking_draft_id` already nulled
            // before this candidate query even runs) is what the second
            // pass below exists for.
            $this->expireIfStillDue(PlotReservation::activeForDraftId($draftId), $expired);
        }

        $candidatePlotIds = PlotReservation::query()
            ->whereNull('booking_draft_id')
            ->where('state', PlotReservationState::HELD)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->where('expires_at', '>', $now->copy()->subDay())
            ->distinct()
            ->pluck('plot_id');

        foreach ($candidatePlotIds as $plotId) {
            $this->expireIfStillDue(PlotReservation::activeForPlotId($plotId), $expired);
        }

        return $expired;
    }

    /**
     * Shared per-candidate guard for both passes above: re-check the
     * incumbent is genuinely still a stale `held` hold before acting, and
     * isolate a row that moved on between the candidate query and this
     * write so one candidate can never starve the rest of a real sweep.
     */
    private function expireIfStillDue(?PlotReservation $head, Collection $expired): void
    {
        if (
            $head === null
            || $head->state !== PlotReservationState::HELD
            || $head->expires_at === null
            || ! $head->expires_at->isPast()
        ) {
            // Not a real candidate — the incumbent-of-the-latest-row logic
            // says this chain has already moved on (converted/expired/
            // released) or its current head is not actually stale.
            return;
        }

        try {
            $expired->push(($this->expirePlotReservation)($head, 'system', 'system'));
        } catch (PlotReservationTransitionException) {
            // Moved on between the re-derivation above and this write (a
            // genuine concurrent run) — nothing to do, and not an error.
        }
    }
}
