<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate;

use App\Platform\FeatureGate\Contracts\GateRegistrySource;

/**
 * Resolves the whole gate set exactly once per request/job and hands every
 * subsequent caller the same cached `GateRegistrySnapshot` — design.md's
 * resolution flow: "request -> resolve gate set (cached per request) ->
 * deny by default on unknown/misconfigured -> expose mode values."
 *
 * This is the ONLY server-side entry point Domain Actions and UI should
 * call to ask "is gate X open" (requirements.md AC2: "evaluate every gate
 * check server-side"; Negative criteria: "No client-side gate as the
 * enforcement point"). Nothing about this class reads a request header,
 * query string, or cookie — its only input is `GateRegistrySource`, which
 * itself only reads the database. A client cannot influence the answer by
 * sending a flag of its own; see
 * `tests/Feature/FeatureGate/ClientSideTamperingCannotOpenAGateTest.php`.
 *
 * ---------------------------------------------------------------------------
 * Same "resolve once per request" mechanism as `ActorContextResolver`
 * (`app/Platform/IdentityAccess/ActorContextResolver.php` — read that
 * class's doc block for the full reasoning; summarised here, not
 * restated in full, per this batch's own no-duplication instruction).
 * ---------------------------------------------------------------------------
 * This class caches its result in a plain nullable property
 * (`$snapshot`), which only gives genuine once-per-request behaviour when
 * exactly one instance of this class exists for the request/job's
 * lifetime. That guarantee is the container binding's job
 * (`FeatureGateServiceProvider`, `Container::scoped()` — NOT
 * `singleton()`), for the identical Horizon-worker-isolation reason
 * `ActorContextResolver`'s doc block explains: a bare `singleton()` would
 * leak the first job's resolved gate snapshot into every later job handled
 * by the same long-lived worker process, which for a *feature gate* is a
 * materially worse bug than for actor context — it could serve a payment
 * gate's stale "open" state to a job running after that gate was closed.
 */
final class FeatureGateResolver
{
    private ?GateRegistrySnapshot $snapshot = null;

    public function __construct(
        private readonly GateRegistrySource $source,
    ) {}

    public function resolve(): GateRegistrySnapshot
    {
        if ($this->snapshot === null) {
            $this->snapshot = $this->source->load();
        }

        return $this->snapshot;
    }

    /**
     * Deny-by-default gate check (requirements.md AC10). An unknown or
     * misconfigured gate id returns `false` — this method has no "throw on
     * unknown id" mode and none should be added; a typo'd gate id silently
     * resolving closed (and being loud about it via the observability
     * signal `GateState::$unknown`/`$misconfigured` carry) is the entire
     * point of deny-by-default.
     */
    public function isOpen(string $gateId): bool
    {
        return $this->resolve()->isOpen($gateId);
    }

    public function stateFor(string $gateId): GateState
    {
        return $this->resolve()->stateFor($gateId);
    }

    /**
     * Escape hatch mirroring `ActorContextResolver::forget()` — for a
     * future activation flow that changes gate state mid-request (e.g. an
     * admin action followed immediately by a response that must reflect
     * the new state) and needs to force the next `resolve()` to re-query
     * rather than serve the request-scoped cache. Not exercised by any
     * caller in this batch; the admin activation UI is out of scope (see
     * this batch's report).
     */
    public function forget(): void
    {
        $this->snapshot = null;
    }
}
