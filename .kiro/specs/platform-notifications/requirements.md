# Requirements — Platform Notifications

**Authority:** K7 notification contract; `AGENTS.md` §Notifications; `docs/contracts/notification-matrix.md`; gate `G-WA-01`.

**Status:** Foundation P0. Consumed by 6 specs. Blocks booking Step 9. Previously owned by no spec — `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference to these criteria in other documents still points at the same requirement.

1. THE SYSTEM SHALL implement `notification-matrix.md` as the single source of truth for event, recipient scope, channel, and template, and SHALL NOT restate it.
2. THE SYSTEM SHALL resolve `WhatsAppMode` (`ACTIVE` or `EMAIL_IN_APP_FALLBACK`) on the server, per `overview.md` §15.
3. THE SYSTEM SHALL record a delivery state per channel for every notification: queued, sent, delivered, failed, or unavailable.
4. THE SYSTEM SHALL NOT let the UI claim a delivery that lacks a recorded delivery state. WHEN delivery state is absent THE SYSTEM SHALL render it as `pending` or `unavailable`, never as sent.
5. THE SYSTEM SHALL NOT let a channel failure change business state; a failed email SHALL NOT fail an order.
6. THE SYSTEM SHALL resolve recipient scope from record scope: customer, admin, cemetery operator, vendor, case manager, finance.
7. THE SYSTEM SHALL always create required in-app records for admin, operator, and vendor recipients using record scope, independent of external channel success.
8. THE SYSTEM SHALL dispatch idempotently per (event, recipient, channel, window). THE SYSTEM SHALL NOT let a retried job double-send.
9. THE SYSTEM SHALL dispatch notifications from the transactional outbox. THE SYSTEM SHALL NOT dispatch them inline with the state mutation.
10. THE SYSTEM SHALL NOT attach private documents to email or WhatsApp. THE SYSTEM SHALL reference documents by an authenticated link only.
11. THE SYSTEM SHALL NOT include restricted data (KTP, KK, death-certificate content, bank details, full addresses) in a notification body, subject, or template variable.
12. THE SYSTEM SHALL enable WhatsApp only with an approved BSP and approved templates. WHILE `G-WA-01` is closed THE SYSTEM SHALL state in the UI that WhatsApp is unavailable.
13. THE SYSTEM SHALL version templates and make them previewable. THE SYSTEM SHALL NOT let a template change retroactively alter sent records.
14. WHEN a delivery fails THE SYSTEM SHALL make the failure observable and retry it with bounded backoff. THE SYSTEM SHALL escalate permanent failure to an operational queue.

## Negative criteria

- No claim of WhatsApp or email delivery without delivery state.
- No private attachment on any external channel.
- No business-state change caused by a channel outcome.
- No notification sent outside the matrix recipient scope.
- No restricted data in a notification payload or log.
