<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\FuneralCase\Models\FuneralCase;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PreNeed\Models\PreNeedInterest;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes abandoned booking drafts once they pass the retention window.
 *
 * A draft accumulates customer and deceased PII from Step 6 onward. Once the
 * visitor walks away, that data has no further purpose, and an unbounded
 * `booking_drafts` table becomes a growing store of personal data nobody
 * asked us to keep. This Action is the deletion half of that lifecycle.
 *
 * "Stale" is measured on `updated_at`, not `created_at`: a draft someone is
 * still slowly working through has recent activity and must survive, however
 * long ago it was first opened.
 *
 * The count, never the content, is audited — a deletion should be
 * accountable, but the audit trail must not become the very copy of the PII
 * the purge exists to remove.
 *
 * Excludes drafts an `Order`, `FuneralCase`, or `PreNeedInterest` still
 * references (finding DOM-01, 6 Sep 2026 audit). Those three tables'
 * `booking_draft_id` FK is `nullOnDelete` and its own migration doc block
 * calls it "the convenience link back to its originating draft" — but that
 * convenience is not optional in practice: `IssueOrderQuote` reads
 * `$order->bookingDraft` and throws `InvalidArgumentException` when it is
 * null, and `PreNeed\Actions\QuotePreNeed` has the identical shape. Once the
 * draft is gone, the order/interest becomes permanently unquotable — this
 * purge must not create that state. `PlotReservation` is deliberately NOT
 * in this list: a reservation's own `nullOnDelete` link severs cleanly with
 * no functional loss (the reservation chain is append-only history, not a
 * live read dependency), which is the behaviour
 * `PurgeStaleBookingDraftsTest::test_a_stale_draft_with_a_live_plot_hold_is_
 * still_purged` already pins.
 */
final readonly class PurgeStaleBookingDrafts
{
    public function __invoke(int $retentionDays, bool $dryRun = false): int
    {
        $cutoff = Carbon::now()->subDays($retentionDays);

        // Same subquery-against-the-far-side shape as
        // `BookingDraftQuery::openForUser()`, for the same reason: a
        // `BookingDraft::order()`/`funeralCase()`/`preNeedInterest()`
        // inverse relation would create a cross-domain model cycle between
        // `Domain\Booking` and `Domain\OrderWorkflow`/`Domain\FuneralCase`/
        // `Domain\PreNeed`.
        $query = BookingDraft::query()
            ->where('updated_at', '<', $cutoff)
            ->whereNotIn('id', Order::query()->whereNotNull('booking_draft_id')->select('booking_draft_id'))
            ->whereNotIn('id', FuneralCase::query()->whereNotNull('booking_draft_id')->select('booking_draft_id'))
            ->whereNotIn('id', PreNeedInterest::query()->whereNotNull('booking_draft_id')->select('booking_draft_id'));

        if ($dryRun) {
            return $query->count();
        }

        return DB::transaction(function () use ($query, $cutoff): int {
            $deleted = $query->delete();

            if ($deleted > 0) {
                Audit::record(
                    action: 'BOOKING_DRAFTS_PURGED',
                    // No single draft is the subject — this is a sweep. The
                    // window is the identifying fact worth keeping, and it
                    // carries no personal data.
                    subject: new AuditSubject('booking_draft_sweep', $cutoff->toDateString(), $deleted),
                    outcome: AuditOutcome::Allowed,
                    actorRef: null,
                    actorRole: 'system',
                    source: AuditSource::Console,
                );
            }

            return $deleted;
        });
    }
}
