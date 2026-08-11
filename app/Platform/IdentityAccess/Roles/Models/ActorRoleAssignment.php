<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Roles\Models;

use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A single "this actor holds this role" grant — the `actor_role_assignments`
 * table `Roles\ActorRoleReader` (Task 3) reads to resolve
 * `ActorContext::$roles`. Deliberately the structural twin of
 * `Scopes\Models\ScopeAssignment`; see that class's doc block for the
 * shared background on why identity is stored as a plain string here rather
 * than a `users` foreign key. This doc block covers only what differs.
 *
 * ---------------------------------------------------------------------------
 * Column shape decisions (see the migration for the schema itself)
 * ---------------------------------------------------------------------------
 * - `actor_identifier` is a string, not a `users` foreign key — same
 *   reasoning as `ScopeAssignment::$actor_identifier`: `ActorContext
 *   ::$identityReference` is typed `int|string|null` so a future K1/K2
 *   adapter can key on an external string id, and design.md's "Identity
 *   itself is NOT mastered here" applies to this table too.
 * - `role` is app-validated against `ActorRole::KNOWN_ROLES` on every save
 *   (`booted()` below), never a Postgres enum — the column stays a plain
 *   `string(64)` so extending the list is a one-line change in `ActorRole`,
 *   not a migration.
 * - `revoked_at` (nullable timestamp) rather than deleting the row on
 *   revocation — mirrors `ScopeAssignment::$revoked_at` and
 *   `actor_sessions.revoked_at`: keeps a revoked grant's history queryable
 *   instead of silently disappearing.
 * - There is deliberately **no `entity_id` column** and **no grant-reason or
 *   granted-by column** — see the migration's doc block for both omissions;
 *   they are design decisions, not oversights.
 */
final class ActorRoleAssignment extends Model
{
    protected $table = 'actor_role_assignments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_identifier',
        'role',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $assignment): void {
            ActorRole::assertKnown($assignment->role);
        });
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Soft-revoke this grant. Does not delete the row — see the class-level
     * note on why `revoked_at` was chosen over deletion.
     */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
    }
}
