# Platform Notifications — Implementation Plan (Lane L2, Wave 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement `platform-notifications` (`.kiro/specs/platform-notifications/`) as a real `app/Platform/Notification/**` module: outbox-consumed dispatch, record-scope recipient resolution per `notification-matrix.md`, server-resolved `WhatsAppMode`, per-channel delivery-state recording, versioned templates with a restricted-field variable allowlist, bounded retry with a permanent-failure operational queue, and always-written in-app records for admin/operator/vendor — with no external provider configured and `G-WA-01` closed.

**Architecture:** `NotificationAdapter` (overview.md §5) consumes outbox events, resolves recipients from record scope, renders a versioned template (no restricted fields), dispatches per channel, and records delivery state. `notification_deliveries` is the **only** source the UI may read to claim a delivery. Channels are behind a provider-neutral `Channel` interface; dev runs the `LogChannel` (delivery state recorded honestly as `sent` to the audit log — never claimed as real WhatsApp/email), so the module is fully testable with no provider. `WhatsAppMode` is server-resolved from `ModeResolver` (`G-WA-01`), matching the established `PaymentMode` pattern; while closed, the UI renders "WhatsApp belum tersedia" (`neutral`) — an explicit state, never a silent omission.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, PostgreSQL 18, Pest/PHPUnit, Redis queue via Horizon on the `notifications` queue (queue-and-outbox §2/§3).

---

## Current state — read this before planning any change

### What is already built

- `app/Platform/Notification/` contains only `.gitkeep` — the module does not exist.
- `WhatsAppMode` enum exists (`app/Platform/FeatureGate/Modes/WhatsAppMode.php`) and `ModeResolver::whatsAppMode()` resolves it from `G-WA-01` — server-side resolution is already the established, tested pattern (`PaymentMode`/`UrgentMode` precedent).
- The transactional outbox is real and proven: `Outbox::record()` (`app/Platform/Outbox/Outbox.php`), `OutboxPublisher` claim loop, `OutboxQueueRouter` (event→queue; `notifications` queue exists as `OutboxQueueName::Notifications`), `PublishOutboxEventJob`. `OutboxBookingDraftPublicationTest` proves a real producer's row is claimed and dispatched; `OutboxRecoveryTest` runs the job to completion. The retrofit plan explicitly notes provider/notification propagation was NOT covered because no notification classes existed — **this lane closes that gap**.
- `docs/contracts/notification-matrix.md` v0.4 is the canonical matrix (event × recipient × channel × template). `docs/contracts/outbox-event-contract.md` v1 defines the envelope. `event-catalog.md` names the producer events (`payment.received.v1`, `availability.confirmed.v2`, `quote.issued.v1`, `order.status_changed.v1`, `funeral_case.created.v1`, `grave.reminder_sent.v1`, etc.).
- `Audit::record()`/`wrap()`, `SensitiveActions`, `ActorContext`/`ScopeAssignment` (record scope) all exist and are tested — this module consumes them, never re-implements.
- `queue-and-outbox.md` §2 gives the `notifications` queue (priority 3) and §10 the combined-host worker profile (staging: one constrained Horizon across critical/urgent/notifications/default, max 2 processes; dev: on-demand `--stop-when-empty`).

### Status / NOT TESTED

`platform-notifications/tasks.md:29-31` is the authority: "Nothing here is implemented. `notification-matrix.md` exists but has **not** been reconciled against these criteria field by field. No email or WhatsApp provider is configured; `G-WA-01` is closed. The K7 contract is external and its actual interface has not been seen."

This lane does **not** provision an email/WhatsApp provider and does **not** open `G-WA-01`. It builds the module behind a `Channel` interface, ships the `LogChannel` for dev/CI, and enforces the honest-delivery-state contract so that when a provider IS added, nothing about the platform layer changes.

### What the spec requires (AC → design mapping)

| AC | Requirement (abridged) | Design surface |
|---|---|---|
| 1 | `notification-matrix.md` is the single source of truth; do not restate | `RecipientResolver` reads the matrix via a `NotificationMatrix` reader; the module never hardcodes an event→recipient table |
| 2 | Server-resolve `WhatsAppMode` | `ModeResolver::whatsAppMode()` injected at dispatch time |
| 3 | Per-channel delivery state: queued, sent, delivered, failed, unavailable | `notification_deliveries` rows written per (event, recipient, channel) |
| 4 | UI never claims delivery without recorded state; absent → pending/unavailable | `DeliveryState::fromRecord()`; views only read `notification_deliveries` |
| 5 | Channel failure never changes business state | Dispatch job never throws out of the business transaction; delivery state recorded independently |
| 6 | Recipient scope from record scope: customer, admin, cemetery operator, vendor, case manager, finance | `RecipientResolver` maps record-owner + scope assignments |
| 7 | Always create in-app records for admin/operator/vendor, independent of external success | `in_app_notifications` rows created even when email/WA channel fails or is unavailable |
| 8 | Idempotent dispatch per (event, recipient, channel, window); no double-send | DB unique on `(event_id, recipient_ref, channel, window_key)` |
| 9 | Dispatch from transactional outbox, never inline | Consumer is a queue job fed by `OutboxPublisher`; inline dispatch API is absent by construction |
| 10 | No private attachments; authenticated link only | Template variables can only be reference-style; no attachment field exists on any channel |
| 11 | No restricted data in body/subject/template variable | `TemplateRenderer` allowlist per template; render-time rejection of restricted classifications/fields |
| 12 | WhatsApp only with approved BSP+templates; while `G-WA-01` closed state it in UI | `WhatsAppMode::EMAIL_IN_APP_FALLBACK` → channel list omits WA, UI shows neutral "belum tersedia" |
| 13 | Versioned templates, previewable; no retroactive change to sent records | `notification_templates` + `notification_template_versions`; sent deliveries pin the version |
| 14 | Failures observable, bounded retry, permanent failure escalates to operational queue | `DeliveryState::FAILED` → bounded backoff retry → `OPERATIONAL` queue after max attempts |

## NOT TESTED (this lane)

- Real email/WhatsApp provider delivery (no provider configured; `LogChannel` is the honest dev stand-in, and `G-WA-01` stays closed).
- WhatsApp BSP template approval flow (explicitly out of scope; requires an approved BSP).
- K7 external interface (contract not seen; `Channel` interface keeps the platform provider-neutral).

## Global Constraints

- `notification-matrix.md` is the authority (AC1). The module reads it; it never restates an event→recipient table in code. Any divergence is a documentation defect, fixed by updating the matrix, never by forking the behavior.
- One write API for `notification_deliveries`: only the dispatch path writes rows. No model `::create()` bypass.
- Honest state: no "sent"/"delivered" claim without a recorded provider or internal delivery state (AC4). `LogChannel` writes `sent` ONLY to the dev log/console channel — never to a real external claim.
- Channel failure never changes business state (AC5, `AGENTS.md` §Notifications). The consumer job and the business transaction are decoupled by the outbox; the job's exceptions never roll back the mutation that produced the event.
- Restricted data never enters a body, subject, template variable, payload, or log (AC11, `AGENTS.md` §Authorization and files, `outbox-event-contract.md` rule 3). Restricted field names are rejected at render time by the allowlist, not by reviewer discipline.
- No private attachment on any external channel (AC10); documents are referenced by authenticated link only — the `DocumentVaultAdapter` (L1) supplies such links.
- Idempotency is a DB constraint (AC8, `AGENTS.md` §Queue and event reliability: at-least-once delivery, idempotent consumers), not a pre-check that can race.
- `notifications` queue is isolated from `critical`/`urgent` and must not be starved by `imports`/`media`/`reports` (`queue-and-outbox.md` §2/§10; combined host max 2 processes, dev on-demand).
- Restricted data never appears in Pulse/Horizon tags or error trackers (`AGENTS.md` §Observability).
- Capacity: worktree + staggered CI per Wave 0 S4-T9 baseline.
- No new `SensitiveActions` entries in this lane.

## File Structure

New files under `app/Platform/Notification/`:

| File | Responsibility |
|---|---|
| `Contracts/Channel.php` | `send(DeliveryRequest): DeliveryResult` (success/failure with provider ref) |
| `Contracts/NotificationMatrixSource.php` | reads event→recipient-scope/channel/template rows from `notification-matrix.md` (seed) |
| `RecipientResolver.php` | AC6: record-scope resolution to concrete actors (customer + scoped admin/operator/vendor/case-manager/finance) |
| `WhatsAppModeResolver.php` | AC2: server-side mode from `ModeResolver::whatsAppMode()` |
| `DeliveryState.php` | closed-list enum `QUEUED|SENT|DELIVERED|FAILED|UNAVAILABLE` |
| `NotificationPriority.php` | closed-list enum per matrix channel routing |
| `TemplateRenderer.php` | AC11/AC13: versioned render with allowlist, restricted-field rejection |
| `Actions/DispatchNotification.php` | the ONE dispatch action (called by the outbox consumer) |
| `Actions/RecordInAppNotification.php` | AC7: always-write in-app record for admin/operator/vendor |
| `Jobs/ConsumeOutboxNotificationJob.php` | outbox-fed consumer, idempotent, bounded retry |
| `Jobs/RetryFailedDeliveryJob.php` | bounded backoff retry with escalation |
| `Channels/LogChannel.php` | dev/CI channel: honest `sent` to the dev log only |
| `Channels/NullChannel.php` | unavailable-channel stand-in (`UNAVAILABLE` state) |
| `Models/NotificationEvent.php` | consumed outbox event reference (idempotency anchor) |
| `Models/NotificationRecipient.php` | resolved scope, actor reference, role |
| `Models/NotificationDelivery.php` | per-channel delivery state (the only UI-truth source) |
| `Models/InAppNotification.php` | in-app record for admin/operator/vendor |
| `Models/NotificationTemplate.php`, `Models/NotificationTemplateVersion.php` | versioned templates |
| `NotificationServiceProvider.php` | binds `Channel` → LogChannel (dev), NullChannel for WA when closed |
| `DatabaseNotificationListener.php` | consumes Laravel `DatabaseNotification`? No — uses its own `in_app_notifications` table via `RecordInAppNotification` |

Migrations (all additive, `2026_08_09_*`): `create_notification_templates_table`, `create_notification_template_versions_table`, `create_notification_events_table`, `create_notification_recipients_table`, `create_notification_deliveries_table`, `create_in_app_notifications_table`, plus a seed migration loading the `notification-matrix.md` rows into `notification_templates`/`notification_template_versions` (version 1 of each canonical template; the matrix remains the durable source, the seed is a snapshot for the versioned renderer). No changes to `Outbox`/`OutboxPublisher` beyond a new consumer wiring and possibly a new `OutboxQueueRouter` route for any event whose queue is `notifications` (already covered by the existing default route behavior — verify, don't assume).

---

## Task 1: Templates + versions + matrix seed

**Files:** migrations `2026_08_09_100000_create_notification_templates_table.php`, `..._100010_create_notification_template_versions_table.php`, seed migration; `Models/NotificationTemplate.php`, `Models/NotificationTemplateVersion.php`; `NotificationMatrixSource` implementation reading the matrix doc.

- `notification_templates`: `id`, `event_name` (unique per event), `default_channel` (EMAIL|WA), `active_version_id` (nullable, FK). `notification_template_versions`: `id`, `template_id` FK, `version` (int), `subject` (nullable), `body`, `variable_allowlist` (jsonb array — the ONLY variables `TemplateRenderer` may substitute), `restricted_fields` (jsonb array — render-time rejection list), `created_by`, `created_at`. Partial unique `(template_id, version)`.
- The seed migration reads `docs/contracts/notification-matrix.md` and materializes version 1 rows for every matrix event (subject/body templated from the matrix's own language; where the matrix gives no body text, the seed body is the matrix row's recipient/channel facts — marked as such, never invented copy). This is a snapshot; the matrix stays the durable authority (AC1).
- `TemplateRenderer`: substitutes ONLY allowlisted variables; throws on a restricted-field variable (AC11). Render of a non-allowlisted variable is a hard error, not a silent blank.

- [ ] **Step 1:** Write migrations with DB CHECK on `variable_allowlist`/`restricted_fields` as jsonb (no closed-list enum — the list is data, owned by the matrix).
- [ ] **Step 2:** Models with `$guarded = ['*']` and doc blocks.
- [ ] **Step 3:** Seed migration for the matrix snapshot.
- [ ] **Step 4:** Tests: version is immutable (a new version does not alter a sent delivery's pinned version); render rejects a restricted field even if allowlisted (restricted list wins); render rejects a non-allowlisted variable; matrix-vs-seed reconcile test asserts the seed covers every matrix event (prevents drift).

---

## Task 2: Recipient resolution from record scope (AC6, AC1)

**Files:** `RecipientResolver.php`, `Contracts/NotificationMatrixSource.php`

- `RecipientResolver::resolve(OutboxEvent $event) : RecipientSet`
  - Reads the matrix for the event → target recipient classes (customer / admin / cemetery operator / vendor / case manager / finance) and per-class scope rule.
  - Customer: the record's owner (e.g. `booking_drafts.customer_user_id`, `orders.customer_user_id`, `grave_records.owner`). Admin: actors with admin scope assignments. Operator/vendor/case-manager/finance: resolved via `ScopeAssignment` on the record's cemetery/order/case (record scope, per `AGENTS.md` §Authorization: scope by cemetery, vendor, order, case, grave, and business entity).
  - Each resolved recipient carries `actor_ref`, `actor_role`, and the scope entity it was resolved from. Cross-scope leakage is prevented by construction: resolution only reads records the event's aggregate references.
  - Unknown event → resolve to empty set, log, no-op (never throw into business state).

- [ ] **Step 1:** Implement resolver reading the matrix source + `ScopeAssignmentResolver`.
- [ ] **Step 2:** Tests: booking-submitted resolves customer + location operator + admin; vendor event resolves only assigned vendor; cross-scope leakage test (a vendor with a scope on a different order resolves to nothing for this order); matrix event with `none` everywhere resolves empty.

---

## Task 3: Dispatch + delivery-state recording (AC3, AC4, AC7, AC8, AC9)

**Files:** `Actions/DispatchNotification.php`, `Actions/RecordInAppNotification.php`, `Models/NotificationEvent.php`, `Models/NotificationRecipient.php`, `Models/NotificationDelivery.php`, `Models/InAppNotification.php`, `Jobs/ConsumeOutboxNotificationJob.php`, `NotificationServiceProvider.php`

- `ConsumeOutboxNotificationJob`: the outbox-fed consumer (registered to receive `OutboxEventPublished` for notification-classified events; the existing `PublishOutboxEventJob` already routes). Steps in ONE transaction:
  1. Upsert `notification_events` from the outbox envelope (`event_id` = outbox `id`/`event_id`, `event_name`, `aggregate_type/id`, `trace_id`) — unique on `event_id` (idempotency anchor, AC8).
  2. `RecipientResolver::resolve()`.
  3. Per recipient × channel from the matrix (channels filtered by `WhatsAppMode`: `EMAIL_IN_APP_FALLBACK` drops WA — AC12/AC2):
     - Idempotency key = `(event_id, recipient_ref, channel, window_key)` — window is a bounded time bucket (e.g. `{event_id}` for transactional events; reminder events use the event's own window) enforced by a DB unique constraint. A retried job collides and no-ops (AC8, at-least-once safety).
     - Insert `notification_recipients` + `notification_deliveries` with `QUEUED`.
  4. `RecordInAppNotification` for every admin/operator/vendor recipient (AC7), independent of channel outcome — created in the same transaction as the event row, so a failed email can never erase the in-app record.
  5. Render via `TemplateRenderer` with the pinned template version.
  6. Dispatch each channel job (channels run on the `notifications` queue; each channel is a separate job so one channel's failure can't block another, and the consumer returns success regardless — AC5).
- `DispatchNotification` is the only class that writes `notification_deliveries`. There is no inline `send()` API callable from a business action — AC9 by construction.

- [ ] **Step 1:** Implement consumer + recipient/delivery writes + in-app records.
- [ ] **Step 2:** Channel-job dispatch on the `notifications` queue.
- [ ] **Step 3:** Tests: duplicate outbox delivery produces exactly one notification (AC8); in-app record created even when the email channel fails or is unavailable (AC7); a channel failure leaves the business record unchanged (AC5); UI-truth test: delivery table is the only source a view can read.

---

## Task 4: Channels + delivery outcomes + retry/escalation (AC4, AC14, AC2, AC12)

**Files:** `Contracts/Channel.php`, `Channels/LogChannel.php`, `Channels/NullChannel.php`, `Jobs/RetryFailedDeliveryJob.php`, `DeliveryState.php`, `NotificationPriority.php`

- `Channel` contract: `send(NotificationDelivery, NotificationTemplateVersion, RecipientSet): DeliveryResult {state, providerRef?, message?}`.
- `LogChannel`: dev/CI stand-in — logs the rendered body to the dev log, returns `sent` with a synthetic provider ref. Explicitly NOT a real external delivery (the module never claims provider delivery that didn't happen — dev `sent` means "written to dev log", which the delivery-state UI distinguishes by channel).
- `NullChannel`: returns `UNAVAILABLE` — used for WA while `G-WA-01` is closed (AC12). UI renders the neutral "WhatsApp belum tersedia" state.
- Channel outcome → `notification_deliveries.state`:
  - `sent` → provider accepted; `delivered` → provider confirmed (only if the provider supplies a delivered event; `LogChannel` stays `sent`).
  - `failed` → transient: bounded exponential backoff with jitter via `RetryFailedDeliveryJob`, max attempts per `queue-and-outbox.md` §8 (no retry for permanent validation/authorization errors). After max attempts → `FAILED` + escalation to the operational queue (a separate `operations` queue name if present in `queue-and-outbox.md`, else the `default` queue with an ops-tagged job; verify against the doc, don't assume a queue name that doesn't exist).
  - `unavailable` → recorded as `UNAVAILABLE`, never retried (channel is off).
- `DeliveryState` mapping for the UI (tasks.md §Design system): `success` "Terkirim" (sent/delivered) · `pending` "Sedang dikirim" (queued) · `neutral` "WhatsApp belum tersedia" (unavailable, WA closed) · `danger` for failed with retry status shown.

- [ ] **Step 1:** Channel contract + LogChannel + NullChannel.
- [ ] **Step 2:** Channel jobs + state transitions.
- [ ] **Step 3:** `RetryFailedDeliveryJob` bounded backoff + escalation.
- [ ] **Step 4:** Tests: failed → retried → after max → operational queue; permanent validation error not retried; `unavailable` never retried and never claims sent; delivery-state UI mapping test (three distinct visuals never collapsed); no "Email & WhatsApp terkirim" static string anywhere (grep gate).

---

## Task 5: In-app notification list contract + routes (read surface)

**Files:** `routes/web.php` (admin/vendor panel routes), Livewire component + partials for the in-app list, `NotificationPriority`

- In-app notification list for admin/operator/vendor panels: scope-filtered by the actor's own scope assignments (no existence leak — a user only sees records they're scoped to). Empty state "Belum ada notifikasi". Unread uses `--mk-intent-info-*`, never a red dot (design-system §3.6/§2.3).
- Unread-count badge for panels. Read/unread transitions audited via `Audit::record('NOTIFICATION_READ', ...)` (non-sensitive).
- The delivery-state list renders from `notification_deliveries` only (AC4) — never from order/booking status.

- [ ] **Step 1:** Scope-filtered list Livewire + routes for admin panel and vendor panel.
- [ ] **Step 2:** Unread badge + read transition.
- [ ] **Step 3:** Tests: actor sees only scoped in-app records; empty state; delivery list renders `pending`/`unavailable` never a false `sent`; read action audited.

---

## Task 6: Doc reconciliation (AC1, matrix-vs-spec)

**Files:** `docs/contracts/notification-matrix.md`, `.kiro/specs/platform-notifications/{tasks.md,traceability-matrix.md}`

- Reconcile `notification-matrix.md` against the ACs field by field (the tasks.md NOT TESTED note flags this as never done). Add a header note recording the reconciliation date and the resolution of any gap (e.g. the matrix's `optional` channel cells — resolved as: optional means "emitted when the record has the recipient", never "skip on failure").
- Mark the spec tasks closed per this plan's traceability; record any AC overclaim corrections per the append-correction precedent.

- [ ] **Step 1:** Reconcile + annotate the matrix.
- [ ] **Step 2:** Update tasks.md/traceability-matrix.md.
- [ ] **Step 3:** Test that `NotificationMatrixSource` reads the reconciled matrix (a `docs` check in CI, mirroring the existing `ci/verify-docs.sh` gate if one exists — verify, don't assume).

---

## Task 7: Review slices, fix wave, re-review

### 7a. Task-scoped review slices (dispatched concurrently)

1. **Delivery-state/honesty slice** — AC3, AC4, AC5, AC14: delivery-state recording, UI-truth, no false-sent claim, channel-failure isolation, bounded retry/escalation.
2. **Scope/privacy slice** — AC6, AC7, AC10, AC11: recipient-scope resolution, cross-scope leakage, in-app always-write, no private attachments, restricted-field rejection.
3. **Idempotency/outbox slice** — AC8, AC9, AC2, AC12: outbox-consumed dispatch, idempotency key correctness, no double-send under retry, server-resolved mode, WA-closed behavior.

### 7b. Bounded fix wave + 7c. Scoped re-review + 7d. Doc correction

Per the two-tier review convention (findings triaged Critical/Important/Minor; Critical + Important get one bounded fix wave with regression tests; Minor ledgered unless trivial; doc overclaims corrected).

---

## Task 8: Finish the branch

- [ ] Merge to trunk `docs/design-system-and-planning` via PR against the Wave 1 review checkpoint.
- [ ] Update `sprint-plan.md` row **S6** (`platform-notifications` (full)) — mark build complete with PR + CI run, and append the no-provider / `G-WA-01`-closed NOT-TESTED note.
- [ ] Update `docs/planning/retrofit-backlog.md` §2 for surfaced findings, if any.
- [ ] Verify static analysis, tests on PostgreSQL 18, and the Blade content-survival gate in CI (staggered per Wave 0 capacity baseline).

## Verification

- [ ] `vendor/bin/pest` green on PostgreSQL 18, including `tests/Feature/Notification/` and `tests/Unit/Platform/Notification/`.
- [ ] Duplicate outbox delivery → exactly one notification; in-app record survives channel failure; cross-scope leakage denied; restricted-field render rejected; delivery-state UI never overclaims — all proven by non-vacuous tests.
- [ ] No restricted data in any payload/log; no private attachment path on any channel.
- [ ] `grep -rn "Email & WhatsApp terkirim" app resources` returns nothing (prohibited string absent).
- [ ] Static analysis + lint clean; Blade content-survival gate passes.
