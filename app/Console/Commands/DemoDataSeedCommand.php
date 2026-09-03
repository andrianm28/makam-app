<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Models\DemoDataBatch;
use App\Models\User;
use App\Platform\Notification\DemoDataSuppression;
use App\Platform\Outbox\OutboxPublisher;
use App\Support\ExampleData\BookingOrderExampleData;
use App\Support\ExampleData\CareSubscriptionExampleData;
use App\Support\ExampleData\CemeteryOperatorExampleData;
use App\Support\ExampleData\CertificateExampleData;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;
use App\Support\ExampleData\DemoContactData;
use App\Support\ExampleData\MarketplaceOrderExampleData;
use App\Support\ExampleData\RenewalExampleData;
use App\Support\ExampleData\VendorAccountExampleData;
use App\Support\ExampleData\VisitationExampleData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates every demo generator (Tasks 4-9) in dependency order, inside
 * one call to `DemoDataSuppression::run()`, with `queue.default` forced to
 * `sync` and the outbox drained in-process before the run ends — see the
 * comment above `handle()`'s body for why all three are necessary together
 * (a real finding from Task 3's own implementation: a domain write alone
 * never synchronously fires `OutboxEventPublished`, so without the
 * sync-forcing and the drain, the suppression guard is correctly placed
 * but unreachable on a real host). Each domain runs in its own DB::transaction()
 * so one domain's failure never corrupts an earlier domain's already-
 * committed data — see docs/superpowers/specs/2026-09-03-demo-seed-data-design.md
 * §Error handling.
 *
 * NEVER run this against anything but a disposable local/test database.
 * This command has no way to positively verify the database it's pointed
 * at is safe — that responsibility belongs to whoever invokes it, per this
 * plan's own Global Constraints and the spec's decision 5 (running this
 * against the live beta host is a separate, explicitly-confirmed action,
 * never bundled with merging the PR that adds this command).
 */
final class DemoDataSeedCommand extends Command
{
    protected $signature = 'demo-data:seed';

    protected $description = 'Seed realistic, safely-tagged demo data across every major journey for a live demo.';

    public function handle(): int
    {
        $batchId = (string) Str::uuid();
        $summary = [];

        // Real finding from Task 3's implementer, confirmed independently
        // (2026_09_03): a domain write does NOT synchronously fire
        // OutboxEventPublished. It only writes an outbox_events row;
        // `PublishOutboxEventJob` — the thing that actually fires that
        // event — is itself `ShouldQueue`, dispatched only by
        // `OutboxPublisher::publishBatch()`, whose only real caller is the
        // `outbox:publish` scheduled command running EVERY MINUTE IN A
        // SEPARATE PROCESS. On beta, `QUEUE_CONNECTION=database` (real,
        // async) — so even calling `publishBatch()` from inside this
        // command would just enqueue the job for a separate `queue:work`
        // worker to pick up later, in a process where
        // `DemoDataSuppression::active()` has already gone back to false.
        // Task 3's guard is correctly placed, but nothing reaches it at
        // all on beta without this fix: force the queue driver to `sync`
        // for the whole run (so every job this run dispatches — including
        // PublishOutboxEventJob, ConsumeOutboxNotificationJob if the guard
        // were ever bypassed, and anything else in the chain — executes
        // immediately, in-process, in the SAME process the suppression
        // flag is true in), and drain the outbox ourselves rather than
        // waiting for the scheduler.
        $originalQueueDriver = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            DemoDataSuppression::run(function () use ($batchId, &$summary): void {
                $summary['vendor_accounts'] = $this->runDomain('vendor accounts', function () use ($batchId) {
                    return VendorAccountExampleData::seed($batchId);
                });
                $vendor = $summary['vendor_accounts']['vendors'][0];

                $cemetery = Cemetery::query()->firstOrFail();

                $summary['cemetery_operator'] = $this->runDomain('cemetery operator', function () use ($batchId, $cemetery) {
                    return CemeteryOperatorExampleData::seed($batchId, $cemetery->id);
                });

                $summary['booking_orders'] = $this->runDomain('booking orders', function () use ($batchId) {
                    return BookingOrderExampleData::seed($batchId);
                });

                // Task 6's own generator requires a non-null `due_date` AND a
                // fully-priced parent cemetery (`price_min`, `price_source`,
                // `price_effective_at` all non-null) — that is what
                // `QuoteRenewal` actually reads, and it throws otherwise. The
                // narrower `whereNotNull('due_date')` alone (this query's
                // first draft) can select a grave whose cemetery has no
                // price data, which fails inside `OpenRenewal`/`QuoteRenewal`
                // rather than qualifying. Confirmed against Task 6's own
                // merged generator and its test
                // (tests/Feature/Support/ExampleData/RenewalExampleDataTest.php),
                // which qualifies grave records the same way.
                $graveRecords = GraveRecord::query()
                    ->whereNotNull('due_date')
                    ->whereHas('cemetery', function ($query): void {
                        $query->whereNotNull('price_min')
                            ->whereNotNull('price_source')
                            ->whereNotNull('price_effective_at');
                    })
                    ->orderBy('id')
                    ->limit(3)
                    ->get()
                    ->all();

                $summary['renewals'] = $this->runDomain('renewals', function () use ($batchId, $graveRecords) {
                    return RenewalExampleData::seed($batchId, $graveRecords);
                });

                $summary['marketplace_orders'] = $this->runDomain('marketplace orders', function () use ($batchId, $vendor) {
                    return MarketplaceOrderExampleData::seed($batchId, $vendor);
                });

                // A DEDICATED customer user, not an arbitrary demo_batch_id-tagged
                // row — by this point Task 4 has already tagged 3 users (2 vendor
                // accounts + 1 cemetery operator) with this same batch id, so
                // `User::where('demo_batch_id', $batchId)->firstOrFail()` would
                // non-deterministically hand one of THOSE personas to
                // CareSubscriptionExampleData as "the customer". Found during
                // this skill's own pre-flight cross-task scan, fixed before any
                // implementer touched it.
                $customer = User::query()->create([
                    'name' => DemoContactData::personName(300),
                    'email' => DemoContactData::email(300),
                    'password' => Hash::make('DemoContoh2026!'),
                ]);
                TaggedAsDemoData::tag($customer, $batchId);

                $grave = $graveRecords[0] ?? GraveRecord::query()->firstOrFail();
                $summary['care_subscriptions'] = $this->runDomain('care subscriptions', function () use ($batchId, $customer, $grave) {
                    return CareSubscriptionExampleData::seed($batchId, $customer->id, $grave->id);
                });

                $dibayarOrder = $summary['booking_orders'][2]; // index 2 = DIBAYAR, per Task 5's 5-state ordering
                $summary['certificates'] = $this->runDomain('certificates', function () use ($batchId, $dibayarOrder) {
                    return CertificateExampleData::seed($batchId, $dibayarOrder);
                });

                $summary['visitation'] = $this->runDomain('visitation', function () use ($batchId, $cemetery) {
                    return VisitationExampleData::seed($batchId, $cemetery);
                });

                // Drain the outbox OURSELVES, in-process, still inside the
                // suppression window — see the comment above handle()'s start
                // for why this is load-bearing, not defensive. Loop until a
                // batch comes back empty rather than trusting one call: this
                // run's own record count is small (well under the 50-row
                // default batch size) but looping is what actually GUARANTEES
                // full drainage regardless of volume, rather than assuming it.
                $publisher = new OutboxPublisher;
                while ($publisher->publishBatch() > 0) {
                    // keep draining
                }
            });
        } finally {
            config(['queue.default' => $originalQueueDriver]);
        }

        DemoDataBatch::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => $batchId,
            'summary' => array_map(static fn ($v) => is_array($v) ? count($v) : 1, $summary),
            'created_at' => now(),
        ]);

        $this->info("Demo data seeded. Batch id: {$batchId}");
        $this->table(['Domain', 'Result'], array_map(
            static fn (string $domain, mixed $result): array => [$domain, is_array($result) ? 'seeded' : 'seeded'],
            array_keys($summary),
            $summary,
        ));

        return self::SUCCESS;
    }

    private function runDomain(string $label, callable $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (Throwable $e) {
            $this->error("Domain [{$label}] failed: {$e->getMessage()}");

            throw $e;
        }
    }
}
