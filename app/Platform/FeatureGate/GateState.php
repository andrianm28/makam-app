<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate;

/**
 * The result of resolving ONE gate id, for one request/job. Immutable value
 * object — never an Eloquent model — so a consumer can hold it for the rest
 * of the request without risking a stale re-fetch overwriting it mid-flight.
 *
 * `$open` is the single fact everything else in this module reduces to.
 * `$unknown` and `$misconfigured` are diagnostic-only: they explain WHY a
 * gate resolved closed (requirements.md AC10's "denied-by-default hits (a
 * signal of misconfiguration)" observability note, design.md §Observability)
 * — they must never be read as "closed but actually fine really". A gate
 * that is unknown or misconfigured is ALWAYS also `$open === false`; that
 * invariant is enforced by the two named constructors below, not left to
 * caller discipline.
 */
final readonly class GateState
{
    private function __construct(
        public string $gateId,
        public bool $open,
        public bool $unknown,
        public bool $misconfigured,
    ) {}

    /**
     * A gate row that loaded, parsed, and holds a recognised `state` value.
     * `$open` is exactly what that row (after any environment override)
     * says — this is the ONLY constructor that can return an open state.
     */
    public static function fromRecord(string $gateId, bool $open): self
    {
        return new self($gateId, open: $open, unknown: false, misconfigured: false);
    }

    /**
     * No `feature_gates` row exists for this gate id at all. Deny-by-
     * default (AC10): always closed.
     */
    public static function unknown(string $gateId): self
    {
        return new self($gateId, open: false, unknown: true, misconfigured: false);
    }

    /**
     * A row exists but could not be trusted — an unrecognised `state`
     * value, a load/parse failure, or any other shape the resolver was not
     * built to interpret. Deny-by-default (AC10): always closed. Kept
     * distinct from `unknown()` because the two point an operator at
     * different fixes (register the gate, vs. repair the row).
     */
    public static function misconfigured(string $gateId): self
    {
        return new self($gateId, open: false, unknown: false, misconfigured: true);
    }
}
