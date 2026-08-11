<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Notification\Actions\DispatchNotification;
use App\Platform\Notification\Exceptions\NotificationDeliveryWriteNotAllowedException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use WeakMap;

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
     * Keyed by the connection object itself, NOT by `spl_object_id()`.
     *
     * PHP reuses an object id once the original object is collected, so
     * an id-keyed record could not distinguish "this connection is
     * already hooked" from "a destroyed connection once held this id".
     * A replacement connection handed a recycled id looked
     * already-registered, `register()` returned early, and that
     * connection carried no `beforeExecuting()` hook at all: every write
     * to `notification_deliveries` on it silently unguarded. The guard
     * failed OPEN, and only under whatever GC timing the surrounding
     * workload happened to produce.
     *
     * The trigger is repeated application bootstraps inside one process
     * — the test suite, which boots the providers afresh for every test,
     * and Octane, which is not installed here. It is specifically NOT a
     * plain queue worker: `Illuminate\Queue\Worker` never purges the
     * connection, and `DatabaseManager::reconnect()` swaps the PDO on
     * the same `Connection` object, so the hook survives ordinary
     * lost-connection recovery.
     *
     * A `WeakMap` keys on true object identity, which cannot collide,
     * and drops its entry when the connection is actually collected —
     * which is the real question being asked: "has THIS live connection
     * object been hooked?"
     *
     * Known gap, unchanged by this keying and tracked separately:
     * `register()` runs once per bootstrap from
     * `Providers\NotificationServiceProvider::boot()`, so nothing
     * re-attaches the hook if something purges the connection mid-process.
     * This makes re-registration correct whenever it happens; it does not
     * make it happen. Closing that needs a listener on
     * `Illuminate\Database\Events\ConnectionEstablished`, for which this
     * keying is the right substrate — repeated registration is safe and
     * self-cleaning.
     *
     * Not initialised inline: PHP's "new in initializers" does not
     * extend to property defaults, so it is built on first use.
     *
     * @var WeakMap<Connection, true>|null
     */
    private static ?WeakMap $registeredConnections = null;

    /**
     * Idempotent per connection — safe to call from a service provider's
     * `boot()`, including repeated application bootstraps in one process.
     */
    public static function register(): void
    {
        $connection = DB::connection();

        self::$registeredConnections ??= new WeakMap;

        if (isset(self::$registeredConnections[$connection])) {
            return;
        }

        self::$registeredConnections[$connection] = true;

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
     * state. `self::$unlocked` is process-global static state — like
     * `OutboxEvent::flushEventListeners()` elsewhere in this codebase, a
     * test that deliberately triggers a violation must not leak
     * `$unlocked = true` into a later, unrelated test.
     *
     * `self::$registeredConnections` deliberately is NOT cleared: it
     * holds live connection objects weakly and empties itself as they
     * are collected, so clearing it would only risk hooking a still-live
     * connection twice.
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
