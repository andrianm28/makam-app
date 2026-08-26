<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\OrderWorkflow\QuoteExpiryScheduler;
use Illuminate\Console\Command;

/**
 * `php artisan orders:expire-stale-quotes`
 *
 * Read-model honesty only — see `QuoteExpiryScheduler`'s own doc block.
 * Writes `KEDALUWARSA` on an order whose current quote's `expires_at` has
 * passed while the order still shows as awaiting the buyer or awaiting
 * payment; the payment guard and `Quote::accept()` already refuse an
 * expired quote on their own, live, regardless of whether this command has
 * run.
 *
 * Scheduled hourly in `routes/console.php` — no financial decision depends
 * on the frequency, so hourly is chosen purely to keep the admin/customer
 * status display from drifting far from reality.
 *
 * Idempotent: `QuoteExpiryScheduler` silently skips an order that has
 * already moved on since it was queried (paid, cancelled, rejected, or
 * re-quoted), so re-running this command changes nothing beyond what is
 * still genuinely due.
 *
 * Deliberately does NOT log or print anything beyond a count — same
 * `AGENTS.md` §Observability discipline `booking:purge-stale-drafts` and
 * `care:generate-cycles` already follow: no order reference, customer name,
 * or other order content in command output.
 */
final class OrdersExpireStaleQuotesCommand extends Command
{
    protected $signature = 'orders:expire-stale-quotes';

    protected $description = 'Write KEDALUWARSA on orders whose current quote has expired, for read-model honesty only.';

    public function handle(QuoteExpiryScheduler $scheduler): int
    {
        $expired = $scheduler->expireDueOrders();

        $this->info("Expired {$expired->count()} order(s) with a stale quote.");

        return self::SUCCESS;
    }
}
