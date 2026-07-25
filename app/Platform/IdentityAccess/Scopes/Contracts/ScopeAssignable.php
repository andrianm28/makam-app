<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes\Contracts;

/**
 * Implemented by any Eloquent model that uses
 * `App\Platform\IdentityAccess\Scopes\Concerns\HasScopeAssignments` — the
 * contract `ScopeAssignmentGlobalScope` relies on to know which
 * `scope_assignments.entity_type` a given model represents, and which
 * column carries its entity id.
 *
 * Note for this batch's own scope: this interface lives under
 * `Scopes/Contracts/`, a NEW namespace this batch owns — it is unrelated to
 * (and must not be confused with) the already-merged
 * `App\Platform\IdentityAccess\Contracts\` directory from Batch 3.1, which
 * this batch does not touch.
 */
interface ScopeAssignable
{
    /**
     * The `scope_assignments.entity_type` value this model is scoped by.
     * Must be one of `ScopeEntityType::KNOWN_TYPES`.
     */
    public function scopeAssignmentEntityType(): string;

    /**
     * The column `ScopeAssignmentGlobalScope` constrains queries on.
     * Defaults to the model's own primary key when using
     * `HasScopeAssignments`'s default implementation — declared here so a
     * model can override it (e.g. to scope by a non-primary-key column)
     * without changing the trait.
     */
    public function scopeAssignmentKeyName(): string;

    /**
     * Whether `scope_assignments.entity_id` (stored as a string — see the
     * migration's doc block) should be cast to `int` before being compared
     * against `scopeAssignmentKeyName()`. `true` for the common case of an
     * auto-incrementing integer primary key; a model with a string/UUID key
     * overrides this to `false`. Getting this wrong does not create a
     * cross-scope leak (a failed/empty comparison still deny-by-default
     * closes the query) — it only breaks legitimate access — but it is
     * still model-specific and belongs on the model, not guessed centrally.
     */
    public function scopeAssignmentKeyIsInteger(): bool;
}
