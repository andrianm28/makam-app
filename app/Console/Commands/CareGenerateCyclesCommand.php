<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CareSubscription\CycleScheduler;
use Illuminate\Console\Command;

/**
 * `php artisan care:generate-cycles`
 *
 * Finds ACTIVE subscriptions where the next cycle is due and generates them.
 * Scheduled daily at 03:30 in `routes/console.php`.
 *
 * Idempotent: GenerateCycle handles dedup via the unique constraint.
 *
 * Deliberately does NOT log or print any PII — only counts. `AGENTS.md`
 * §Observability: never place restricted data in logs.
 */
final class CareGenerateCyclesCommand extends Command
{
    protected $signature = 'care:generate-cycles';

    protected $description = 'Generate due subscription cycles for active care subscriptions.';

    public function handle(CycleScheduler $scheduler): int
    {
        $cycles = $scheduler->generateDueCycles();

        $count = $cycles->count();

        $this->info("Generated {$count} cycle(s) for due subscriptions.");

        return self::SUCCESS;
    }
}
