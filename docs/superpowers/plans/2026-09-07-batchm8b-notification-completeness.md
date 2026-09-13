# Phase 3, Batch M8b — Notification completeness/visibility (07 Sep 2026)

Remediation of 7 audit findings from `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`:
NOTIF-01, NOTIF-04, NOTIF-05, NOTIF-06, NOTIF-07, NOTIF-09, NOTIF-13.

## Building on PR #248 (Batch 2E) and PR #252 (Batch M1a)

Neither branch is merged into trunk yet at the time this branch was cut
(`git merge-base --is-ancestor` on both returns false against
`origin/docs/design-system-and-planning`). This branch is cut from trunk,
not from either of those branches, per the standard "one lane, one base"
rule — a normal merge conflict on `ProvisionalAggregateNotificationSubjectSource.php`
and `docs/contracts/notification-matrix.md` is expected whichever of the
three PRs merges second or third, and is for whoever merges to resolve.

What was read from PR #248's diff before starting, so this batch does not
regress or reinvent it:

- `ProvisionalAggregateNotificationSubjectSource` there adds `vendor_order`
  and `marketplace_order` aggregate mappings, BOTH resolving
  `scopeEntityType: ScopeEntityType::VENDOR`. NOTIF-01 below is written so
  that when merged after #248, the vendor-scope mapping already present in
  that branch composes with the new multi-scope shape without further
  vendor-specific work — every `*Subject()` private method returns a LIST
  of scope entities, and the vendor branch's single `VENDOR` entity becomes
  a one-element list under the same shape used everywhere else.
- PR #248 also touched `wizard.blade.php` recipient/template copy — not
  overlapping with NOTIF-09's read-model change below, which touches the
  Screen 4 "Pemberitahuan" card's delivery-status badges. Left alone.

PR #252 (Batch M1a) adds new outbox producers (`MarkMarketplaceOrderPaid`,
`MarkExternalRenewal`, `MarkRenewalPaidExternally`, `AssignWorkOrder`) and a
`notification_templates` migration pattern this batch copies exactly for
NOTIF-13's `visitation_booking` template rows (see
`2026_09_07_100000_add_renewal_marked_external_notification_template.php`
for the shape copied).

## NOTIF-01 — multi-scope recipient resolution

`RecipientResolutionSubject` changes from a single
`scopeEntityType`/`scopeEntityId` pair to a `list<ScopeEntityReference>`
(`scopeEntities`). `hasScopeEntity()` becomes `scopeEntities !== []`.
Backward-compat constructor argument `scopeEntityType`/`scopeEntityId` is
NOT kept — every call site in this codebase is inside this same module and
is updated in this same change (`ProvisionalAggregateNotificationSubjectSource`,
every unit test). A compat shim would let a future caller silently keep
using single-scope semantics without noticing multi-scope now exists.

`RecipientResolver::resolveScopedRecipients()` loops over
`$subject->scopeEntities`, resolving each independently through the same
per-type role lookup and `actorsForEntity()` call as before, deduping into
the same `$seen` set (this makes the existing "overlapping-grant dedupe"
doc-block claim literally true for the first time — the class already
claimed to guard against it before this change, but nothing exercised it
until subjects can genuinely carry more than one scope entity).

`ProvisionalAggregateNotificationSubjectSource::orderSubject()`,
`quoteSubject()` (delegates to `orderSubject()`), and `renewalSubject()`
now additionally include `ScopeEntityType::BUSINESS_ENTITY` /
`'1'` — the same singleton business-entity reference every other admin
scope check in this codebase already grants against (`entity_id: '1'`,
confirmed by grep across `tests/Feature/Filament/**` and
`tests/browser/e2e-admin-vendor.spec.ts` — there is exactly one business
entity fixture and every platform-admin grant in this codebase is scoped to
it). `bookingDraftSubject()` intentionally does NOT gain this: a booking
draft is pre-order, no admin action is expected on a draft, and the matrix's
"Booking draft created" row is `none`/`none`/`none` across the board — there
is nothing for an admin recipient to do with it, so adding the scope would
resolve recipients the matrix does not ask for.

Vendor scope for `vendor_order`/`marketplace_order` is left for whichever
PR (#248 or this one) merges second to reconcile — this branch does not
duplicate PR #248's not-yet-merged `vendor_order`/`marketplace_order`
mapping; it only changes the SHAPE (list instead of single value) that
mapping will need to conform to.

## NOTIF-04 — operator/vendor panel inbox surfaces

`InAppNotificationList` and `InAppNotificationInboxQuery` are unchanged —
both are already actor-scoped via `ScopeAssignmentResolver`, not
panel-scoped. Added:

- `App\Filament\Operator\Pages\InAppNotifications` — same shape as
  `App\Filament\Admin\Pages\InAppNotifications`, mounted in
  `OperatorPanelProvider::panel()`'s `->pages([...])` list.
- `App\Filament\Vendor\Pages\InAppNotifications` — same shape, mounted in
  `VendorPanelProvider::panel()`'s `->pages([...])` list. Meaningful once
  NOTIF-01 above makes `vendor_order`/`marketplace_order` vendor-scoped
  in-app rows resolve (today none do; this still ships the surface so it is
  ready the moment #248 lands, rather than a second follow-up PR).

Both reuse the SAME Blade partial view
(`filament.admin.notifications.in-app-notification-list`) and the same
Livewire component tag — no panel-specific view fork, since the component
itself only ever renders the current actor's own scoped rows regardless of
which panel mounted it.

## NOTIF-05 — genuinely-exponential retry backoff

`RetryFailedDeliveryJob::MAX_ATTEMPTS` raised `3` -> `6`.
`backoffSeconds()` changed from a seconds-scale exponential
(`2**(attempt-1)`, ~1/2/4s, total ~7s) to a minutes-scale one:
`base = min(300, 30 * 2**(attempt-1))` seconds, i.e. 30s / 60s / 120s /
240s / 300s(capped) / 300s(capped) for attempts 1-6, plus the existing
jitter term. Total worst-case window (no jitter) is 1050s (~17.5 minutes) —
"several minutes," not seconds, closing the finding. The `min(300, ...)`
cap this finding calls out as already anticipating a longer window is kept
as the outer clamp on the new base, not replaced.

Chose custom-logic-kept-but-rescaled over moving to the queue job's own
`$tries`/`$backoff`: `RetryFailedDeliveryJob` already separates "queued
attempts" (this job's own dispatch count) from `notification_deliveries.
attempt_count` (the business-level send-attempt counter `SendNotification
ChannelJob::claimDeliveryForChannelJob()` increments) — collapsing onto the
job's own retry mechanism would conflate the two counters, which the
existing design deliberately keeps apart (see `DispatchNotification::
claimDelivery()`'s `attempt_count` increment vs. this job's own dispatch).
Rescaling in place is the smaller, correctness-preserving change.

A regression test (`RetryFailedDeliveryJobBackoffTest`) asserts the summed
backoff across all 6 attempts is at least several minutes (>= 900s) and
that `MAX_ATTEMPTS` is 6.

## NOTIF-06 — failed-delivery visibility

`SpineWatchdogCommand` gains a fourth signal,
`checkFailedDeliveries(int $minutes)`, counting `notification_deliveries`
rows in `FAILED` state whose `updated_at` falls inside the same kind of
recent window the other three signals already use (a new
`--failed-deliveries-window-minutes` option, default 15 — matching the
existing stale-delivery default rather than inventing an unrelated
default). On a nonzero count it returns a message in the same
counts-and-durations-only shape as the other three (no event name,
recipient ref, or delivery id — `AGENTS.md` §Observability), and the
existing `report(new SpineDegradedException($message))` loop in `handle()`
already picks up any new entry in the `$problems` array unchanged — no
escalation-path code duplicated.

New read-only admin page: `App\Filament\Admin\Pages\FailedNotificationDeliveries`
lists `notification_deliveries` rows in `FAILED` (and `UNAVAILABLE`, since
that state is also a terminal-bad, invisible-today state per NOTIF-07's own
finding) states, newest first, with the same `delivery-state-chip` partial
NOTIF-09 reuses — no new visual vocabulary. Read-only: no actions, no
writes; retrying stays `RetryFailedDeliveryJob`'s job, not a page click,
because a manual page-triggered retry would create a second, undocumented
write path for `notification_deliveries` alongside `DispatchNotification`
(the class's own doc block calls out that it is "the ONE write API").

## NOTIF-07 — LOG channel must not render as delivered

Option (a), per the finding's own preference. `LogChannel::send()` now
returns `DeliveryResult(DeliveryState::Unavailable, providerRef: ...,
message: self::LOG_ONLY_MESSAGE)` instead of `DeliveryState::Sent`.
`DeliveryState::presentation()` already renders any non-null
`failure_message` on `Unavailable` as the neutral "Notifikasi tidak
tersedia" (verified by reading the enum directly) — this is a
one-constant, one-line change plus a doc-block update; no changes needed
anywhere else, because `presentation()` was already built to take this
branch. `retryable` is left at its default (`true`) — a mismatch here
would be harmless in practice (there is no channel to actually retry
against; a retry re-runs `LogChannel::send()` and gets the same honest
`Unavailable` outcome again, cheaply) — but `SendNotificationChannelJob`
only re-dispatches a retry when `$result->state === DeliveryState::Failed`,
and `Unavailable` never reaches that branch, so `retryable` is moot here
and left at the class default rather than adding an unused override.

## NOTIF-09 — real delivery state on the booking confirmation screen

`BookingWizard`'s confirmation-state block (around line 1843) now loads
this order's own `notification_deliveries` rows for the CUSTOMER-role
recipient: `notification_events` (`aggregate_type = 'order'`,
`aggregate_id = (string) $order->id`) joined to `notification_deliveries`
via `notification_recipient_id` -> `notification_recipients.id` filtered to
`actor_role = RecipientRole::CUSTOMER`, ordered newest first, one row read
per channel (`EMAIL`, `WA`). Passed to the view as `customerDeliveries:
array<string, ?NotificationDelivery>` keyed by channel.

`wizard.blade.php`'s Screen 4 "Pemberitahuan" card now renders
`<x-filament::delivery-state-chip>`... no — reuses the EXACT existing
`resources/views/filament/admin/notifications/partials/delivery-state-chip.blade.php`
partial (`@include` with `['delivery' => $customerDeliveries['EMAIL']]`)
when a delivery row exists for that channel, falling back to the
pre-existing static "Belum dikirim" pending badge ONLY when no row exists
yet (the outbox has not been drained in the few seconds since submission —
a real, common race, not a bug to paper over). The WhatsApp-gate-closed
branch (`WhatsAppMode::EmailInAppFallback`) is unchanged: that is a real,
already-honest "Belum tersedia" state, not the finding's target.

## NOTIF-13 — visitation request "sent to operator" claim

Option (a) — full wiring, made tractable by NOTIF-01's multi-scope
mechanism landing in this same PR, per the finding's own preference order.

- `ProvisionalAggregateNotificationSubjectSource::visitationBookingSubject()`
  added: `aggregate_type = 'visitation_booking'` (the literal string both
  `RequestVisitation::book()` and `ChangeVisitationBookingStatus::__invoke()`
  already emit onto the outbox — verified by reading both actions directly)
  resolves `ownerRef: null` (a visit request carries `contact_phone`/
  `contact_email` inline, but — like the `renewal` case this class already
  documents — that contact is not a `scope_assignments.actor_identifier`-
  shaped reference to a real actor; there is no customer account here
  either, so this deliberately matches the renewal precedent rather than
  inventing a new prefixed-ownerRef convention for a channel this fix does
  not wire) and a single `ScopeEntityType::CEMETERY` scope entity from
  `visitation_bookings.cemetery_id` (`NOT NULL` on that table).
- New migration `2026_09_07_120000_add_visitation_booking_notification_templates.php`,
  copying PR #252's `renewal_marked_external` migration shape exactly:
  reads two new matrix rows ("Visitation booking requested",
  "Visitation booking confirmed") via `NotificationMatrixSource`, wires
  `outbox_event_name` to `visit.booking_requested.v1` /
  `visit.booking_confirmed.v1`, seeds an immutable version-1 snapshot body.
- `docs/contracts/notification-matrix.md` gains the two rows: Customer
  `none` (no owner reference exists to notify, matching the renewal rows'
  precedent above — NOT a channel this fix silently drops, there was never
  one to keep), Admin platform `none` (a visitation request is
  cemetery-operational, not a platform-admin concern — no matrix row this
  batch touches sends an admin every single scoped event either), Pengelola
  TPU/TPS `IN_APP` for both rows (this is the entire point: the operator
  finally gets a real row), Vendor/Case manager/Finance `none`/`TBD`
  unchanged pattern.
- No emission code changes needed in `RequestVisitation`/
  `ChangeVisitationBookingStatus` — both already record the outbox event
  with the right `aggregate_type`; the gap was purely in subject resolution
  and template mapping, both closed above.
- Operator surface: NOTIF-04's new `App\Filament\Operator\Pages\
  InAppNotifications` page is exactly the surface the finding demands — no
  separate visitation-specific page.
- `resources/views/livewire/public/visitation/page.blade.php`'s "Permintaan
  Anda dikirim ke pengelola lokasi dan menunggu konfirmasi" copy is LEFT
  UNCHANGED: once the wiring above lands, the claim is true — a real
  in-app row is written for a cemetery operator holding a grant on this
  cemetery, and that operator now has a real page to read it from. Kept
  here as an explicit record of that reasoning so a future reviewer does
  not wonder why the copy was in scope but never touched.

## Verification

Run inside a fresh CI-parity Docker image against disposable `postgres:18`
+ `redis:8.2-alpine` containers (`m8b-` prefix) — host PHP is 8.3, too old
for this codebase's baseline. `vendor/bin/pint --test`,
`vendor/bin/phpstan analyse --no-progress`, `bash ci/verify-docs.sh`, and
the full test suite (or at minimum every touched module's test directory)
must be clean before commit. Results recorded honestly in the PR
description — `BLOCKED`/`NOT TESTED` for anything not actually run.
