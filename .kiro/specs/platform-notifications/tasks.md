# Tasks — Platform Notifications

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Implement recipient resolution from record scope per `notification-matrix.md`. _Requirements: 1, 6_
- [ ] Implement server-resolved `WhatsAppMode`. _Requirements: 2_
- [ ] Implement per-channel delivery-state recording. _Requirements: 3, 4_
- [ ] Implement idempotent dispatch keyed on event/recipient/channel/window. _Requirements: 8_
- [ ] Consume notifications from the transactional outbox, never inline. _Requirements: 9_
- [ ] Implement versioned templates with a variable allowlist that rejects restricted fields at render time. _Requirements: 11, 13_
- [ ] Always create in-app records for admin/operator/vendor using record scope. _Requirements: 7_
- [ ] Implement bounded retry and a permanent-failure operational queue. _Requirements: 14_
- [ ] Isolate the `notifications` queue from `critical` and `urgent`.
- [ ] Add tests: channel failure does not change business state. _Requirements: 5_
- [ ] Add tests: recipient scope correctness and cross-scope leakage. _Requirements: 6_
- [ ] Add tests: no private attachment on any external channel. _Requirements: 10_
- [ ] Add tests: duplicate outbox delivery produces exactly one notification. _Requirements: 8_

## Design system

This spec owns the **delivery-state contract** the UI renders. Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) §6.8 and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- Three distinct visuals, never collapsed: `success` "Terkirim" · `pending` "Sedang dikirim" · `neutral` "WhatsApp belum tersedia" (when `G-WA-01` closed).
- A static "Email & WhatsApp terkirim" is prohibited — that is the exact failure `AGENTS.md` forbids.
- `EMAIL_IN_APP_FALLBACK` renders an `<x-mk.alert intent=info>` banner per §6.9, read from the server.
- In-app notification lists follow §6.2 for empty state and §3.6 for badges; unread uses `--mk-intent-info-*`, never a red dot (§2.3 forbids urgency pressure).
- Required states per §6: loading · empty ("Belum ada notifikasi") · error · authorization (scope-filtered, no existence leak) · provider unavailable · duplicate-safe · pending · success (quiet) · support · responsive.

## NOT TESTED

Nothing here is implemented. `notification-matrix.md` exists but has **not** been reconciled against these criteria field by field. No email or WhatsApp provider is configured; `G-WA-01` is closed. The K7 contract is external and its actual interface has not been seen.
