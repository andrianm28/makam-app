# Funeral Case Model

## Purpose

At-Need and Urgent are human service orchestration problems. `order.status` alone cannot represent the work required.

## Aggregate

```text
FuneralCase
├── customer and deceased contacts
├── urgency and service area
├── case manager
├── response/availability/service deadlines
├── tasks and checklist
├── communications
├── appointments and transport milestones
├── selected cemetery/package/plot
├── quotation/order/payment references
├── documents
├── vendor/work orders
├── incidents/escalations
└── completion evidence
```

## Case statuses

```text
NEW -> TRIAGED -> COORDINATING -> READY_FOR_SERVICE -> IN_SERVICE -> COMPLETED
        -> DECLINED
        -> CANCELLED
        -> TRANSFERRED
```

These are operational statuses and do not replace commercial order/payment statuses.

## Task rules

- Every task has owner, due time, priority, status, evidence requirement, and escalation policy.
- Critical tasks cannot be silently skipped; waiver requires reason and authorized actor.
- Task retry or duplicate notification does not create duplicate work.
- Case manager handover records previous/new owner, time, open tasks, and reason.

## Urgent readiness

Per service area:

- operating hours;
- capacity state;
- first-response target;
- confirmation target;
- escalation contacts;
- fallback phone/manual channel;
- automatic closure conditions.
