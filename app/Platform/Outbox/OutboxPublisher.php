<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * AC5/AC6/AC8: the claim/publish/retry loop. design.md's own publish-loop
 * pseudocode (NOT part of the column-name conflict this batch's migration
 * doc block resolves — followed as-is):
 *
 *   claim batch (atomic: ... SELECT ... FOR UPDATE SKIP LOCKED)
 *     -> dispatch to queue per event_type routing
 *     -> mark published
 *     -> on failure: release claim, increment attempts, bounded backoff
 *
 * ---------------------------------------------------------------------------
 * `SKIP LOCKED` requires raw SQL — verified, not assumed
 * ---------------------------------------------------------------------------
 * Laravel's query builder has `->lockForUpdate()` (plain `FOR UPDATE`) but,
 * as of Laravel 13, no query-builder method for `SKIP LOCKED` — confirmed
 * by inspecting `Illuminate\Database\Query\Builder` and
 * `Illuminate\Database\Concerns\BuildsQueries`: `lockForUpdate()` only ever
 * emits `FOR UPDATE`, with no parameter or variant for the `SKIP LOCKED`
 * suffix. `self::CLAIM_QUERY` below is therefore raw SQL via `DB::select()`,
 * as the batch brief anticipated.
 *
 * ---------------------------------------------------------------------------
 * Postgres only — this is a hard requirement, not a preference
 * ---------------------------------------------------------------------------
 * `claim()` throws immediately if the active connection is not `pgsql`.
 * SQLite has no row-level locking and no `FOR UPDATE`/`SKIP LOCKED` syntax
 * at all — this repo's default local test environment
 * (`phpunit.xml`: `DB_CONNECTION=sqlite`) would otherwise fail with a
 * confusing SQL syntax error instead of a clear message. CI overrides to
 * `DB_CONNECTION=pgsql` (`.github/workflows/ci.yml`), matching production
 * and staging (ADR-0021) — the same environment split
 * `audit_events`' CHECK-constraint guard already documents.
 *
 * ---------------------------------------------------------------------------
 * Stale-claim reclaim
 * ---------------------------------------------------------------------------
 * A row whose `locked_at` is older than `STALE_CLAIM_SECONDS` is treated as
 * a crashed publisher's abandoned claim and becomes reclaimable again —
 * design.md: "A stale claim (crashed publisher) is reclaimed after a
 * timeout — which is why consumers must be idempotent." 5 minutes is a
 * judgement call (the batch brief's own suggested figure), not sourced from
 * any cited document — a future batch with real operational data should
 * revisit it.
 */
final class OutboxPublisher
{
    public const int DEFAULT_BATCH_SIZE = 50;

    public const int STALE_CLAIM_SECONDS = 300;

    /**
     * AC6 "bounded backoff": exponential with a hard cap, no unbounded
     * growth. `min(300, 5 * 2^min(attempt, 6))` — 5s, 10s, 20s, 40s, 80s,
     * 160s, then capped at 300s (5 minutes) for every attempt after the
     * 6th. Judgement call, not sourced from a specific figure in any cited
     * document (`queue-and-outbox.md` §8 says "bounded exponential backoff
     * with jitter" for EXTERNAL providers specifically; no jitter is added
     * here since this schedules an internal reclaim window, not a call to
     * an external system with its own concurrent-retry stampede risk).
     */
    public const int BASE_BACKOFF_SECONDS = 5;

    public const int MAX_BACKOFF_SECONDS = 300;

    /**
     * The exact query the claim step runs. A single source of truth so
     * tests exercising real Postgres locking semantics can run the
     * identical SQL this class uses, rather than a hand-copied
     * approximation that could silently drift from it.
     */
    public const string CLAIM_QUERY = <<<'SQL'
        SELECT id FROM outbox_events
        WHERE dispatched_at IS NULL
          AND (locked_at IS NULL OR locked_at < ?)
          AND available_at <= ?
        ORDER BY occurred_at ASC
        LIMIT ?
        FOR UPDATE SKIP LOCKED
        SQL;

    /**
     * Claims up to `$batchSize` unclaimed (or stale-claimed) rows, routes
     * and dispatches each to its queue, and marks success/failure. Returns
     * the number of rows claimed (dispatched successfully or not — a
     * failure still counts as "handled this pass," just not published).
     */
    public function publishBatch(int $batchSize = self::DEFAULT_BATCH_SIZE): int
    {
        $ids = $this->claim($batchSize);

        foreach ($ids as $id) {
            $this->dispatchOne($id);
        }

        return count($ids);
    }

    /**
     * A scoped variant of `publishBatch()` for a caller that already knows
     * exactly which rows it is allowed to touch (`demo-data:seed` — see
     * `DemoDataSeedCommand`'s own doc block for why the unscoped
     * `publishBatch()`/`CLAIM_QUERY` claim is unsafe for that caller: it
     * would claim ANY unclaimed row in the table, including a real
     * customer's concurrently-arriving event). Still claims via
     * `SELECT ... FOR UPDATE SKIP LOCKED` — restricted to `$ids` — so this
     * never double-dispatches against the real `outbox:publish` scheduled
     * command running concurrently. Returns the number of rows claimed
     * (dispatched or not, same contract as `publishBatch()`).
     *
     * @param  list<string>  $ids
     */
    public function publishSpecificIds(array $ids, int $batchSize = self::DEFAULT_BATCH_SIZE): int
    {
        if ($ids === []) {
            return 0;
        }

        $claimedIds = $this->claimSpecific($ids, $batchSize);

        foreach ($claimedIds as $id) {
            $this->dispatchOne($id);
        }

        return count($claimedIds);
    }

    /**
     * @return list<string>
     */
    private function claim(int $batchSize): array
    {
        $this->assertPgsql();

        $staleBefore = CarbonImmutable::now()->subSeconds(self::STALE_CLAIM_SECONDS);
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($batchSize, $staleBefore, $now): array {
            $rows = DB::select(self::CLAIM_QUERY, [$staleBefore, $now, $batchSize]);

            $ids = array_map(static fn (object $row): string => (string) $row->id, $rows);

            if ($ids !== []) {
                DB::table('outbox_events')->whereIn('id', $ids)->update([
                    'locked_at' => $now,
                ]);
            }

            return $ids;
        });
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function claimSpecific(array $ids, int $batchSize): array
    {
        $this->assertPgsql();

        $now = CarbonImmutable::now();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return DB::transaction(function () use ($ids, $batchSize, $now, $placeholders): array {
            $rows = DB::select(
                <<<SQL
                    SELECT id FROM outbox_events
                    WHERE id IN ({$placeholders})
                      AND dispatched_at IS NULL
                    ORDER BY occurred_at ASC
                    LIMIT ?
                    FOR UPDATE SKIP LOCKED
                    SQL,
                [...$ids, $batchSize],
            );

            $claimedIds = array_map(static fn (object $row): string => (string) $row->id, $rows);

            if ($claimedIds !== []) {
                DB::table('outbox_events')->whereIn('id', $claimedIds)->update([
                    'locked_at' => $now,
                ]);
            }

            return $claimedIds;
        });
    }

    private function assertPgsql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'OutboxPublisher requires a real row-locking RDBMS (Postgres) for '.
                'SELECT ... FOR UPDATE SKIP LOCKED. Active connection driver is ['.
                DB::connection()->getDriverName().']. Run with DB_CONNECTION=pgsql — see '.
                'this class\'s own doc block.'
            );
        }
    }

    private function dispatchOne(string $id): void
    {
        $row = OutboxEvent::query()->find($id);

        if ($row === null) {
            return;
        }

        try {
            $queue = OutboxQueueRouter::routeFor($row->event_name);

            PublishOutboxEventJob::dispatch($row->getKey())->onQueue($queue->value);

            $row->forceFill([
                'dispatched_at' => CarbonImmutable::now(),
                'locked_at' => null,
            ])->save();
        } catch (Throwable $exception) {
            $attempt = $row->attempt_count + 1;

            $row->forceFill([
                'locked_at' => null,
                'attempt_count' => $attempt,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'available_at' => CarbonImmutable::now()->addSeconds(self::backoffSeconds($attempt)),
            ])->save();
        }
    }

    public static function backoffSeconds(int $attempt): int
    {
        return min(self::MAX_BACKOFF_SECONDS, self::BASE_BACKOFF_SECONDS * (2 ** min($attempt, 6)));
    }
}
