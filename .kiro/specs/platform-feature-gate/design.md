# Design — Platform Feature Gate

## Module

`FeatureGate` (`overview.md` §5). Single evaluation point for the 17 gates and 18 flags. Domain Actions and UI both read it; neither reimplements it.

## Data

```text
feature_gates          -- id, capability, type, owner, state, effective_at, rollback_path
gate_activations       -- append-only: actor, evidence ref, reason, from/to state
feature_flags          -- server-side flags per feature-flag-registry.md
gate_environment_state -- per-environment resolution
```

`gate_activations` is append-only; history is never rewritten.

## Resolution

```text
request -> resolve gate set (cached per request)
        -> deny by default on unknown/misconfigured
        -> expose mode values, not bare booleans, where a fallback has behaviour
```

Modes: `PaymentMode`, `WhatsAppMode`, `PreNeedMode`, `GraveSearchMode`. A mode tells the consumer *which* behaviour to run, which is why a boolean is insufficient.

## Fallback contract

Each gate declares its closed-state behaviour, mirroring `mvp-scope.md` §7. Closing a gate switches behaviour; it never deletes a route or a step. The explanatory state is a first-class UI state, not an error.

## Change flow

Privileged action → recent re-authentication → evidence reference required → audited → outbox event emitted → per-request caches invalidated.

## Observability

Gate state per environment, activation history, denied-by-default hits (a signal of misconfiguration), fallback usage rate per gate, cache invalidation lag.
