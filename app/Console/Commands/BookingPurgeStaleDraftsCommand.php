<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Booking\Actions\PurgeStaleBookingDrafts;
use Illuminate\Console\Command;

/**
 * `php artisan booking:purge-stale-drafts {--days=} {--dry-run}`
 *
 * Retention for abandoned booking drafts. From Step 6 a draft holds customer
 * and deceased PII — full name, mobile, email, home address, dates of birth
 * and death. A draft that was never carried through to an order has no
 * remaining purpose, so keeping that PII indefinitely is retention without a
 * basis; this is the mechanism that ends it.
 *
 * Scheduled daily in `routes/console.php`. `--dry-run` reports the count it
 * WOULD delete and changes nothing, so the window can be checked against
 * real data before a first live run.
 *
 * Deliberately does NOT log or print any of the PII it removes — only
 * counts. `AGENTS.md` §Observability: never place restricted data in logs.
 */
final class BookingPurgeStaleDraftsCommand extends Command
{
    protected $signature = 'booking:purge-stale-drafts {--days= : Retention window in days; defaults to config booking.draft_retention_days} {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete abandoned booking drafts, and the personal data they hold, past the retention window.';

    public function handle(PurgeStaleBookingDrafts $purge): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('booking.draft_retention_days');

        if ($days < 1) {
            $this->error('The retention window must be at least 1 day.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $count = $purge($days, dryRun: $dryRun);

        $this->info($dryRun
            ? "Dry run: {$count} abandoned draft(s) older than {$days} day(s) would be deleted."
            : "Deleted {$count} abandoned draft(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
