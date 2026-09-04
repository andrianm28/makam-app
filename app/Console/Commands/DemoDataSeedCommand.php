<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
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
 *
 * ---------------------------------------------------------------------------
 * Draining the outbox NEVER calls `OutboxPublisher::publishBatch()` — a
 * real live-host data-safety finding, not a style choice
 * ---------------------------------------------------------------------------
 * `publishBatch()`'s claim query (`OutboxPublisher::CLAIM_QUERY`) selects
 * ANY unclaimed `outbox_events` row in the whole table — on the live beta
 * host, real customer events land in that table continuously (the
 * scheduled `outbox:publish` job runs `everyMinute()`, concurrently with
 * any seed run). Calling `publishBatch()` here would claim those real rows
 * too, execute them synchronously inside `DemoDataSuppression::run()`, hit
 * the suppression guard (which correctly no-ops), and still leave
 * `dispatched_at` stamped on the row — so the real scheduled job would
 * never pick it up again. Net effect, if this were ever reintroduced:
 * silent, permanent loss of a real customer notification. See
 * `DemoDataSuppression`'s own doc block for the exact split between what
 * that flag guarantees (a listener-level no-op) and what it does NOT
 * (which rows a drain touches) — this command owns the latter.
 *
 * `drainThisRunsOutboxEvents()` instead publishes only rows it can
 * positively correlate to this run's own `demo_batch_id`-tagged aggregates:
 * every aggregate type a generator invoked by this command can write an
 * outbox row for (`booking_draft`, `order`, `renewal`, `certificate`,
 * `agreement`, `marketplace_order`, `vendor_order`, `visitation_booking` —
 * confirmed by reading every `Outbox::record()` call site reachable from
 * this command's domains) maps 1:1 onto an already-tagged table, so
 * `(aggregate_type, aggregate_id)` pairs pulled from those tables are a
 * real correlation, not a guess. A UUIDv7 high-water mark captured before
 * any domain runs (`outbox_events.id` is time-ordered, so `id > $floor`
 * excludes anything that existed before this run started) is a second,
 * independent layer — belt-and-suspenders, since a real row can never
 * match this run's own aggregate ids regardless. If a real event somehow
 * DID match both filters (it cannot, by construction — ids are globally
 * unique), the correct behaviour is to leave it alone: this method
 * deliberately UNDER-publishes rather than over-claims. Any demo event it
 * misses is picked up by the real scheduled `outbox:publish` job within a
 * minute of this command exiting — harmless, since every demo contact
 * value is a reserved, non-deliverable `@example.*`/`0811-8990-*` value
 * (`DemoContactData`'s own doc block).
 */
final class DemoDataSeedCommand extends Command
{
    protected $signature = 'demo-data:seed';

    protected $description = 'Seed realistic, safely-tagged demo data across every major journey for a live demo.';

    public function handle(): int
    {
        $batchId = (string) Str::uuid();
        $summary = [];

        // I2: write (and print) the batch id BEFORE any domain runs, not
        // only after every domain succeeds. `runDomain()` re-throws on
        // failure, so without this the exception would escape `handle()`
        // before `DemoDataBatch::create()` ever ran — leaving earlier,
        // already-committed domains tagged with a batch id that appears
        // nowhere (not in `demo_data_batches`, not in stdout), which
        // `demo-data:purge`'s no-argument "most recent batch" lookup can
        // never find. `summary` starts null and is filled in by the
        // `update()` at the bottom, once every domain has actually
        // succeeded.
        DemoDataBatch::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => $batchId,
            'summary' => null,
            'created_at' => now(),
        ]);
        $this->info("Demo data batch id: {$batchId} (run `demo-data:purge {$batchId} --force` to remove it, even if this command fails partway through)");

        // C1: a high-water mark captured before any domain writes an
        // outbox_events row — see this class's own doc block,
        // "Draining the outbox NEVER calls OutboxPublisher::publishBatch()",
        // for the full mechanism and why this is only one of two layers.
        // Not `->max('id')`: Postgres has no built-in MAX() aggregate for
        // the `uuid` type (confirmed by running this against real
        // Postgres — "function max(uuid) does not exist"), even though
        // `uuid` fully supports the `<`/`>` comparison `ORDER BY` needs.
        $outboxFloorId = DB::table('outbox_events')->orderByDesc('id')->value('id')
            ?? '00000000-0000-0000-0000-000000000000';

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
            DemoDataSuppression::run(function () use ($batchId, $outboxFloorId, &$summary): void {
                $summary['vendor_accounts'] = $this->runDomain('vendor accounts', function () use ($batchId) {
                    return VendorAccountExampleData::seed($batchId);
                });
                $vendor = $summary['vendor_accounts']['vendors'][0];

                // C2: a DEDICATED demo cemetery, never an arbitrary real
                // one. `Cemetery::query()->firstOrFail()` used to adopt
                // whatever cemetery Postgres happened to return first —
                // including one of the migration-seeded real example
                // cemeteries already live on beta — and this run's own
                // `VisitationExampleData` call below then tagged (and a
                // later purge would delete) whatever policy already existed
                // on it. See `createDemoCemetery()`'s own doc block.
                $cemetery = $this->createDemoCemetery($batchId);

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
                    'password' => Hash::make(DemoContactData::DEMO_PASSWORD),
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
                // for why draining at all is load-bearing, not defensive,
                // and this class's own top-of-file doc block for why this
                // NEVER calls the shared, unscoped `publishBatch()`.
                $this->drainThisRunsOutboxEvents($batchId, $outboxFloorId);
            });
        } finally {
            config(['queue.default' => $originalQueueDriver]);
        }

        DemoDataBatch::query()->where('batch_id', $batchId)->update([
            'summary' => array_map(static fn ($v) => is_array($v) ? count($v) : 1, $summary),
        ]);

        $this->info("Demo data seeded. Batch id: {$batchId}");
        $this->table(['Domain', 'Result'], array_map(
            static fn (string $domain): array => [$domain, 'seeded'],
            array_keys($summary),
        ));

        return self::SUCCESS;
    }

    /**
     * See this class's own doc block ("Draining the outbox NEVER calls
     * OutboxPublisher::publishBatch()") for the full reasoning. Publishes
     * only `outbox_events` rows whose `(aggregate_type, aggregate_id)`
     * matches an entity THIS batch tagged, and whose `id` is newer than the
     * pre-run floor — never the unscoped shared claim.
     */
    private function drainThisRunsOutboxEvents(string $batchId, string $outboxFloorId): void
    {
        $aggregateIdsByType = [
            'booking_draft' => DB::table('booking_drafts')->where('demo_batch_id', $batchId)->pluck('id'),
            'order' => DB::table('orders')->where('demo_batch_id', $batchId)->pluck('id'),
            'renewal' => DB::table('renewals')->where('demo_batch_id', $batchId)->pluck('id'),
            'certificate' => DB::table('certificates')->where('demo_batch_id', $batchId)->pluck('id'),
            'agreement' => DB::table('agreements')->where('demo_batch_id', $batchId)->pluck('id'),
            'marketplace_order' => DB::table('marketplace_orders')->where('demo_batch_id', $batchId)->pluck('id'),
            'vendor_order' => DB::table('vendor_orders')->where('demo_batch_id', $batchId)->pluck('id'),
            'visitation_booking' => DB::table('visitation_bookings')->where('demo_batch_id', $batchId)->pluck('id'),
        ];

        $ids = DB::table('outbox_events')
            ->whereNull('dispatched_at')
            ->where('id', '>', $outboxFloorId)
            ->where(function ($query) use ($aggregateIdsByType): void {
                foreach ($aggregateIdsByType as $aggregateType => $aggregateIds) {
                    if ($aggregateIds->isEmpty()) {
                        continue;
                    }

                    $query->orWhere(function ($inner) use ($aggregateType, $aggregateIds): void {
                        $inner->where('aggregate_type', $aggregateType)
                            ->whereIn('aggregate_id', $aggregateIds->map(static fn ($id): string => (string) $id));
                    });
                }
            })
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($ids === []) {
            return;
        }

        // Loop until a call returns 0 rather than trusting one call — the
        // same "guarantee full drainage regardless of volume" discipline
        // the pre-fix single-call drain already established, now scoped.
        $publisher = new OutboxPublisher;
        while ($publisher->publishSpecificIds($ids) > 0) {
            // keep draining
        }
    }

    /**
     * A cemetery this run creates and tags itself, never an existing one —
     * see the call site's own comment and this class's top-of-file doc
     * block for why adopting an arbitrary real `Cemetery` row is unsafe.
     * `2026_09_03_150000_add_demo_batch_id_for_demo_seed_data.php` added
     * `demo_batch_id` to `cemeteries` specifically for this: "any NEW
     * demo-specific cemetery this subsystem creates (for the
     * cemetery-operator account's scope grant) is independently purgeable".
     */
    private function createDemoCemetery(string $batchId): Cemetery
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh Demo',
            'slug' => "tpu-contoh-demo-{$batchId}",
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh Demo No. 1, Jakarta',
            'published_at' => now(),
        ]);
        TaggedAsDemoData::tag($cemetery, $batchId);

        return $cemetery;
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
