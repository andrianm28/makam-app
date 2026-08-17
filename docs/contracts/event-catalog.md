# Event Catalog — v0.6

Durable events use the transactional outbox and envelope in `outbox-event-contract.md`. All events include `event_id`, `event_version`, `occurred_at`, actor/service identity, `trace_id`, aggregate reference, idempotency key, and data classification. Restricted documents or permanent file URLs are never embedded.

| Event | Producer | Main consumers | Notes |
|---|---|---|---|
| `booking.draft_submitted.v2` | Booking | Workflow router, audit | Contains explicit product type |
| `funeral_case.created.v1` | FuneralCase | Operations, notification | At-Need accepted |
| `funeral_case.manager_assigned.v1` | FuneralCase | Operations, audit | Includes handover reason when changed |
| `funeral_case.task_overdue.v1` | FuneralCase | Escalation | Idempotent per task/window |
| `availability.requested.v1` | Availability | Operator notification | Non-blocking |
| `availability.confirmed.v2` | Availability | Quote workflow | Manual or authoritative evidence type |
| `cemetery.capability_changed.v1` | CemeteryCapability | UI/read models, operations | Audited version activation |
| `plot.reservation_acquired.v1` | PlotReservation | Case/quote | Authoritative source version |
| `plot.reservation_expired.v1` | PlotReservation | Case/payment guard | Exactly once |
| `plot.reservation_conflict.v1` | PlotReservation | Incident/operations | No automatic winner |
| `quote.issued.v1` | Quotation | Customer notification | Immutable version |
| `quote.accepted.v1` | Quotation | Payment gate | Exact version |
| `payment.received.v1` | PaymentAdapter | Journal/order/invoice | Valid webhook only |
| `order.status_changed.v1` | OrderWorkflow | Notification/reporting | Forward-only commercial status |
| `agreement.accepted.v1` | Agreement (AcceptAgreement) | PreNeed/operations | Exact version and evidence; emitted once on the `agreements` row — the pre-need case-level acceptance binds the same row without re-emitting |
| `pre_need_case.activated.v1` | PreNeed | Operations | AC8: new At-Need FuneralCase linked; original contract history preserved |
| `certificate.issued.v1` | AgreementCertificate | Customer/audit | Unique issuer number |
| `certificate.replaced.v1` | AgreementCertificate | Customer/audit | Preserves previous version |
| `document.uploaded.v1` | DocumentVault | Scan workflow, audit | Private quarantine reference only |
| `document.accepted.v1` | DocumentVault | Booking, audit | Emitted after clean scan and accepted transition |
| `document.accessed.v1` | DocumentVaultAdapter | Audit/security | Sensitive event |
| `document.deleted.v1` | DocumentVault | Retention/audit | Emitted after approved deletion; no file contents |
| `grave.import_completed.v1` | GraveRegistry | Admin notification | Success/error/dedup counts |
| `renewal.marked_external.v1` | Renewal | Billing guard | Prevents duplicate period |
| `grave.reminder_sent.v1` | GraveRegistry | Reporting | Idempotent window key |
| `care.cycle_created.v1` | CareSubscription | Billing/work scheduling | One per cycle |
| `care.work_order_created.v1` | VendorFulfillment | Case/customer | Evidence reference; one per paid cycle |
| `care.complaint_filed.v1` | VendorFulfillment | Case/customer/audit | Linked to work order; audited |
| `care.make_good_created.v1` | VendorFulfillment | Case/customer | Replacement order linked to original |
| `vendor.work_completed.v1` | VendorFulfillment | Case/customer | Evidence reference |
| `memorial.unpublished.v1` | Memorial | Public read/QR | Privacy/moderation action |
| `memorial.profile_created.v1` | Memorial | Read models, audit | Privacy default private; grave-record reference only (AC7) |
| `memorial.published.v1` | Memorial | Public read/QR | Profile made public |
| `memorial.qr_token_rotated.v1` | Memorial | Public read/QR | Old token invalidated; opaque random token (AC4) |
| `memorial.content_moderated.v1` | Memorial | Public read/QR | Moderation action; approved-only render (AC6) |
| `visit.booking_confirmed.v1` | Visitation | Customer/operator | Capacity reservation |
| `visit.booking_requested.v1` | Visitation | Customer/operator | Booking request, idempotent per booking |
| `plot_reservation.state_changed.v1` | PlotReservation | Order/case guard, audit | Authoritative hold; append-only, one active hold per plot |

> **Note (16 Aug 2026):** `plot.reservation_acquired.v1` / `plot.reservation_expired.v1` / `plot.reservation_conflict.v1` above are superseded by `plot_reservation.state_changed.v1` — the shipped P3 module emits the underscore event and no producer exists for the dotted names; kept as history, not evidence of an active contract.

> **Note (17 Aug 2026):** v0.6 — P5a whole-branch review: `agreement.accepted.v1` has exactly one producer, Lane 1's `AcceptAgreement`, emitting on the `agreements` row (UUID aggregate id, `{agreement_id, version_number, quote_id, accepted_by_ref}` payload); `AcceptPreNeedAgreement` records the case binding without a second emission.

## Compatibility

Additive fields are backward compatible. Renaming/removing or semantic changes require a new version. Consumers ignore unknown fields and enforce the documented privacy classification.
