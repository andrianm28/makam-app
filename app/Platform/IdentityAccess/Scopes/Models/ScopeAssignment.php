<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes\Models;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A single "this actor may see this entity" grant — the `scope_assignments`
 * table design.md's data list names ("cemetery / vendor / entity grants"),
 * and the row `ScopeAssignmentGlobalScope` reads to enforce
 * requirements.md AC5's mandatory query-level scope.
 *
 * ---------------------------------------------------------------------------
 * Column shape decisions (see the migration for the schema itself)
 * ---------------------------------------------------------------------------
 * - `actor_identifier` is a string, not a `users` foreign key. `ActorContext
 *   ::$identityReference` is typed `int|string|null` specifically because a
 *   future K1/K2-backed adapter may reference identity by an external
 *   string id (see `ActorContext`'s own doc block) — a `foreignId` column
 *   tied to the local `users` table today would have to be widened or
 *   migrated the moment that adapter lands. Storing the identifier as a
 *   plain string avoids that, at the cost of no database-level FK
 *   integrity check against `users` — acceptable because, per design.md,
 *   "Identity itself is NOT mastered here" even for the actor side of this
 *   table.
 * - `entity_id` is also a string for the same forward-compatibility reason:
 *   no real domain model exists yet (`app/Domain/**` is empty scaffolding),
 *   so this batch cannot know today whether a future Cemetery/Vendor/Case
 *   model will have an integer or UUID primary key. `HasScopeAssignments
 *   ::scopeAssignmentKeyIsInteger()` casts back to `int` before comparison
 *   for the (today, universal) integer-keyed case — see that trait.
 * - `grant_level` is nullable and deliberately NOT read by
 *   `ScopeAssignmentGlobalScope` — see `ScopeGrantLevel`'s doc block for
 *   why a binary "an active grant row exists" is sufficient for
 *   query-level scope specifically.
 * - `revoked_at` (nullable timestamp) rather than deleting the row on
 *   revocation — mirrors `actor_sessions.revoked_at`'s already-established
 *   pattern in this codebase (Batch 3.1) for the same reason: keeps a
 *   revoked grant's history queryable instead of silently disappearing.
 *   This is NOT the append-only/audit treatment a grant table arguably
 *   deserves (every grant/revoke being itself an auditable event) — see
 *   this batch's report for that finding; `app/Platform/Audit/**` is a
 *   sibling agent's concurrent, unmerged work this batch cannot integrate
 *   with directly.
 *
 * `entity_type` and `grant_level` are validated against their known-value
 * lists on every save (`booted()` below) so an invalid value can never
 * reach the database even though the column itself is a plain string, not
 * a Postgres enum — the batch brief's explicit "not a Postgres enum type
 * that requires a migration to extend" instruction.
 */
final class ScopeAssignment extends Model
{
    protected $table = 'scope_assignments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_identifier',
        'entity_type',
        'entity_id',
        'grant_level',
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
            ScopeEntityType::assertKnown($assignment->entity_type);

            if ($assignment->grant_level !== null) {
                ScopeGrantLevel::assertKnown($assignment->grant_level);
            }
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
