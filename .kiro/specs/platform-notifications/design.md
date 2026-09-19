# Design — Platform Notifications

## Module

`NotificationAdapter` (`overview.md` §5). Consumes outbox events, resolves recipients from record scope, renders a versioned template, dispatches per channel, and records delivery state.

## Data

```text
notification_events          -- consumed outbox event reference
notification_recipients      -- resolved scope, actor reference, role
notification_deliveries      -- channel, state, provider reference, timestamps
notification_templates
notification_template_versions
in_app_notifications         -- always created for admin/operator/vendor
```

`notification_deliveries` is the only source the UI may read to claim a delivery.

## Template variables (AC15, added 19 Sep 2026)

Until 19 Sep 2026 every `TemplateRenderer::render()` call site passed an empty bag, so no template could carry a value. `NotificationVariableResolver` now builds the bag: for a fresh outbox event from the row itself (`DispatchNotification`), and for a channel send from the delivery's `event_id` back to the outbox row (`MailChannel`, `LogChannel`). It consults `Contracts\NotificationVariableSource` implementations registered per aggregate type — the platform ships the payload-derived one; a feature module registers its own (e.g. `OrderWorkflow` for `order`) so the platform never imports a Domain model — and restricts the merged bag to the version's `variable_allowlist` before rendering, so a version-1 template with an empty allowlist keeps rendering. Values must be scalar or `Stringable`; a document list is pre-joined text. Placeholders are `{{ name }}` only; the two version-2 bodies that used single braces are superseded by version-3 rows.

## Sequence

```text
outbox event -> resolve recipients (record scope)
             -> per recipient/channel: idempotency key
             -> render versioned template (no restricted fields)
             -> dispatch -> record delivery state
             -> in-app record always written
```

Idempotency key: `event_id + recipient_id + channel + window`.

## Channel modes

`ACTIVE` uses WhatsApp plus email. `EMAIL_IN_APP_FALLBACK` uses email plus in-app and reports WhatsApp as unavailable — an explicit state, not a silent omission.

## Template safety

Templates declare an allowlist of variables. Restricted classifications are rejected at render time, not by reviewer discipline. Attachments are structurally impossible on external channels; documents are referenced by an authenticated link.

## Queue

Notifications run on the `notifications` queue per `queue-and-outbox.md`, isolated from `critical` and `urgent`, and must not be starved by `imports`/`media`/`reports`.

## Observability

Delivery state distribution per channel, failure rate, retry depth, permanent-failure queue age, template version in use, recipient-scope resolution errors.
