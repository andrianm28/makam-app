<?php

declare(strict_types=1);

namespace App\Platform\Audit\Models;

use App\Platform\Audit\Exceptions\AuditRecordIsImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `audit_events`
 * (`2026_07_26_110000_create_audit_events_table.php`).
 *
 * ONLY ever construct/insert rows here via
 * `App\Platform\Audit\Audit::record()` (and its `Audit::wrap()`
 * wrapper) — never call `AuditEvent::create()`/`::insert()`/`save()`
 * directly from anywhere else in this codebase. `Audit::record()` is
 * the one place AC2's required-field list, AC3's sensitive-reason
 * check, and AC5's metadata allowlist are all enforced together;
 * constructing a row any other way skips all three silently. Same
 * "construct/call only through here" pattern as
 * `App\Platform\IdentityAccess\ActorContext` and `StatusIntent`.
 *
 * ---------------------------------------------------------------------------
 * AC1 (append-only) — what update()/performUpdate()/delete() below
 * actually guarantee, and what they do NOT
 * ---------------------------------------------------------------------------
 * UPDATED 7 Sep 2026 (OBS-01): the real, role-independent enforcement now
 * EXISTS — `2026_09_07_100000_enforce_audit_events_append_only.php` adds a
 * `BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW` PL/pgSQL trigger
 * (`reject_audit_history_mutation()`, `ERRCODE 42501`), the same mechanism
 * `journal_batches`/`journal_entries` already use. That trigger — not this
 * class — is the actual database-level control; it fires for every role,
 * every connection, and every write path, including the two the overrides
 * below cannot reach (see immediately below). PostgreSQL 18 only; SQLite
 * (the local/unit-test driver) has no equivalent, which is why
 * `tests/Feature/Audit/AuditEventAppendOnlyTest.php` runs its trigger
 * assertions against the real Postgres connection.
 *
 * The overrides below remain as application-level defense-in-depth: they
 * fail fast, in PHP, before a doomed statement ever reaches the database,
 * and they stop:
 *   - `$event->update([...])`
 *   - `$event->outcome = 'denied'; $event->save();` (the `exists ===
 *     true` path, via `performUpdate()`)
 *   - `$event->delete()`
 *
 * Previously (before the trigger existed) they did NOT stop, from PHP
 * alone, and the DATABASE now does:
 *   - `AuditEvent::query()->update([...])` / `AuditEvent::where(...)
 *     ->update([...])` — that goes through
 *     `Illuminate\Database\Eloquent\Builder`, not this `Model` class,
 *     so overriding `Model` methods never sees it. See
 *     `tests/Feature/Audit/AuditEventAppendOnlyTest.php` for a test
 *     that documents this exact gap rather than silently assuming it
 *     is closed.
 *   - `DB::table('audit_events')->update(...)` or any raw SQL —
 *     bypasses Eloquent entirely.
 *   - A second ORM/driver instance, a `psql` session, or any future
 *     service with direct database credentials.
 *
 * The trigger added by OBS-01 closes ALL of the paths above — it fires on
 * every `UPDATE`/`DELETE`, regardless of which query builder, ORM, driver,
 * or `psql` session issued it, and regardless of role (including the
 * owning/migration role). A `REVOKE`-based design.md control (see
 * `app/Platform/Audit/sql/revoke-audit-mutations.sql`, still NOT EXECUTED —
 * blocked on finding N-1: only one Postgres role per environment, which
 * both owns the database and runs the application) would be role-scoped and
 * by construction could never constrain that owning role; the trigger is
 * strictly stronger on that axis, and is a complement, not a substitute,
 * for the still-blocked `REVOKE` (a superuser can `ALTER TABLE ... DISABLE
 * TRIGGER`, which a `REVOKE` cannot be talked out of). See
 * `2026_09_07_100000_enforce_audit_events_append_only.php`'s own doc block
 * for the complete reasoning, mirrored from the journal migration's.
 */
final class AuditEvent extends Model
{
    /**
     * No `updated_at` — see the migration's doc block: this table
     * deliberately carries no column suggesting a row can be revised
     * after it is written.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'occurred_at',
        'actor_ref',
        'actor_role',
        'action',
        'source',
        'subject_type',
        'subject_id',
        'subject_version',
        'reason',
        'correlation_id',
        'outcome',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Always throws — see the class-level doc block. Blocks
     * `$event->update([...])`.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw AuditRecordIsImmutableException::forOperation('update');
    }

    /**
     * Always throws — see the class-level doc block. Blocks
     * `$event->outcome = 'denied'; $event->save();` on an already-
     * persisted (`exists === true`) instance, which routes through
     * this method rather than `update()`.
     */
    protected function performUpdate(Builder $query): bool
    {
        throw AuditRecordIsImmutableException::forOperation('performUpdate');
    }

    /**
     * Always throws — see the class-level doc block. Blocks
     * `$event->delete()`.
     */
    public function delete(): ?bool
    {
        throw AuditRecordIsImmutableException::forOperation('delete');
    }
}
