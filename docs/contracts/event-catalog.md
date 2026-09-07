# Event Catalog — v0.7

Durable events use the transactional outbox and envelope in `outbox-event-contract.md`. All events include `event_id`, `event_version`, `occurred_at`, actor/service identity, `trace_id`, aggregate reference, idempotency key, and data classification. Restricted documents or permanent file URLs are never embedded.

| Event | Producer | Main consumers | Notes | Status |
|---|---|---|---|---|
| `booking.draft_submitted.v2` | Booking | Workflow router, audit | Contains explicit product type | Not yet produced — Step 9 submission is unbuilt; owned by `public-booking-wizard` |
| `booking.draft_started.v1` | Booking (`StartBookingDraft`) | Audit | Provisional lifecycle name (no catalogue entry existed when the 09 Aug 2026 outbox retrofit needed one); disclosed as finding N-17 | Live |
| `booking.draft_step_saved.v1` | Booking (`SaveBookingDraftStep`) | Audit | Same provisional-name gap as `booking.draft_started.v1`; finding N-17 | Live |
| `funeral_case.created.v1` | FuneralCase | Operations, notification | At-Need accepted; the only `funeral_case.*` event with a real producer — `App\Domain\FuneralCase\Actions\OpenFuneralCase`, called from `SubmitBookingDraft` and `ActivatePreNeed`/`RegisterPreNeedInterest` | Live |
| `funeral_case.manager_assigned.v1` | FuneralCase | Operations, audit | **Deferred (CONTRACT-04, 07 Sep 2026)** — no producer exists; `funeral-case-management` is not built (`docs/planning/sprint-plan.md` §2.2 "Feature specs deferred entirely"). Row/shape reserved for when that spec starts. Includes handover reason when changed | Not yet produced — owned by `funeral-case-management` |
| `funeral_case.task_overdue.v1` | FuneralCase | Escalation | **Deferred (CONTRACT-04, 07 Sep 2026)** — no producer exists (only referenced in `OutboxQueueRouter`'s routing table, never emitted); same `funeral-case-management` deferral as above. Idempotent per task/window | Not yet produced — owned by `funeral-case-management` |
| `availability.requested.v1` | Availability | Operator notification | Non-blocking | Not yet produced — owned by `cemetery-directory-and-availability` |
| `availability.confirmed.v2` | Availability | Quote workflow | Manual or authoritative evidence type | Not yet produced — owned by `cemetery-directory-and-availability` |
| `cemetery.capability_changed.v1` | CemeteryCapability | UI/read models, operations | Audited version activation | Not yet produced — owned by `cemetery-directory-and-availability` |
| `plot.reservation_acquired.v1` | PlotReservation | Case/quote | Authoritative source version | Superseded (see 16 Aug 2026 note) |
| `plot.reservation_expired.v1` | PlotReservation | Case/payment guard | Exactly once | Superseded (see 16 Aug 2026 note) |
| `plot.reservation_conflict.v1` | PlotReservation | Incident/operations | No automatic winner | Superseded (see 16 Aug 2026 note) |
| `quote.issued.v1` | Quotation | Customer notification | Immutable version | Live |
| `quote.accepted.v1` | Quotation | Payment gate | Exact version | Live |
| `payment.received.v1` | PaymentAdapter | Journal/order/invoice | Valid webhook only | Live |
| `payment.outcome_failed.v1` | PaymentAdapter | Notification | Carries `outcome` (Failed/Expired) — one event for one matrix row, not two | Live |
| `marketplace_order.submitted.v1` | Marketplace | Notification | Real customer order submission, one event, no discrimination needed | Live |
| `marketplace_order.paid.v1` | Marketplace | Notification | The marketplace order root's `payment_state -> DIBAYAR` transition (`MarkMarketplaceOrderPaid`) — distinct from `payment.received.v1`, whose catalogued payload carries an `OrderInvoice` reference the marketplace domain has no analogue for | Live |
| `vendor_order.decided.v1` | Marketplace | Notification | Carries `outcome` (accepted/rejected) — one event for one matrix row, not two, same shape as payment.outcome_failed.v1 | Live |
| `vendor_order.complaint_filed.v1` | Marketplace (`UpdateVendorOrderStatus`) | Notification | A vendor order's complaint-filed transition; distinct aggregate from `care.complaint_filed.v1`'s work-order-scoped complaint | Live |
| `order.status_changed.v1` | OrderWorkflow | Notification/reporting | Forward-only commercial status | Live |
| `agreement.accepted.v1` | Agreement (AcceptAgreement) | PreNeed/operations | Exact version and evidence; emitted once on the `agreements` row — the pre-need case-level acceptance binds the same row without re-emitting | Live |
| `pre_need_case.activated.v1` | PreNeed | Operations | AC8: new At-Need FuneralCase linked; original contract history preserved | Live |
| `certificate.issued.v1` | AgreementCertificate | Customer/audit | Unique issuer number | Live |
| `certificate.replaced.v1` | AgreementCertificate | Customer/audit | Preserves previous version | Live |
| `document.uploaded.v1` | DocumentVault | Scan workflow, audit | Private quarantine reference only | Live |
| `document.accepted.v1` | DocumentVault | Booking, audit | Emitted after clean scan and accepted transition | Live |
| `document.accessed.v1` | DocumentVaultAdapter | Audit/security | Sensitive event | Live |
| `document.deleted.v1` | DocumentVault | Retention/audit | Emitted after approved deletion; no file contents | Live |
| `grave.import_completed.v1` | GraveRegistry | Admin notification | Success/error/dedup counts | Not yet produced — owned by `renewal-and-grave-registry` |
| `renewal.marked_external.v1` | Renewal | Billing guard | Prevents duplicate period | `MarkExternalRenewal`/`MarkRenewalPaidExternally` gained their first `Outbox::record()` calls via PR #252 (QUE-03) | Live |
| `renewal.submitted.v1` | Renewal | Notification | The online submission path — distinct from renewal.marked_external.v1's offline/admin path | Live |
| `renewal.paid_online.v1` | Renewal | Notification | A validated webhook settled the renewal online — distinct from renewal.marked_external.v1's offline/admin settlement path | Live |
| `grave.reminder_sent.v1` | GraveRegistry | Reporting | Idempotent window key | Not yet produced — owned by `renewal-and-grave-registry` |
| `care.cycle_paid.v1` | CareSubscription (`MarkCyclePaid`) | Billing/work scheduling | A subscription cycle's PAID transition. Renamed 07 Sep 2026 (CONTRACT-05) from `care.cycle_created.v1` — the event never fired on cycle *creation*; `GenerateCycle` only ever wrote a `CYCLE_GENERATED` audit row and called `Outbox::record()` nowhere. See the dated note below | Live |
| `care.work_order_created.v1` | VendorFulfillment — two producers: `CreateWorkOrder` and `CreateWorkOrderFromCycle` | Case/customer | Evidence reference; corrected 07 Sep 2026 — not "one per paid cycle" (`CreateWorkOrder` is a second, independent producer with no cycle involved) | Live |
| `care.complaint_filed.v1` | VendorFulfillment | Case/customer/audit | Linked to work order; audited | Live |
| `care.complaint_investigating.v1` | VendorFulfillment (`StartInvestigatingComplaint`) | Case/customer/audit | Complaint moved to investigating | Live |
| `care.complaint_resolved.v1` | VendorFulfillment (`ResolveComplaint`) | Case/customer/audit | Complaint resolved | Live |
| `care.complaint_dismissed.v1` | VendorFulfillment (`DismissComplaint`) | Case/customer/audit | Complaint dismissed | Live |
| `care.make_good_created.v1` | VendorFulfillment | Case/customer | Replacement order linked to original | Live |
| `vendor.order_assigned.v1` | VendorFulfillment | Case/customer/notification | A pending work order's vendor assignment (`AssignWorkOrder`); references only — work order, vendor, and care plan/cycle ids | Live |
| `vendor.work_completed.v1` | VendorFulfillment | Case/customer | Evidence reference | Not yet produced — owned by `grave-care-fulfillment` |
| `vendor.evidence_uploaded.v1` | VendorFulfillment | Notification | References only — no document content or restricted data | Live |
| `memorial.unpublished.v1` | Memorial | Public read/QR | Privacy/moderation action | Live |
| `memorial.profile_created.v1` | Memorial | Read models, audit | Privacy default private; grave-record reference only (AC7) | Live |
| `memorial.published.v1` | Memorial | Public read/QR | Profile made public | Live |
| `memorial.qr_token_rotated.v1` | Memorial | Public read/QR | Old token invalidated; opaque random token (AC4) | Live |
| `memorial.content_moderated.v1` | Memorial | Public read/QR | Moderation action; approved-only render (AC6) | Live |
| `visit.booking_confirmed.v1` | Visitation | Customer/operator | Capacity reservation | Live |
| `visit.booking_requested.v1` | Visitation | Customer/operator | Booking request, idempotent per booking | Live |
| `plot_reservation.state_changed.v1` | PlotReservation | Order/case guard, audit | Authoritative hold; append-only, one active hold per plot | Live |
| `feature_gate.state_changed.v1` | FeatureGate (`GateActivationRecorder`) | Dependent projections/notifications (none built yet) | Provisional name, no catalogue entry existed when needed; disclosed as finding N-12 | Live |

> **Note (16 Aug 2026):** `plot.reservation_acquired.v1` / `plot.reservation_expired.v1` / `plot.reservation_conflict.v1` above are superseded by `plot_reservation.state_changed.v1` — the shipped P3 module emits the underscore event and no producer exists for the dotted names; kept as history, not evidence of an active contract.

> **Note (17 Aug 2026):** v0.6 — P5a whole-branch review: `agreement.accepted.v1` has exactly one producer, Lane 1's `AcceptAgreement`, emitting on the `agreements` row (UUID aggregate id, `{agreement_id, version_number, quote_id, accepted_by_ref}` payload); `AcceptPreNeedAgreement` records the case binding without a second emission.

> **Note (07 Sep 2026):** v0.7 — Phase 3 Batch M1a: added `marketplace_order.paid.v1` (new producer `MarkMarketplaceOrderPaid`, closing QUE-02 — a settlement that wrote state and an audit row with no outbox event) and `vendor.order_assigned.v1` (new producer `AssignWorkOrder`, closing QUE-10, same gap). `renewal.marked_external.v1` already existed in this catalogue with no producer; QUE-03 gave it its first two (`MarkExternalRenewal`, `MarkRenewalPaidExternally`) — no catalogue row change needed for that one.

> **Note (07 Sep 2026):** v0.7 — Phase 3 Batch M4b, reconciling this catalogue against what `app/` actually emits (API-05/CONTRACT-01, CONTRACT-02, CONTRACT-05). Added a `Status` column: `Live` (real producer exists), `Not yet produced` (row describes a future contract with no producer — named against the `.kiro/specs/` module that owns it), or `Superseded` (kept as history per the 16 Aug 2026 note). Added seven previously-uncatalogued-but-emitted rows: `booking.draft_started.v1`, `booking.draft_step_saved.v1`, `feature_gate.state_changed.v1` (all three were disclosed gaps, findings N-17/N-12), `vendor_order.complaint_filed.v1`, `care.complaint_investigating.v1`, `care.complaint_resolved.v1`, `care.complaint_dismissed.v1`. Corrected `care.work_order_created.v1`'s note — it has two producers (`CreateWorkOrder`, `CreateWorkOrderFromCycle`), not "one per paid cycle." **Renamed `care.cycle_created.v1` to `care.cycle_paid.v1`** (CONTRACT-05): verified by reading `GenerateCycle` (audit-only, no outbox call) and `MarkCyclePaid` (the actual, only producer, on the cycle's PAID transition — `docs/domain/traceability-matrix.md`'s CARE-SUB-02 entry recorded this same discrepancy earlier but the live event name was never fixed until now); no consumer or notification-matrix row referenced the old name, so this is a rename, not a new version. Marked ten rows `Not yet produced` after confirming no `Outbox::record()` call anywhere in `app/` uses that event name — see each row for the owning spec; `renewal.marked_external.v1` gained its first producers via PR #252 (merged) per the note above.

## Compatibility

Additive fields are backward compatible. Renaming/removing or semantic changes require a new version. Consumers ignore unknown fields and enforce the documented privacy classification.
