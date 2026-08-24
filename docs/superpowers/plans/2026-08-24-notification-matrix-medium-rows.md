# Notification Matrix Medium Rows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close 5 more rows of `docs/contracts/notification-matrix.md`'s 17-row matrix — Payment failed/exception, Marketplace order submitted, Vendor accepted/rejected, Vendor evidence uploaded, Renewal submitted — by adding a real, catalogued outbox event to each row's already-correct domain Action, and wiring the notification template to consume it. This closes the "5 rows with real, correct domain logic but zero outbox events" half of `docs/testing/release-gates.md`'s "Notification matrix implemented" box, following the earlier `docs/superpowers/plans/2026-08-24-release-gates-phase1-closeout.md` "CHEAP" pass that closed 3 different rows via a different mechanism (bridging an existing ambiguous event) — this pass adds genuinely new events instead, since none of these 5 rows have one today.

**Architecture:** No new subsystems. Each task adds exactly one `Outbox::record()` call to an existing, already-correct domain Action (following the real, established pattern `App\Domain\Quotation\Actions\IssueQuote` already uses), catalogues the new event name in `docs/contracts/event-catalog.md`, and adds one `match()` arm to `database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php`'s `outboxEventName()` method. **No new listener class is needed for any of these 5 rows** — unlike the earlier CHEAP-set pass (which needed `DispatchOrderNotifications` because `order.status_changed.v1` is inherently ambiguous across multiple matrix rows), each of these 5 events is its own, unambiguous, one-to-one outbox event name. The already-registered generic consumer (`DispatchNotificationConsumerOnOutboxEventPublished` → `ConsumeOutboxNotificationJob` → `DispatchNotification::consumeOutboxEvent()`) resolves the template by `WHERE outbox_event_name = <the new event's name>` automatically — confirmed by reading `DispatchNotification.php`'s real lookup logic, which falls back to that column exactly when no explicit `$matrixEventName` is passed (the generic listener never passes one).

**Tech Stack:** Laravel 13 / PHP 8.5, PostgreSQL 18 transactional outbox pattern.

**Spec:** No separate spec document — this plan's scope was researched this session (a dedicated research pass mapped every remaining notification-matrix row by real implementation cost) and approved by the user, continuing directly from the just-merged CHEAP-set pass (PR #158).

## Global Constraints

- Every new/modified PHP file needs `declare(strict_types=1);`.
- **Never invent an event name without cataloguing it** (`AGENTS.md`'s finding N-12 / this codebase's own repeated discipline this session) — every new event name this plan introduces must be added to `docs/contracts/event-catalog.md` in the SAME task that starts emitting it, following that file's real existing row format (`| \`event.name.v1\` | Producer | Main consumers | Notes |`).
- **Ruling, made at plan-writing time, binding on Tasks 1 and 3**: two of these five matrix rows — "Payment failed/exception" and "Vendor accepted/rejected" — each name ONE matrix row but correspond to TWO real, distinct outcomes in code (Failed vs. Expired; Accepted vs. Rejected). `notification_templates.outbox_event_name` is a single column per row, so it cannot hold two event names for one matrix row. Rather than inventing a second listener-bridge class for these two rows (the more expensive CHEAP-set-style mechanism), both tasks emit **one** catalogued event per row, carrying the real outcome as a `data` field (`'outcome' => $state->value`-shaped), mirroring the established real precedent `order.status_changed.v1` already sets (one event, `to_status` carried as data, consumers/copy discriminate on the field). Do not deviate from this ruling by inventing two separate event names for either row — that would need a second listener class this plan deliberately avoids, for consistency between the two tasks that share this exact shape.
- Follow this repo's evidence-citation discipline for the `docs/testing/release-gates.md` box update in the final task — cite real test names, never overclaim.
- `Outbox::record()` calls must sit inside the SAME database transaction as the write they accompany (the established pattern in `IssueQuote.php`, `ApplyPaidEffects.php`, `RecordOrderStatusChange.php`) — never emitted after a transaction has already committed.
- `AGENTS.md` §Observability: outbox event `data` payloads carry references only (IDs, status/outcome values) — never amounts, never restricted/PII data. Confirm this against `IssueQuote.php`'s own real payload (`quote_id`, `order_id`, `version_number`, `status` — no money figures) as the concrete precedent to match.
- No AWS; no production-affecting/security/authorization/financial/DNS/firewall config changes without human review. None of these 5 tasks should need that (pure additive event emission, no schema changes), but flag if any task's real scope turns out to touch such an area.
- Composer/npm builds do not run on this host — CI only. If any task needs a new package, that's a blocker to flag, not something to route around.
- This host cannot run npm/composer builds, but CAN run PHP/PHPUnit against real Postgres 18 + Redis 8.2 via Docker, using the pinned `ghcr.io/andrianm28/makam-app` image (any locally-cached digest is fine — they are all the same pinned build) — the established, working recipe from every prior task this session: `docker run --network host --user 1000:1000 -e DB_CONNECTION=pgsql ... <image> php -d memory_limit=512M vendor/bin/phpunit <paths>`. Use `vendor/bin/phpunit` directly, never `php artisan test` (its wrapper produces misleading truncated output on this host, unrelated to real test outcomes — confirmed this session).
- Each task independently closes exactly one named notification-matrix row with real, run evidence (never an unexecuted claim) — matching this repo's Task Right-Sizing convention. The final task (6) closes the release-gates.md box text for all 5 together, since they share one compound claim.

---

### Task 1: Payment failed/exception → `payment.outcome_failed.v1`

**Files:**
- Modify: `app/Platform/Payment/Actions/ApplyPaymentSettlement.php`
- Modify: `docs/contracts/event-catalog.md`
- Modify: `database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php`
- Test: `tests/Feature/Payment/ProcessWebhookEventTest.php` (extend the existing file)

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent) — Tasks 1-5 do not interact with each other.

- [ ] **Step 1: Read the real current file and confirm the exact insertion point**

Read `app/Platform/Payment/Actions/ApplyPaymentSettlement.php` in full. Confirm the real `applyOutcome()` method (found this session around lines 213-226) handles both `SessionState::Failed` and `SessionState::Expired` through one `$state` variable, and confirm the exact line where `$this->transitionToTerminal($session, $state);` (or its real equivalent name — verify against the current file, don't assume the method name is still exactly this) runs, still inside the same transaction the rest of the settlement write uses. This is your insertion point — add the `Outbox::record()` call immediately after it, still inside the transaction.

Per this plan's own Global Constraint (binding, do not deviate): this is ONE matrix row for TWO real outcomes (Failed/Expired) — emit ONE event carrying the outcome as data, do not invent two event names or a second listener class.

- [ ] **Step 2: Add the `Outbox::record()` call**

Follow `app/Domain/Quotation/Actions/IssueQuote.php`'s real, established pattern exactly (read it yourself first — this is the illustrative shape, not verbatim-copyable since your real variable names will differ):

```php
Outbox::record(
    eventName: 'payment.outcome_failed.v1',
    eventVersion: 1,
    aggregateType: 'payment_session',
    aggregateId: $session->getKey(),
    data: [
        'session_id' => $session->getKey(),
        'order_id' => $session->order_id, // confirm the real column/relation name against the file
        'outcome' => $state->value,
    ],
    classification: OutboxClassification::Internal,
    idempotencyKey: "payment_outcome:{$session->getKey()}:{$state->value}",
);
```

Confirm the real `PaymentSession` model's actual column names for `order_id`-equivalent and any other reference before writing this — do not guess a column name. The idempotency key must be unique per session+outcome (a session can only reach one terminal failed/expired outcome once, but do not assume — read `transitionToTerminal`'s own guard logic to confirm a session can't reach this branch twice for the same outcome, and adjust the key shape if that assumption is wrong).

- [ ] **Step 3: Catalogue the new event**

Add a new row to `docs/contracts/event-catalog.md`, matching its real existing table format exactly (read a few neighboring rows first, e.g. `payment.received.v1`'s row, to match column content style):

```
| `payment.outcome_failed.v1` | PaymentAdapter | Notification | Carries `outcome` (Failed/Expired) — one event for one matrix row, not two |
```

- [ ] **Step 4: Wire the notification template**

In `database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php`'s `outboxEventName()` method, add:

```php
'Payment failed/exception' => 'payment.outcome_failed.v1',
```

Confirm `'Payment failed/exception'` is the exact, character-for-character matrix row label (check `docs/contracts/notification-matrix.md` and the migration's own existing array directly — do not assume the string from this brief is exactly right, verify it).

Read this migration's own class-level doc block (mentions "every other row is NULL... Do not add to this list without verifying the counterpart against the catalogue and updating that doc block") and update that doc block's own prose to reflect this new addition, matching its existing style.

- [ ] **Step 5: Write a Feature test**

Extend `tests/Feature/Payment/ProcessWebhookEventTest.php` (read its real existing conventions first). Add at least 2 tests: a payment session reaching the Failed outcome emits `payment.outcome_failed.v1` with `outcome: 'Failed'` (or whatever the real enum value is — confirm), a session reaching Expired emits the same event name with `outcome: 'Expired'`. Confirm via a real query against the outbox table (matching `OrderNotificationTest.php`'s own `statusChangedFor()`-style helper pattern from the earlier CHEAP-set pass, adapted for this event name) — do not just assert the method ran without error, assert the real outbox row exists with the right event name and data.

- [ ] **Step 6: Run the test against real Postgres**

```bash
docker run -d --name t1-pg -e POSTGRES_USER=testuser -e POSTGRES_PASSWORD=testpass -e POSTGRES_DB=testdb -p <free-port>:5432 postgres:18
docker run -d --name t1-redis -p <free-port>:6379 redis:8.2-alpine
sleep 5
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:RKxTuGlM4MNUB65volwGUsTfCiDumShAS0GGdu5zXn4= \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<port> -e DB_DATABASE=testdb -e DB_USERNAME=testuser -e DB_PASSWORD=testpass \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<port> \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array \
  -v "<worktree-path>":/var/www/html -w /var/www/html \
  <pinned-image> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Payment/ProcessWebhookEventTest.php
docker rm -f t1-pg t1-redis
```

Report real pass/fail output. Use ports that don't collide with anything else running.

- [ ] **Step 7: Run doc gates, pint, phpstan, and commit**

```bash
bash ci/verify-docs.sh
vendor/bin/pint --test app/Platform/Payment/Actions/ApplyPaymentSettlement.php tests/Feature/Payment/ProcessWebhookEventTest.php
vendor/bin/phpstan analyse --no-progress app/Platform/Payment/Actions/ApplyPaymentSettlement.php
git add app/Platform/Payment/Actions/ApplyPaymentSettlement.php docs/contracts/event-catalog.md database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php tests/Feature/Payment/ProcessWebhookEventTest.php
git commit -m "feat(notifications): emit payment.outcome_failed.v1 for the Failed/Expired matrix row"
```

---

### Task 2: Marketplace order submitted → `marketplace_order.submitted.v1`

**Files:**
- Modify: `app/Domain/Marketplace/Actions/PlaceMarketplaceOrder.php`
- Modify: `docs/contracts/event-catalog.md`
- Modify: `database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php`
- Test: `tests/Feature/Domain/Marketplace/PlaceMarketplaceOrderTest.php` (extend the existing file)

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

- [ ] **Step 1: Read the real current file and confirm the exact insertion point**

Read `app/Domain/Marketplace/Actions/PlaceMarketplaceOrder.php` in full. Confirm the real insertion point: inside the existing `DB::transaction()` closure, after the order/items/vendor-orders rows are all created, right before the closure returns (found this session near `return $order->fresh();`, verify against the real current file — line numbers shift).

This row is single-emission, no outcome discrimination needed (unlike Tasks 1/3) — one event, one meaning.

- [ ] **Step 2: Add the `Outbox::record()` call**

Same real pattern as Task 1 Step 2 (read `IssueQuote.php` yourself if you haven't already this session). Illustrative shape:

```php
Outbox::record(
    eventName: 'marketplace_order.submitted.v1',
    eventVersion: 1,
    aggregateType: 'marketplace_order',
    aggregateId: $order->getKey(),
    data: [
        'order_id' => $order->getKey(),
        'customer_ref' => $order->customer_ref, // confirm real column name
    ],
    classification: OutboxClassification::Internal,
    idempotencyKey: "marketplace_order_submitted:{$order->getKey()}",
);
```

Confirm real column/relation names against the file — do not guess.

- [ ] **Step 3: Catalogue the new event**

Add to `docs/contracts/event-catalog.md`:

```
| `marketplace_order.submitted.v1` | Marketplace | Notification | Real customer order submission, one event, no discrimination needed |
```

- [ ] **Step 4: Wire the notification template**

In `outboxEventName()`:

```php
'Marketplace order submitted' => 'marketplace_order.submitted.v1',
```

Verify the exact matrix label string against the real matrix document and migration array, same discipline as Task 1 Step 4. Update the migration's own class doc block to mention this addition.

- [ ] **Step 5: Write a Feature test**

Extend `tests/Feature/Domain/Marketplace/PlaceMarketplaceOrderTest.php`. Add a test asserting a real, successful order placement emits `marketplace_order.submitted.v1` with the real order id in its data — query the outbox table directly, don't just assert no exception was thrown.

- [ ] **Step 6: Run the test against real Postgres**

Same recipe as Task 1 Step 6, targeting `tests/Feature/Domain/Marketplace/PlaceMarketplaceOrderTest.php`.

- [ ] **Step 7: Run doc gates, pint, phpstan, and commit**

```bash
bash ci/verify-docs.sh
vendor/bin/pint --test app/Domain/Marketplace/Actions/PlaceMarketplaceOrder.php tests/Feature/Domain/Marketplace/PlaceMarketplaceOrderTest.php
vendor/bin/phpstan analyse --no-progress app/Domain/Marketplace/Actions/PlaceMarketplaceOrder.php
git add app/Domain/Marketplace/Actions/PlaceMarketplaceOrder.php docs/contracts/event-catalog.md database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php tests/Feature/Domain/Marketplace/PlaceMarketplaceOrderTest.php
git commit -m "feat(notifications): emit marketplace_order.submitted.v1 for the submission matrix row"
```

---

### Task 3: Vendor accepted/rejected → `vendor_order.decided.v1`

**Files:**
- Modify: `app/Domain/Marketplace/Actions/UpdateVendorOrderStatus.php`
- Modify: `docs/contracts/event-catalog.md`
- Modify: `database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php`
- Test: `tests/Feature/Domain/Marketplace/UpdateVendorOrderStatusTest.php` (extend the existing file)

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

- [ ] **Step 1: Read the real current file and confirm the exact discrimination logic**

Read `app/Domain/Marketplace/Actions/UpdateVendorOrderStatus.php` in full. Confirm this is genuinely a single choke-point Action handling ALL 8 `VendorProcessingStatus` values (read `app/Domain/Marketplace/VendorProcessingStatus.php`'s real `KNOWN_STATUSES` list to confirm all 8 and their real constant names — do not assume the names in this brief are exact). Confirm the real `if ($statusChanged)` block (found this session around lines 103-116) and that `$previousStatus` is a real local variable available at that point.

Per this plan's own Global Constraint (binding): this matrix row covers TWO of the 8 real status values — the transition FROM the vendor-pending status TO an accepted outcome, and FROM the same source TO a rejected outcome. Confirm the exact real constant names for "vendor is waiting to decide" and its two decision outcomes (this brief's illustrative names — `MENUNGGU_VENDOR`/`DITERIMA_VENDOR`/`DITOLAK_VENDOR` — are placeholders; read the real enum/constants and use the actual names). Only emit for these 2 specific transitions, matching the exact same "genuinely need discrimination, not just a status match" reasoning the earlier `DITOLAK` fix (release-gates-phase1-closeout plan, Task with the notification-matrix cheap rows) already established as this codebase's real precedent for this class of problem — read that precedent's real committed code (`app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php`, already merged) if useful context.

- [ ] **Step 2: Add the `Outbox::record()` call**

One event, `outcome` carried as data (matching Task 1's identical ruling):

```php
if ($statusChanged && $previousStatus === VendorProcessingStatus::[REAL_WAITING_CONSTANT] && in_array($status, [VendorProcessingStatus::[REAL_ACCEPTED_CONSTANT], VendorProcessingStatus::[REAL_REJECTED_CONSTANT]], true)) {
    Outbox::record(
        eventName: 'vendor_order.decided.v1',
        eventVersion: 1,
        aggregateType: 'vendor_order',
        aggregateId: $vendorOrder->getKey(), // confirm real variable name
        data: [
            'vendor_order_id' => $vendorOrder->getKey(),
            'outcome' => $status,
        ],
        classification: OutboxClassification::Internal,
        idempotencyKey: "vendor_order_decided:{$vendorOrder->getKey()}",
    );
}
```

Replace the bracketed placeholders with the real constant names confirmed in Step 1. Place this after the existing `Audit::record()` call in the same block, still inside whatever transaction wraps the write.

- [ ] **Step 3: Catalogue the new event**

Add to `docs/contracts/event-catalog.md`:

```
| `vendor_order.decided.v1` | Marketplace | Notification | Carries `outcome` (accepted/rejected) — one event for one matrix row, not two, same shape as payment.outcome_failed.v1 |
```

- [ ] **Step 4: Wire the notification template**

```php
'Vendor accepted/rejected' => 'vendor_order.decided.v1',
```

Verify the exact matrix label string, same discipline as prior tasks. Update the migration's class doc block.

- [ ] **Step 5: Write a Feature test**

Extend `tests/Feature/Domain/Marketplace/UpdateVendorOrderStatusTest.php`. Add tests: (a) the waiting→accepted transition emits `vendor_order.decided.v1` with `outcome` matching the accepted constant's value; (b) the waiting→rejected transition emits the same event name with the rejected value; (c) a negative test — transitioning through any of the OTHER 6 status values (pick at least one, e.g. whatever represents "in progress"/"completed" if such exist) does NOT emit this event, proving the discrimination genuinely gates on the right two outcomes and not on every status change this Action handles. This negative test is the load-bearing one, matching this session's established "prove the discrimination actually discriminates" discipline from the earlier `DITOLAK` fix.

- [ ] **Step 6: Run the test against real Postgres**

Same recipe as Task 1 Step 6, targeting `tests/Feature/Domain/Marketplace/UpdateVendorOrderStatusTest.php`.

- [ ] **Step 7: Run doc gates, pint, phpstan, and commit**

```bash
bash ci/verify-docs.sh
vendor/bin/pint --test app/Domain/Marketplace/Actions/UpdateVendorOrderStatus.php tests/Feature/Domain/Marketplace/UpdateVendorOrderStatusTest.php
vendor/bin/phpstan analyse --no-progress app/Domain/Marketplace/Actions/UpdateVendorOrderStatus.php
git add app/Domain/Marketplace/Actions/UpdateVendorOrderStatus.php docs/contracts/event-catalog.md database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php tests/Feature/Domain/Marketplace/UpdateVendorOrderStatusTest.php
git commit -m "feat(notifications): emit vendor_order.decided.v1 for the accepted/rejected matrix row"
```

---

### Task 4: Vendor evidence uploaded → `vendor.evidence_uploaded.v1`

**Files:**
- Modify: `app/Domain/VendorFulfillment/Actions/UploadEvidence.php`
- Modify: `docs/contracts/event-catalog.md`
- Modify: `database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php`
- Test: `tests/Feature/Domain/VendorFulfillment/EvidenceUploadTest.php` (extend the existing file)

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

**Important scope note (already resolved by research, do not re-litigate)**: this matrix row's real target is `App\Domain\VendorFulfillment\Actions\UploadEvidence` — a DIFFERENT domain from Tasks 2/3 (care-subscription grave-care fulfillment, not marketplace). The matrix's adjacent placement of "Vendor accepted/rejected" and "Vendor evidence uploaded" is coincidental wording, not shared domain — confirmed this session by reading `VendorProcessingStatus::KNOWN_STATUSES` directly (no evidence-upload status exists in that 8-value marketplace enum at all). Do not implement this task against `UpdateVendorOrderStatus.php`.

- [ ] **Step 1: Read the real current file and confirm the exact insertion point**

Read `app/Domain/VendorFulfillment/Actions/UploadEvidence.php` in full. Confirm it validates document acceptance, writes a real `WorkEvidence` row, and wraps the mutation in `Audit::wrap()`. This domain already has 4 real catalogued events (confirmed this session: `care.work_order_created.v1`, `care.complaint_filed.v1`, `care.make_good_created.v1`, `vendor.work_completed.v1`) — read at least one of those emission sites in this same domain (grep for `Outbox::record` inside `app/Domain/VendorFulfillment/` or `app/Domain/CareSubscription/`) as your primary reference pattern instead of `IssueQuote.php`, since it's more directly comparable (same domain, same `Audit::wrap()` shape) — confirm whether `Outbox::record()` calls in this domain sit inside or alongside the `Audit::wrap()` closure, and match that exact convention rather than assuming it's identical to `IssueQuote.php`'s plain-transaction shape.

- [ ] **Step 2: Add the `Outbox::record()` call**

Following this domain's own real, established pattern (confirmed in Step 1, not `IssueQuote.php`'s pattern blindly):

```php
Outbox::record(
    eventName: 'vendor.evidence_uploaded.v1',
    eventVersion: 1,
    aggregateType: 'work_evidence',
    aggregateId: $workEvidence->getKey(), // confirm real variable name
    data: [
        'work_evidence_id' => $workEvidence->getKey(),
        'work_order_id' => $workEvidence->work_order_id, // confirm real column
    ],
    classification: OutboxClassification::Internal,
    idempotencyKey: "vendor_evidence_uploaded:{$workEvidence->getKey()}",
);
```

No amounts, no document content, no restricted data in the payload — references only, matching this plan's own Global Constraint and this domain's own established discipline (evidence documents are exactly the class of content `AGENTS.md` §Observability warns about).

- [ ] **Step 3: Catalogue the new event**

Add to `docs/contracts/event-catalog.md`, matching the naming pattern of this domain's existing 4 events (e.g. `vendor.work_completed.v1`'s row style):

```
| `vendor.evidence_uploaded.v1` | VendorFulfillment | Notification | References only — no document content or restricted data |
```

- [ ] **Step 4: Wire the notification template**

```php
'Vendor evidence uploaded' => 'vendor.evidence_uploaded.v1',
```

Verify the exact matrix label string, same discipline as prior tasks. Update the migration's class doc block.

- [ ] **Step 5: Write a Feature test**

Extend `tests/Feature/Domain/VendorFulfillment/EvidenceUploadTest.php`. Add a test asserting a real, successful evidence upload emits `vendor.evidence_uploaded.v1` with the real work-evidence id — query the outbox table directly.

- [ ] **Step 6: Run the test against real Postgres**

Same recipe as Task 1 Step 6, targeting `tests/Feature/Domain/VendorFulfillment/EvidenceUploadTest.php`.

- [ ] **Step 7: Run doc gates, pint, phpstan, and commit**

```bash
bash ci/verify-docs.sh
vendor/bin/pint --test app/Domain/VendorFulfillment/Actions/UploadEvidence.php tests/Feature/Domain/VendorFulfillment/EvidenceUploadTest.php
vendor/bin/phpstan analyse --no-progress app/Domain/VendorFulfillment/Actions/UploadEvidence.php
git add app/Domain/VendorFulfillment/Actions/UploadEvidence.php docs/contracts/event-catalog.md database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php tests/Feature/Domain/VendorFulfillment/EvidenceUploadTest.php
git commit -m "feat(notifications): emit vendor.evidence_uploaded.v1 for the evidence-upload matrix row"
```

---

### Task 5: Renewal submitted → `renewal.submitted.v1`

**Files:**
- Modify: `app/Domain/Renewal/Actions/OpenRenewal.php`
- Modify: `docs/contracts/event-catalog.md`
- Modify: `database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php`
- Test: `tests/Feature/Domain/Renewal/OpenRenewalTest.php` (extend the existing file)

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

- [ ] **Step 1: Read the real current file and confirm the exact insertion point**

Read `app/Domain/Renewal/Actions/OpenRenewal.php` in full. Confirm the real insertion point: inside the existing `DB::transaction()`, right after `Renewal::create()` (found this session near line 82), before the transaction closure continues to create the associated `RenewalQuote`. Confirm whether the event should reference the renewal alone or wait until the quote also exists — read the real file to decide correctly rather than assuming; if the matrix's "Renewal submitted" concept is really about the renewal record's own creation (not the quote), emit right after `Renewal::create()` as planned.

Do NOT reuse `renewal.marked_external.v1` — confirmed this session that name is reserved for the unrelated offline/admin marking path (`MarkExternalRenewal`), a different Action entirely.

- [ ] **Step 2: Add the `Outbox::record()` call**

```php
Outbox::record(
    eventName: 'renewal.submitted.v1',
    eventVersion: 1,
    aggregateType: 'renewal',
    aggregateId: $renewal->getKey(),
    data: [
        'renewal_id' => $renewal->getKey(),
        'grave_record_id' => $renewal->grave_record_id, // confirm real column
    ],
    classification: OutboxClassification::Internal,
    idempotencyKey: "renewal_submitted:{$renewal->getKey()}",
);
```

Confirm real column/relation names against the file — do not guess.

- [ ] **Step 3: Catalogue the new event**

Add to `docs/contracts/event-catalog.md`:

```
| `renewal.submitted.v1` | Renewal | Notification | The online submission path — distinct from renewal.marked_external.v1's offline/admin path |
```

- [ ] **Step 4: Wire the notification template**

```php
'Renewal submitted' => 'renewal.submitted.v1',
```

Verify the exact matrix label string, same discipline as prior tasks. Update the migration's class doc block.

- [ ] **Step 5: Write a Feature test**

Extend `tests/Feature/Domain/Renewal/OpenRenewalTest.php`. Add a test asserting a real, successful online renewal submission emits `renewal.submitted.v1` with the real renewal id — query the outbox table directly. If a negative test is feasible cheaply (e.g. confirming `MarkExternalRenewal`'s own path does NOT also emit this new event), add one — but do not force it if `MarkExternalRenewalTest.php` already effectively proves this by omission (read it first to check).

- [ ] **Step 6: Run the test against real Postgres**

Same recipe as Task 1 Step 6, targeting `tests/Feature/Domain/Renewal/OpenRenewalTest.php`.

- [ ] **Step 7: Run doc gates, pint, phpstan, and commit**

```bash
bash ci/verify-docs.sh
vendor/bin/pint --test app/Domain/Renewal/Actions/OpenRenewal.php tests/Feature/Domain/Renewal/OpenRenewalTest.php
vendor/bin/phpstan analyse --no-progress app/Domain/Renewal/Actions/OpenRenewal.php
git add app/Domain/Renewal/Actions/OpenRenewal.php docs/contracts/event-catalog.md database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php tests/Feature/Domain/Renewal/OpenRenewalTest.php
git commit -m "feat(notifications): emit renewal.submitted.v1 for the online submission matrix row"
```

---

### Task 6: Update `docs/testing/release-gates.md`'s notification-matrix box

**Files:**
- Modify: `docs/testing/release-gates.md`

**Interfaces:**
- Consumes: all 5 prior tasks' real commit hashes and test names (this task runs last, after Tasks 1-5 all land).
- Produces: nothing.

- [ ] **Step 1: Read the current box text in full**

Read the "Notification matrix implemented" box (§D) as it stands after the CHEAP-set pass (PR #158) — it currently states 9 of 16 real-producer-needing rows are covered, 7 remain: 5 MEDIUM (this plan's targets) + 2 LARGE (Reminder due, Renewal paid/verified — explicitly out of scope for this plan too).

- [ ] **Step 2: Update the box**

Cite all 5 new events by name, all 5 new test files/methods, confirm the real cumulative count (9 + 5 = 14 of 16 real-producer-needing rows now covered), and correctly state the 2 remaining LARGE rows (Reminder due, Renewal paid/verified) are still genuinely unbuilt features, out of scope for both this plan and the prior one. Do NOT check this box unless all 16 real rows are covered — 14/16 is real, substantial progress but not the box's full literal claim. Follow this repo's evidence-citation discipline exactly as the prior 2 notification-matrix updates in this file already established (cite real test names, state real run counts, distinguish "confirmed locally" from "CI-confirmed" per this branch's own PR status).

- [ ] **Step 3: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add docs/testing/release-gates.md
git commit -m "docs(testing): update notification-matrix box for the 5 MEDIUM rows closed this pass"
```

---

## Verification

| Task | Done when |
|---|---|
| 1 | `payment.outcome_failed.v1` catalogued and emitted for both Failed/Expired outcomes, wired to the matrix template, real Feature tests pass against Postgres |
| 2 | `marketplace_order.submitted.v1` catalogued and emitted, wired, tested |
| 3 | `vendor_order.decided.v1` catalogued and emitted ONLY for the 2 real decision outcomes (proven by a negative test against the other 6 status values), wired, tested |
| 4 | `vendor.evidence_uploaded.v1` catalogued and emitted from the REAL `VendorFulfillment` domain (not the marketplace `UpdateVendorOrderStatus`), wired, tested |
| 5 | `renewal.submitted.v1` catalogued and emitted for the online path only (not `renewal.marked_external.v1`'s offline path), wired, tested |
| 6 | `release-gates.md`'s notification-matrix box cites all 5 new events accurately, states the real 14/16 count, correctly leaves the box unchecked |

Final whole-branch review checks: do Tasks 1 and 3's shared ruling (one event, `outcome` as data) actually land consistently — same field name (`outcome`), same reasoning cited in both? Does any task accidentally touch a file another task also touches (all 5 are independent per their own Interfaces sections — verify no accidental overlap slipped in during implementation)? Does the final event-catalog.md diff read as 5 clean new rows with no accidental edit to an existing row?

## Execution

Execute via `superpowers:subagent-driven-development` — fresh implementer subagent per task, task-scoped review, one final whole-branch review before PR. Standing execution mode for this session; do not ask the user to choose between subagent-driven and inline execution. All 5 implementation tasks (1-5) are genuinely file-independent and could be dispatched in any order; Task 6 must run last since it summarizes all 5.
