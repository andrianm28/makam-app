<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation;

use App\Domain\Booking\Models\BookingDraft;
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
 * current hold via `PlotReservation::activeForDraft()` — the same
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
 * set — and the per-draft `activeForDraft()` re-derivation each candidate
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
 * `AGENTS.md` §Queue and event reliability: "Consumers are idempotent" —
 * satisfied two ways here: the `activeForDraft()` re-derivation above
 * skips most already-resolved rows outright, and the remaining
 * `ExpirePlotReservation` call is itself idempotent against a row that
 * moved on in the brief window between that re-derivation and this
 * write (a genuine concurrent run) — it throws
 * `PlotReservationTransitionException`, caught and skipped below.
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

        $candidateDraftIds = PlotReservation::query()
            ->whereNotNull('booking_draft_id')
            ->where('state', PlotReservationState::HELD)
            ->where('expires_at', '<', $now)
            ->where('expires_at', '>', $now->copy()->subDay())
            ->distinct()
            ->pluck('booking_draft_id');

        $expired = new Collection;

        foreach ($candidateDraftIds as $draftId) {
            $draft = BookingDraft::query()->find($draftId);

            if ($draft === null) {
                continue;
            }

            $head = PlotReservation::activeForDraft($draft);

            if (
                $head === null
                || $head->state !== PlotReservationState::HELD
                || $head->expires_at === null
                || ! $head->expires_at->isPast()
            ) {
                // Not a real candidate — `activeForDraft()`'s own
                // incumbent-of-the-latest-row logic says this chain has
                // already moved on (converted/expired/released) or its
                // current head is not actually stale. See class doc block.
                continue;
            }

            try {
                $expired->push(($this->expirePlotReservation)($head, 'system', 'system'));
            } catch (PlotReservationTransitionException) {
                // Moved on between the re-derivation above and this write
                // (a genuine concurrent run) — nothing to do, and not an
                // error. Isolated per row so one stale candidate can never
                // starve the rest of a real sweep.
                continue;
            }
        }

        return $expired;
    }
}
