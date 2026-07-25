# Requirements — Platform Notifications

**Authority:** K7 notification contract; `AGENTS.md` §Notifications; `docs/contracts/notification-matrix.md`; gate `G-WA-01`.

**Status:** Foundation P0. Consumed by 6 specs. Blocks booking Step 9. Previously owned by no spec — `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

1. `notification-matrix.md` is the single source of truth for event, recipient scope, channel, and template. This spec implements it and does not restate it.
2. Channel mode is server-resolved: `WhatsAppMode` is `ACTIVE` or `EMAIL_IN_APP_FALLBACK` per `overview.md` §15.
3. Every notification records a delivery state per channel: queued, sent, delivered, failed, or unavailable.
4. The UI may only claim a delivery that has a delivery state. Absence of state renders as `pending` or `unavailable`, never as sent.
5. Channel failure **never** changes business state. A failed email does not fail an order.
6. Recipient scope is resolved from record scope: customer, admin, cemetery operator, vendor, case manager, finance. Notifying the wrong scope is a security defect.
7. Required in-app records are always created for admin, operator, and vendor recipients using record scope, independent of external channel success.
8. Dispatch is idempotent per (event, recipient, channel, window); a retried job never double-sends.
9. Notifications are dispatched from the transactional outbox, never inline with the state mutation.
10. Email and WhatsApp **never** carry private attachments. Documents are referenced by an authenticated link only.
11. Restricted data (KTP, KK, death-certificate content, bank details, full addresses) never appears in a notification body, subject, or template variable.
12. WhatsApp is enabled only with an approved BSP and approved templates; while `G-WA-01` is closed the UI states WhatsApp is unavailable.
13. Templates are versioned and previewable; a template change does not retroactively alter sent records.
14. Delivery failures are observable and retried with bounded backoff; permanent failure escalates to an operational queue.

## Negative criteria

- No claim of WhatsApp or email delivery without delivery state.
- No private attachment on any external channel.
- No business-state change caused by a channel outcome.
- No notification sent outside the matrix recipient scope.
- No restricted data in a notification payload or log.
