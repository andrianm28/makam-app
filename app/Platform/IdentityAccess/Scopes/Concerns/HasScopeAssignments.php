<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes\Concerns;

use App\Platform\IdentityAccess\Scopes\ScopeAssignmentGlobalScope;

/**
 * Attaches `ScopeAssignmentGlobalScope` to any Eloquent model that uses this
 * trait — the reusable mechanism `platform-identity-and-access`
 * requirements.md AC5 asks for: "enforce record scope at query level for
 * cemetery, vendor, order, case, grave, and business entity." No real
 * domain model exists yet to attach this to (`app/Domain/**` is empty
 * scaffolding — see this batch's report for that finding); this trait is
 * the mechanism a future domain model attaches to, proven here against a
 * test-only fixture model instead (`tests/Fixtures/ScopedTestModel.php`).
 *
 * A model using this trait MUST also `implements ScopeAssignable` and
 * implement `scopeAssignmentEntityType()` — this trait supplies sane
 * defaults for the other two contract methods
 * (`scopeAssignmentKeyName()`/`scopeAssignmentKeyIsInteger()`) but
 * deliberately does not guess the entity type, which is always
 * model-specific:
 *
 * ```php
 * final class Cemetery extends Model implements ScopeAssignable
 * {
 *     use HasScopeAssignments;
 *
 *     public function scopeAssignmentEntityType(): string
 *     {
 *         return ScopeEntityType::CEMETERY;
 *     }
 * }
 * ```
 *
 * `ScopeAssignmentGlobalScope` is resolved through the container
 * (`app(ScopeAssignmentGlobalScope::class)`) rather than `new`'d directly,
 * so its own dependency on `ScopeAssignmentResolver` (and, transitively,
 * `App\Platform\IdentityAccess\ActorContext`) is wired automatically. This
 * mirrors how other Laravel global-scope-with-dependencies packages boot
 * (e.g. multi-tenancy packages resolving a "current tenant" service the
 * same way) and needs no service-provider registration from this batch.
 */
trait HasScopeAssignments
{
    public static function bootHasScopeAssignments(): void
    {
        static::addGlobalScope(app(ScopeAssignmentGlobalScope::class));
    }

    /**
     * Column `ScopeAssignmentGlobalScope` constrains queries on. Defaults
     * to the model's own primary key.
     */
    public function scopeAssignmentKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * Whether the column named by `scopeAssignmentKeyName()` holds an
     * integer. Defaults to the model's own `KeyType`/`incrementing`
     * configuration (Eloquent's own signal for "is this an int PK"), which
     * is correct for every model in this codebase today (no UUID-keyed
     * model exists yet).
     */
    public function scopeAssignmentKeyIsInteger(): bool
    {
        return $this->getKeyType() === 'int';
    }
}
