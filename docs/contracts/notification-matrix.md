# Notification Matrix — MVP

## Channel legend

- `IN_APP`: platform/admin/vendor panel notification.
- `EMAIL`: baseline customer/admin channel.
- `WA`: WhatsApp only when BSP/template gate active.
- `MANUAL`: phone/manual contact task when required.

## Matrix

> **Reconciliation record — 11 Aug 2026 (platform-notifications lane, Task 6).**
> This matrix was reconciled field by field against
> `.kiro/specs/platform-notifications/requirements.md` AC1 (the matrix is the
> single source of truth for event, recipient scope, channel, and template —
> the module reads it through `NotificationMatrixSource` and never restates
> it; the notification-template seed is a point-in-time snapshot of the rows
> below), AC6 (recipient scope is resolved from record scope), and the
> design-system delivery-state contract (`docs/design/design-system.md` §6.8:
> `success` "Terkirim" · `pending` "Sedang dikirim" · `neutral` "WhatsApp
> belum tersedia"; the UI may claim a delivery only from a recorded
> `notification_deliveries` state, AC4). The 17 event rows, their order, and
> their cell texts are canonical and pinned by the module's tests; do not
> reorder, drop, or reword a row without changing those tests in the same
> change.
>
> **Cell-value ruling — `optional`.** An `optional` cell means the recipient
> is emitted **when the record has the recipient** — the customer owner
> exists, or the actor holds the scope grant the column is scoped to. It
> NEVER means "skip on failure": a channel failure cannot drop the
> notification, because delivery state is recorded independently of the
> business record (AC5) and admin/operator/vendor in-app records are always
> written (AC7). `optional` cells carry no external channel token, so no
> email/WhatsApp delivery row is derived from them — the module derives
> channels by scanning each cell for the legend's `IN_APP`/`EMAIL`/`WA`/
> `MANUAL` tokens, and the prose qualifiers in these cells (`optional`,
> `confirmation`, `optional status`, `Vendor when allocated`, `Assigned
> vendor`) yield no external channel by that scan. `none` is an explicit
> no-recipient decision and `TBD` (legend below) an undecided one; both
> resolve to no recipients.
>
> **Delivery-rule readings recorded during this reconciliation.** Rule 3's
> "WhatsApp failure falls back to email/in-app when configured" reads, under
> the current module, as: while `WhatsAppMode` is `EMAIL_IN_APP_FALLBACK`
> (`G-WA-01` closed), WhatsApp is never dispatched and is recorded as a real
> `UNAVAILABLE` delivery row — never a silent omission, and no WA→EMAIL
> re-route is configured. Rule 6's "reference, current status, next action,
> and support contact" is a content requirement the seeded templates do not
> yet carry — the seeded body is the matrix row's recipient/channel facts,
> marked as a snapshot. Recorded as a gap, not closed here: template content
> is a seed/migration change, out of a doc-reconciliation task's scope.

`TBD`: recipient policy not yet decided — resolves to no recipients.

| Event | Customer | Admin platform | Pengelola TPU/TPS | Vendor | Case manager | Finance |
|---|---|---|---|---|---|---|
| Booking draft created | none | none | none | none | TBD | TBD |
| Booking submitted | EMAIL/WA | IN_APP | IN_APP/EMAIL for selected location | none | TBD | TBD |
| Availability requested | optional status | IN_APP | IN_APP/EMAIL/WA | none | TBD | TBD |
| Availability confirmed/rejected | EMAIL/WA | IN_APP | IN_APP | none | TBD | TBD |
| Quote issued | EMAIL/WA | IN_APP | optional | none | TBD | TBD |
| Quote accepted | confirmation | IN_APP | optional | none | TBD | TBD |
| Payment opened | EMAIL/WA | IN_APP | none | none | TBD | TBD |
| Payment received | EMAIL/WA + invoice | IN_APP | IN_APP for related order | Vendor when allocated | TBD | TBD |
| Payment failed/exception | EMAIL/WA with recovery | IN_APP exception | none | none | TBD | TBD |
| Order processing | EMAIL/WA based on material change | IN_APP | IN_APP | Assigned vendor | TBD | TBD |
| Order completed | EMAIL/WA | IN_APP | IN_APP | IN_APP | TBD | TBD |
| Marketplace order submitted | EMAIL/WA | IN_APP | none | IN_APP/EMAIL | TBD | TBD |
| Vendor accepted/rejected | EMAIL/WA | IN_APP | none | IN_APP | TBD | TBD |
| Vendor evidence uploaded | EMAIL/WA | optional | none | IN_APP | TBD | TBD |
| Renewal submitted | EMAIL/WA | IN_APP | IN_APP/EMAIL | none | TBD | TBD |
| Renewal paid/verified | EMAIL/WA + invoice | IN_APP | IN_APP | none | TBD | TBD |
| Reminder due | EMAIL/WA | optional | optional | none | TBD | TBD |
| Visitation booking requested | none | none | IN_APP | none | TBD | TBD |
| Visitation booking confirmed | none | none | IN_APP | none | TBD | TBD |

> **07 Sep 2026 addition — NOTIF-13** (Phase 3, Batch M8b,
> `docs/superpowers/plans/2026-09-07-batchm8b-notification-completeness.md`).
> `RequestVisitation::book()` and `ChangeVisitationBookingStatus::__invoke()`
> already recorded `visit.booking_requested.v1`/`visit.booking_confirmed.v1`
> onto the outbox with no matching subject/template mapping, so the
> visitation journey's own confirmation copy claimed the request was "sent
> to the operator" while nothing was ever notified and the operator had no
> surface to see it at all. Customer is `none`, not a channel silently
> dropped: no visitor-account concept exists anywhere in this codebase (a
> visit request's `contact_phone`/`contact_email` are not a
> `scope_assignments.actor_identifier`-shaped reference to a real actor),
> matching the `Renewal submitted`/`Renewal paid/verified` rows' own
> precedent above. Admin platform is `none` for the same reason every other
> row in this document that is NOT genuinely a platform-wide concern stays
> `none` — a visitation request is cemetery-operational, not a platform-admin
> one. Pengelola TPU/TPS is the entire point of this addition: an operator
> holding a grant on the booking's cemetery now gets a real `IN_APP` row —
> see `ProvisionalAggregateNotificationSubjectSource::
> visitationBookingSubject()`'s own doc block for the full resolution.

## Delivery rules

1. Notification outbox is durable and idempotent.
2. Channel failure never changes business state.
3. WhatsApp failure falls back to email/in-app when configured.
4. UI must not claim a message was delivered unless provider or internal delivery state confirms it.
5. Restricted documents are never attached to email/WhatsApp; use authenticated or expiring links.
6. Notification includes reference, current status, next action, and support contact.
7. Admin/operator/vendor recipients are scoped to the relevant record and entity.
