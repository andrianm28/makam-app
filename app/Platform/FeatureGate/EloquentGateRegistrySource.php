<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate;

use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Models\GateEnvironmentState;
use Throwable;

/**
 * Loads a `GateRegistrySnapshot` from `feature_gates` (global state) and
 * `gate_environment_state` (per-environment override), for the environment
 * this PHP process is currently running as (`config('app.env')` —
 * AC11's environment-scoping data source; see the
 * `gate_environment_state` migration for what this batch does and does not
 * implement of AC11 itself).
 *
 * Only recognised `state` values (`open`, `closed`) resolve to a real
 * `GateState::fromRecord()`. Anything else — an unrecognised string, a
 * `null` where the column is unexpectedly empty, or the whole query
 * throwing — resolves that gate (or, if the query itself throws, EVERY
 * gate) to `GateState::misconfigured()` / an empty snapshot. This is the
 * literal implementation of this batch's brief: "an unknown gate id, or a
 * gate row that fails to load/parse, MUST resolve closed."
 */
final readonly class EloquentGateRegistrySource implements GateRegistrySource
{
    public function __construct(
        private string $environment,
    ) {}

    public function load(): GateRegistrySnapshot
    {
        try {
            $rows = FeatureGate::query()->get(['gate_id', 'state']);

            /** @var array<string, string> $environmentOverrides gate_id => state, for $this->environment only */
            $environmentOverrides = GateEnvironmentState::query()
                ->where('environment', $this->environment)
                ->pluck('state', 'gate_id')
                ->all();
        } catch (Throwable) {
            // The registry itself could not be read (DB down, table
            // missing, connection refused ...). Fail closed for every
            // gate rather than let the exception propagate into an
            // unrelated request — see the interface doc block.
            return GateRegistrySnapshot::empty(loadFailed: true);
        }

        $states = [];

        foreach ($rows as $row) {
            $gateId = $row->gate_id;

            // Environment override wins over the global row when present;
            // otherwise fall back to the global `feature_gates.state`.
            $effectiveState = $environmentOverrides[$gateId] ?? $row->state;

            $states[$gateId] = match ($effectiveState) {
                'open' => GateState::fromRecord($gateId, open: true),
                'closed' => GateState::fromRecord($gateId, open: false),
                // Anything else — a bad manual UPDATE, a future admin UI
                // bug, a NULL that slipped past the column default — is a
                // misconfigured row, not an open or a silently-ignored one.
                default => GateState::misconfigured($gateId),
            };
        }

        return new GateRegistrySnapshot($states);
    }
}
