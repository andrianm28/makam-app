<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate;

/**
 * "resolve gate set (cached per request)" — design.md's resolution flow.
 * The entire outcome of one registry load, for every gate id that was known
 * at load time. `FeatureGateResolver` builds exactly one of these per
 * request/job (via a `scoped()` container binding — see that class) and
 * every `isOpen()` call for the rest of that request/job reads this same
 * immutable snapshot instead of hitting the database again.
 *
 * A gate id absent from `$states` is NOT the same thing as "not yet
 * loaded" — `$states` is expected to hold every row `EloquentGateRegistrySource`
 * could read at snapshot-build time, so `stateFor()` treats a missing key
 * as `GateState::unknown()` (deny-by-default, AC10), not as "ask the
 * database again". There is deliberately no lazy per-id fallback query
 * here — that would silently reintroduce a query-per-check cost this class
 * exists to remove, and would also violate "cached per request": a gate
 * activated by another process mid-request must NOT be visible until the
 * next request re-resolves (requirements.md AC8 / Negative criteria: "No
 * cached gate state served after a state change" means the cache must not
 * outlive the change across requests, not that it must observe the change
 * mid-request).
 */
final readonly class GateRegistrySnapshot
{
    /**
     * @param  array<string, GateState>  $states  Keyed by gate id.
     * @param  bool  $loadFailed  True when the underlying registry load
     *                            threw and this snapshot is deliberately empty as a result — see
     *                            `EloquentGateRegistrySource`. Every `stateFor()` call against an
     *                            empty snapshot already resolves `unknown()` (closed) on its own;
     *                            this flag exists only so a caller that wants to distinguish "gate
     *                            genuinely not in the registry" from "the whole registry failed to
     *                            load" (an infrastructure signal, not a product one) can do so.
     */
    public function __construct(
        private array $states,
        public bool $loadFailed = false,
    ) {}

    public static function empty(bool $loadFailed = false): self
    {
        return new self([], loadFailed: $loadFailed);
    }

    public function stateFor(string $gateId): GateState
    {
        return $this->states[$gateId] ?? GateState::unknown($gateId);
    }

    public function isOpen(string $gateId): bool
    {
        return $this->stateFor($gateId)->open;
    }

    /**
     * @return list<string> Every gate id this snapshot has a real
     *                      (non-unknown) row for. Diagnostic/testing use — not consulted by
     *                      `isOpen()`, which must work correctly even for an id absent here.
     */
    public function knownGateIds(): array
    {
        return array_keys($this->states);
    }
}
