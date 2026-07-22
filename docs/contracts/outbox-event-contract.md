# Outbox Event Envelope Contract — v1

Every durable internal event uses this envelope:

```json
{
  "event_id": "uuid",
  "event_name": "payment.received",
  "event_version": 1,
  "occurred_at": "RFC3339 timestamp",
  "trace_id": "opaque trace identifier",
  "aggregate": {
    "type": "order",
    "id": "uuid"
  },
  "actor": {
    "type": "user|service|provider",
    "id": "opaque identifier"
  },
  "classification": "PUBLIC|INTERNAL|CONFIDENTIAL|RESTRICTED",
  "idempotency_key": "domain-unique key",
  "data": {}
}
```

## Rules

1. Consumers ignore unknown additive fields.
2. Semantic breaking changes require a new event version.
3. Payload contains references, not restricted file bodies or permanent object keys.
4. A consumer records processed `event_id` or enforces an equivalent unique constraint.
5. Provider webhook payloads are stored in the dedicated webhook record; the outbox carries only normalized validated facts.
6. Correlation/trace identifiers must not embed PII.
