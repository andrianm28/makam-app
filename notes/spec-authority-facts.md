# Spec-authority fact-finding — inputs for L7 order orchestration

Captured 12 Aug 2026 against trunk `d9fea9f`, during the grill-spec pass on
`.kiro/specs/booking-and-order-orchestration/design.md`. **Working note, not a
canonical document** — re-verify against the cited files before relying on any
claim in a spec, ADR, or plan.

## 1. Module and table ownership

No document states "OrderWorkflow owns `orders`" or "Quotation owns `quotes`"
as a single sentence. Ownership is established by combining three sources:

- `app/Domain/README.md:13-15` — ownership is declared normatively in the
  **owning spec's `design.md`**, not in `docs/architecture/overview.md`.
- `.kiro/specs/booking-and-order-orchestration/design.md:19` claims
  `booking_drafts, orders, order_parties, deceased_profiles, order_documents,
  quotes, quote_lines, order_status_events, workflow references` as **one
  undivided list**, not split across an OrderWorkflow and a Quotation namespace.
- No other spec's `design.md` claims any of these seven tables (verified by grep
  across all specs). **No ownership conflict exists.**

**Consequence:** L7 owns all of it under one spec. Whether it is split
internally into `app/Domain/OrderWorkflow/` plus `app/Domain/Quotation/` or kept
together is an implementation call, not dictated by any document.

## 2. Event catalogue

`docs/contracts/event-catalog.md:7-23` — complete list of catalogued events
touching orders, quotes, payments, cases, or reservations:

`booking.draft_submitted.v2`, `funeral_case.created.v1`,
`funeral_case.manager_assigned.v1`, `funeral_case.task_overdue.v1`,
`availability.requested.v1`, `availability.confirmed.v2`,
`cemetery.capability_changed.v1`, `plot.reservation_acquired.v1`,
`plot.reservation_expired.v1`, `plot.reservation_conflict.v1`,
`quote.issued.v1`, `quote.accepted.v1`, `payment.received.v1`,
`order.status_changed.v1`, `agreement.accepted.v1`, `certificate.issued.v1`,
`certificate.replaced.v1`.

**For orders there is exactly ONE event: `order.status_changed.v1`.** No
`order.created.v1` and no `order.paid.v1` exist. Every order transition must
therefore be expressed through `order.status_changed.v1` rather than a new
per-transition event name.

No approval process is documented in the catalogue file itself, but
`package-and-service-bundles/design.md:69-71` states the norm: check the
catalogue first and add the name there before inventing an ad hoc one (finding
N-12's lesson). Follow that discipline if a new event proves genuinely
necessary.

## 3. Gates

`docs/governance/assumptions-and-gates.md` §2, rows relevant to this lane:

| Gate | Gates | Documented fallback |
|---|---|---|
| `G-PAY-01` | Online payment | **Manual coordination** |
| `G-LEGAL-01` | Paid Pre-Need | Interest/consultation only |
| `G-OPS-01` | Urgent/At-Need acceptance | — |
| `G-PLOT-01` | Specific plot inventory | — |
| `G-DIRECT-01` | Direct plot purchase | — |
| `G-PAYOUT-01`, `G-TOKEN-01`, `G-CERT-01`, `G-RATE-01` | (adjacent) | — |
| `G-EXT-01` | Outside-system renewal marking | L8's territory, not L7's |

The table carries **no explicit open/closed state column** — what it documents
is behaviour-before-active. Corroborating but non-authoritative evidence
elsewhere indicates `G-CERT-01`, `G-LEGAL-01`, and `G-PLOT-01` are currently
closed. `G-PAY-01` closed is already established independently.

## 4. FuneralCase and PreNeedCase

**FuneralCase** — `docs/domain/funeral-case-model.md:26-33`:

```
NEW -> TRIAGED -> COORDINATING -> READY_FOR_SERVICE -> IN_SERVICE -> COMPLETED
```

with branches `DECLINED` / `CANCELLED` / `TRANSFERRED`.

**Explicitly separate from commercial status**, in two places —
`funeral-case-model.md:35` ("These are operational statuses and do not replace
commercial order/payment statuses") and `docs/domain/domain-model.md:165`
("Case/task and order/payment statuses are distinct"). This is direct authority
for requirement 11's separation and should be cited rather than re-argued.

Minimum fields at creation (`funeral-case-management/requirements.md` AC1):
urgency, area, owner, deadlines. Created **before** non-critical data collection
(`at-need-booking/requirements.md` AC3).

**PreNeedCase** — no dedicated model document, and two sources disagree:

- `pre-need-contracting/design.md:3-13` (itself marked "final approval required
  before implementation") lists 11 states: `INTEREST, CONSULTING, PROPOSED,
  RESERVED, CONTRACT_PENDING, ACTIVE_PAYMENT, SETTLED, CERTIFIED, ACTIVATED,
  CANCELLED, DEFAULTED`.
- `docs/domain/order-lifecycle.md:54-62` gives the gate-closed chain
  `INTEREST_REGISTERED -> CONTACTED -> CLOSED`, with no invoice, payment
  session, or financial obligation while `G-LEGAL-01` is closed.

**No reconciliation between the two exists.** `pre-need-contracting` is not
scheduled in Wave 2, so implement the simpler `order-lifecycle.md` chain; the
11-state version governs only once that spec is actually scheduled (consistent
with this session's earlier N-7 ruling).

## 5. House style for a `design.md`

`.kiro/specs/cemetery-directory-and-availability/design.md` — 29 lines, terse
and declarative, 2-5 lines per section, heavy use of backtick-quoted identifiers
in preference to prose. This is the length and tone reference.

Several specs carry an explicit out-of-scope section under headings like "Not
covered, deliberately" / "Deliberately not covered" / "Explicitly not covered"
(`package-and-service-bundles`, `visitation-booking`, `memorial-and-qr`).
Worth matching in the plan doc to name what L7 is **not** building: merchant
registry, `PaymentIntentDecision::Allowed`, invoice production, Pre-Need payment.
