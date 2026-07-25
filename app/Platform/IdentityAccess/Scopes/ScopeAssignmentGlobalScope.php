<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes;

use App\Platform\IdentityAccess\Scopes\Contracts\ScopeAssignable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The mandatory query-level scope mechanism `platform-identity-and-access`
 * requirements.md AC5 requires: "enforce record scope at query level for
 * cemetery, vendor, order, case, grave, and business entity."
 *
 * ---------------------------------------------------------------------------
 * Global scope vs. explicit builder methods — the choice design.md offers,
 * and why this batch picked the global scope
 * ---------------------------------------------------------------------------
 * design.md's enforcement points list names two acceptable mechanisms:
 * "Mandatory query scope via global scopes or explicit builders." An
 * explicit builder (e.g. every consumer must remember to call
 * `Cemetery::visibleTo($actor)->get()` instead of `Cemetery::get()`) is
 * opt-in — a single forgotten call site anywhere in the codebase, today or
 * in a future PR, is a live cross-scope read. requirements.md's own
 * Negative criterion is "No cross-scope read reachable by changing an
 * identifier in a URL" — a guarantee an opt-in mechanism cannot make. An
 * Eloquent global scope applies to EVERY query against the model,
 * including ones written by code that has never heard of this batch,
 * which is what "mandatory" in the requirement text actually needs. This
 * mirrors the deny-by-default principle `platform-feature-gate`'s sibling
 * spec establishes for unknown/misconfigured gates (a concurrent batch,
 * not read by this one beyond that public framing already stated in this
 * batch's own brief).
 *
 * ---------------------------------------------------------------------------
 * Closed-by-default: the "no scopes populated yet" state, and why it is
 * safe
 * ---------------------------------------------------------------------------
 * Today, EVERY actor reaches this scope with zero `scope_assignments` rows
 * — nothing writes to that table yet, and `ActorContext::$scopes` is always
 * `[]` (see `ActorContext`'s own class-level doc block, and
 * `ScopeAssignmentResolver`'s doc block for why this class does not even
 * depend on that empty field). Two possible defaults exist for that state:
 * "matches nothing" (closed) or "matches everything" (open, i.e. skip the
 * constraint entirely). Only closed is consistent with the Negative
 * criterion this AC is graded against — an open default would mean every
 * `HasScopeAssignments` model is fully exposed, unscoped, until someone
 * remembers to grant rows for it, which is precisely "cross-scope read
 * reachable" for every actor on every such model until that day. Closed
 * means a model adopting this trait is INVISIBLE (not merely unscoped)
 * until grants exist for it — the safe failure direction. This is exercised
 * by `tests/Feature/IdentityAccess/Scopes/ScopeAssignmentGlobalScopeTest`.
 *
 * ---------------------------------------------------------------------------
 * FIXED 26 Jul 2026 — resolve the resolver fresh in apply(), never capture
 * it in the constructor
 * ---------------------------------------------------------------------------
 * `HasScopeAssignments::bootHasScopeAssignments()` calls
 * `static::addGlobalScope(app(ScopeAssignmentGlobalScope::class))` exactly
 * ONCE per model class — Eloquent caches both "is this model class booted"
 * and its registered global scope INSTANCES as PHP static properties on the
 * model class itself, not per-request state. Laravel's testing framework
 * rebuilds the DI container fresh for each test method, but that static
 * cache is a plain PHP process-level static and survives across every test
 * in the same PHPUnit run regardless. The first version of this class took
 * `ScopeAssignmentResolver` as a constructor-promoted property, so the
 * SPECIFIC resolver (and the SPECIFIC `ActorContext` it was holding —
 * whichever actor happened to be current the moment the model was first
 * booted, in practice whichever test ran first) got frozen into this
 * object forever. Every later test's `actingAs()` call built a fresh
 * request-scoped `ActorContext` that this already-cached scope instance
 * never saw. First real Postgres CI run caught this as three failures, all
 * "assertNotNull got null" for the tests that needed an actual grant to be
 * visible after two earlier tests that only needed "sees nothing" (which
 * passed regardless, since a stale guest/empty-grants actor still
 * correctly resolves closed) — the bug was invisible until a test actually
 * needed the CURRENT actor to matter.
 *
 * Fix: resolve `ScopeAssignmentResolver` from the container fresh on every
 * `apply()` call instead of once at construction. `apply()` runs per
 * query, not per model-boot, so this reads whichever `ActorContext` is
 * bound *right now* — correct for both real per-request isolation (the
 * property this was already designed for, via `ActorContext`'s own
 * `scoped()` binding) and for this exact cross-test staleness.
 */
final class ScopeAssignmentGlobalScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // $model is required by the Scope contract's Model type, but every
        // model this scope is attached to (via HasScopeAssignments) also
        // implements ScopeAssignable — see that trait's doc block.
        assert($model instanceof ScopeAssignable);

        $entityType = $model->scopeAssignmentEntityType();
        $keyName = $model->qualifyColumn($model->scopeAssignmentKeyName());

        // Resolved HERE, not via a constructor property — see the FIXED
        // note above for exactly why that distinction is load-bearing.
        $resolver = app(ScopeAssignmentResolver::class);

        $actorIdentifier = $resolver->currentActorIdentifier();

        $grantedIds = $actorIdentifier === null
            ? []
            : $resolver->grantedEntityIds($actorIdentifier, $entityType);

        if ($model->scopeAssignmentKeyIsInteger()) {
            $grantedIds = array_map('intval', $grantedIds);
        }

        // Deliberately whereIn() even with an empty array, for both the
        // guest and the "no grants" branch above: Laravel's query grammar
        // compiles an empty IN(...) to a clause that always evaluates
        // false, which is exactly the closed-by-default behaviour this
        // scope requires — no separate "block everything" branch needed.
        $builder->whereIn($keyName, $grantedIds);
    }
}
