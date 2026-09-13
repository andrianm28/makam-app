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

### Amendment — `actor` is reserved-and-null in this version (07 Sep 2026, Batch M4b)

The envelope shape above (line 16) shows `actor.type`/`actor.id` as populated
fields. In the actual implementation they are always emitted `null`. This was
a deliberate decision, not an oversight: `App\Platform\Outbox\Outbox::record()`
has no `actorType`/`actorId` parameters, and the `outbox_events` table
(`2026_07_26_140000_create_outbox_events_table.php`) has no actor columns —
recorded in that migration's own doc block and in finding N-11
(`docs/planning/sprint-plan.md`): "no `actor_type`/`actor_id` columns —
`queue-and-outbox.md` §5's cited 'minimum' schema doesn't list them either,
so the published envelope's `actor` key is emitted with null values rather
than fabricating storage the cited schema doesn't have."

Attribution for who performed the action that produced a given outbox event
is available today, for every real producer, in `audit_events` — every
`Outbox::record()` call site is paired with an `Audit::record()` (or
`Audit::wrap()`) call in the same transaction, and the audit row carries a
real `actor_ref`/`actor_role`. A consumer that needs to know who triggered
an event should join on the same aggregate/correlation id against
`audit_events` rather than expect it in the outbox envelope.

This section is the schema's actual behaviour as of v1; it does not change
the envelope shape documented above (`actor` stays a reserved key so a future
version can populate it without a breaking change) — only what value it
currently carries.

**Alternative, not done here:** thread `App\Platform\IdentityAccess\ActorContext`
through `Outbox::record()` so `actor_type`/`actor_id` are populated for real,
with a migration adding the two columns to `outbox_events`. This is a
schema/behaviour change and requires human sign-off per AGENTS.md
§Infrastructure-agent execution; available as a future change if a consumer
genuinely needs `actor` populated in the envelope itself rather than via
`audit_events`.
