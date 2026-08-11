<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Notification\Actions\DispatchNotification;
use App\Platform\Notification\Exceptions\NotificationDeliveryWriteNotAllowedException;
use Illuminate\Support\Facades\DB;

/**
 * Runtime enforcement of AC9 ("`DispatchNotification` is the only class
 * that writes `notification_deliveries`") — fix round 1, IMPORTANT 1. The
 * original guard test was a static regex scan over `app/`; the reviewer
 * demonstrated seven ways to write the table that regex could not see
 * (direct `DB::table()` insert, `->update()`, `->forceFill()->save()`,
 * `upsert()`, `delete()` — including the single-arrow Eloquent instance
 * form `Actions\DispatchNotification` itself uses). A static scan of PHP
 * source cannot enumerate every syntactic way to reach the same SQL, so
 * this replaces it with a mechanism that inspects the SQL actually sent to
 * the database, which every write path — Eloquent or the raw query
 * builder, single-arrow or chained — must produce.
 *
 * ---------------------------------------------------------------------------
 * Why a connection pre-execution hook, not an Eloquent model event
 * ---------------------------------------------------------------------------
 * Eloquent's `creating`/`updating`/`deleting` events do NOT fire for bulk
 * operations (`Builder::insert()`/`insertOrIgnore()`/`update()` used as a
 * query-builder call, or any `DB::table()` call) — only for a single
 * model's `save()`/`delete()`. `Actions\DispatchNotification` itself
 * writes via `DB::table('notification_deliveries')->insertOrIgnore(...)`
 * for its bulk insert, so a model-event guard would not even see its OWN
 * legitimate write, let alone a bypass using the same bulk form.
 * Laravel's connection `beforeExecuting()` hook fires for every query before
 * it reaches PDO, regardless of which API produced it, which is the actual
 * invariant AC9 needs enforced.
 *
 * ---------------------------------------------------------------------------
 * What this does and does not prove
 * ---------------------------------------------------------------------------
 * The hook fires BEFORE a query executes, so an unauthorised write cannot
 * land and then report its violation. Every real write site in this lane
 * (`Actions\DispatchNotification::recordRecipientsAndDeliveries()`'s
 * `insertOrIgnore()`, `::claimDelivery()`'s claim update, and
 * `::recordChannelOutcome()`'s outcome update)
 * runs inside `withWritesUnlocked()`, and both also run inside a surrounding
 * `DB::transaction()` (the first directly; the second is a single
 * autocommit statement — see that method's own call site). An unauthorised
 * write attempted from anywhere else throws before execution and is
 * provable in a test
 * (`tests/Unit/Platform/Notification/NotificationDeliveryWriteGuardTest.php`),
 * which the prior regex test was not.
 */
final class NotificationDeliveryWriteGuard
{
    private const string TABLE = 'notification_deliveries';

    private static bool $unlocked = false;

    /**
     * @var array<int, true>
     */
    private static array $registeredConnections = [];

    /**
     * Idempotent per connection — safe to call from a service provider's
     * `boot()`, including repeated application bootstraps in one process.
     */
    public static function register(): void
    {
        $connection = DB::connection();
        $connectionId = spl_object_id($connection);

        if (isset(self::$registeredConnections[$connectionId])) {
            return;
        }

        self::$registeredConnections[$connectionId] = true;

        $connection->beforeExecuting(function (string $sql): void {
            if (self::$unlocked) {
                return;
            }

            if (preg_match(
                '/^\s*(?:insert(?:\s+or\s+\w+)?\s+into|update|delete\s+from)\s+["`\[]?'.self::TABLE.'["`\]]?/i',
                $sql
            ) === 1) {
                throw NotificationDeliveryWriteNotAllowedException::forSql($sql);
            }
        });
    }

    /**
     * Runs `$callback` with writes to `notification_deliveries` allowed.
     * Restores the PREVIOUS lock state afterward (not unconditionally
     * `false`) so a nested call — or a caller running inside an already-
     * unlocked scope — cannot accidentally re-lock an outer scope early.
     */
    public static function withWritesUnlocked(callable $callback): mixed
    {
        if (! self::calledFromDispatchNotification()) {
            throw NotificationDeliveryWriteNotAllowedException::forScope();
        }

        $previous = self::$unlocked;
        self::$unlocked = true;

        try {
            return $callback();
        } finally {
            self::$unlocked = $previous;
        }
    }

    private static function calledFromDispatchNotification(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) !== DispatchNotification::class) {
                continue;
            }

            return in_array($frame['function'] ?? null, [
                'claimDelivery',
                'recordRecipientsAndDeliveries',
                'recordChannelOutcome',
                'requeueFailedDelivery',
            ], true);
        }

        return false;
    }

    /**
     * Test-only escape hatch: forces the lock back to its default (locked)
     * state. `self::$unlocked` and `self::$registeredConnections` are process-global
     * static state — like `OutboxEvent::flushEventListeners()` elsewhere in
     * this codebase, a test that deliberately triggers a violation must
     * not leak `$unlocked = true` into a later, unrelated test.
     */
    public static function resetForTests(): void
    {
        self::$unlocked = false;
    }

    /**
     * Static-only class — never constructed.
     */
    private function __construct() {}
}
