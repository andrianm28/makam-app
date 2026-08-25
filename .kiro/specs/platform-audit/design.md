# Design — Platform Audit

## Module

`AuditAdapter` (`overview.md` §5). One write API used by every Action. Consumers never insert audit rows directly.

## Data

```text
audit_events
  id, occurred_at, actor_ref, actor_role, action, source
  subject_type, subject_id, subject_version
  reason            -- required for the declared sensitive actions
  correlation_id
  outcome           -- allowed | denied | failed
  metadata          -- allowlisted, non-restricted fields only
audit_read_events   -- auditing the auditors
```

Append-only enforced at the database level: no `UPDATE`/`DELETE` grant on `audit_events` for the application role. Migration role only for schema.

## Write contract

```php
Audit::record(action: ..., subject: ..., reason: ..., outcome: ...)
```

Called inside the same transaction as the mutation. A helper wraps mutation + audit so the pair cannot be separated by accident.

## Metadata safety

`metadata` accepts an allowlist. Restricted classifications are rejected at write time rather than by reviewer discipline — the same pattern as notification templates.

## Correlation

`correlation_id` originates at the request boundary and is propagated into outbox events, queue jobs, provider calls, and notifications, so a single identifier reconstructs a full flow.

## Observability

Audit write failures (should be zero), sensitive actions missing a reason, actions by role, denied-outcome rate, audit read volume.
