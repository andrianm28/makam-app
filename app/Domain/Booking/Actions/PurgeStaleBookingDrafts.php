<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\BookingDraft;
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
 */
final readonly class PurgeStaleBookingDrafts
{
    public function __invoke(int $retentionDays, bool $dryRun = false): int
    {
        $cutoff = Carbon::now()->subDays($retentionDays);

        $query = BookingDraft::query()->where('updated_at', '<', $cutoff);

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
