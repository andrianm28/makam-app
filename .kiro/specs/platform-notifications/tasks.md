# Tasks — Platform Notifications

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

Implemented and reconciled on `lane/l2-notifications` (lane Tasks 1–6, 11 Aug 2026); per-AC test evidence is in [`traceability-matrix.md`](traceability-matrix.md).

- [x] Implement recipient resolution from record scope per `notification-matrix.md`. _Requirements: 1, 6_
- [x] Implement server-resolved `WhatsAppMode`. _Requirements: 2_
- [x] Implement per-channel delivery-state recording. _Requirements: 3, 4_
- [x] Implement idempotent dispatch keyed on event/recipient/channel/window. _Requirements: 8_
- [x] Consume notifications from the transactional outbox, never inline. _Requirements: 9_
- [x] Implement versioned templates with a variable allowlist that rejects restricted fields at render time. _Requirements: 11, 13_
- [x] Always create in-app records for admin/operator/vendor using record scope. _Requirements: 7_
- [x] Implement bounded retry and a permanent-failure operational queue. _Requirements: 14_ — escalation lands on the `default` queue (`docs/architecture/queue-and-outbox.md` names no `operations` queue), ops-tagged per the plan's fallback.
- [x] Isolate the `notifications` queue from `critical` and `urgent`. — `docs/architecture/queue-and-outbox.md` §2; dev on-demand / staging max-2 Horizon (§10).
- [x] Add tests: channel failure does not change business state. _Requirements: 5_
- [x] Add tests: recipient scope correctness and cross-scope leakage. _Requirements: 6_
- [x] Add tests: no private attachment on any external channel. _Requirements: 10_ — guard is structural (no attachment on the `Channel` contract; restricted variables rejected at render); the real external-channel half is NOT TESTED below.
- [x] Add tests: duplicate outbox delivery produces exactly one notification. _Requirements: 8_

## Design system

This spec owns the **delivery-state contract** the UI renders. Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) §6.8 and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- Three distinct visuals, never collapsed: `success` "Terkirim" · `pending` "Sedang dikirim" · `neutral` "WhatsApp belum tersedia" (when `G-WA-01` closed).
- A static "Email & WhatsApp terkirim" is prohibited — that is the exact failure `AGENTS.md` forbids.
- `EMAIL_IN_APP_FALLBACK` renders an `<x-mk.alert intent=info>` banner per §6.9, read from the server.
- In-app notification lists follow §6.2 for empty state and §3.6 for badges; unread uses `--mk-intent-info-*`, never a red dot (§2.3 forbids urgency pressure).
- Required states per §6: loading · empty ("Belum ada notifikasi") · error · authorization (scope-filtered, no existence leak) · provider unavailable · duplicate-safe · pending · success (quiet) · support · responsive.

## NOT TESTED

The lane's Tasks 1–5 are implemented, committed, and locally green (focused
notification suite passes; CI PostgreSQL evidence still pending). The remaining
NOT TESTED items are honest provider/external gaps, not unimplemented module
surface:

- No email or WhatsApp provider is configured: real provider delivery is NOT
  TESTED. `LogChannel` is the honest dev/CI stand-in (`sent` means "written to
  the dev log", never a real external claim); `NullChannel` stands in for
  closed channels.
- `G-WA-01` is closed: the closed-gate behavior is tested locally (WhatsApp
  recorded `UNAVAILABLE`, UI renders the neutral "WhatsApp belum tersedia"
  state), but the approved-BSP/template-approval flow (AC12's other half) is
  NOT TESTED and out of this lane's scope.
- The K7 contract is external and its actual interface has not been seen.
  `Channel` keeps the module provider-neutral so a later K7 integration swaps
  implementations, not the platform layer.
- No producer exists for any of the six outbox-mapped events yet (booking
  wizard Step 9, availability, quote, and payment domains are unbuilt): the
  consumer is proven against outbox rows recorded directly and is
  correct-but-dormant in production (D3, `ConsumeOutboxNotificationJob` doc
  block).
- `notification-matrix.md` has been reconciled against AC1/AC6 and the
  design-system delivery-state contract (header note, 11 Aug 2026) — including
  the `optional` cell-value ruling and two delivery-rule readings. AC10's
  real-external-channel attachment behavior and AC13's "previewable" UI remain
  NOT TESTED (structural guard and versioned snapshots respectively are
  proven). See `traceability-matrix.md`.
