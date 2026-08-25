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
 * This is application-level defense-in-depth ONLY, not the real
 * enforcement design.md names: "no UPDATE/DELETE grant on
 * audit_events for the application role." This project cannot grant
 * that today — see finding N-1 (`docs/planning/sprint-plan.md`) and
 * the migration's own doc block for why, and
 * `app/Platform/Audit/sql/revoke-audit-mutations.sql` for the exact
 * statements to run once that gap closes.
 *
 * The overrides below stop:
 *   - `$event->update([...])`
 *   - `$event->outcome = 'denied'; $event->save();` (the `exists ===
 *     true` path, via `performUpdate()`)
 *   - `$event->delete()`
 *
 * They do NOT stop, and cannot stop, from PHP alone:
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
 * The only enforcement that closes ALL of those paths is the
 * database-level REVOKE design.md names, which this project cannot
 * apply today (finding N-1: only one Postgres role per environment,
 * which both owns the database and runs the application — there is no
 * distinct, lower-privileged role to revoke from). Until that role
 * split lands, AC1 is PARTIALLY satisfied: real for the
 * ORM-instance-method path above, absent for every other path. Do not
 * treat this class as having "solved" AC1 — see
 * `2026_07_26_110000_create_audit_events_table.php`'s doc block for
 * the full accounting.
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
