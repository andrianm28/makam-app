# Batch 2E — Notification recipient coverage + template versioning (NOTIF-02, NOTIF-03)

## Context

Derived from `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` §Phase 2, Batch 2E. Parent plan
lives on branch `fix/phase0-critical-stopgaps`'s worktree at time of writing (not yet merged to trunk); this
batch is independent of that merge and targets trunk (`docs/design-system-and-planning`) directly.

Two findings:

- **NOTIF-02**: `ProvisionalAggregateNotificationSubjectSource` maps only 4 aggregate types
  (`booking_draft`, `order`, `quote`, `renewal`). Outbox events carrying `aggregate_type` outside this set
  resolve zero recipients, silently, forever — `Actions\DispatchNotification` treats an unmapped/null
  subject as "no recipients, not an error."
- **NOTIF-03**: `notification_template_versions` version-1 rows are matrix-fact placeholders
  (`'Matrix snapshot (recipient/channel facts; not message copy): ...'`), never real Indonesian copy, and a
  DB trigger (migration `2026_08_09_100010`, lines 103-142) makes version 1 immutable — no code path may
  update or delete it. Real copy requires a version-2 row plus flipping `notification_templates.
  active_version_id`.

## Investigation — which aggregate types are actually fixable here

Confirmed by reading migrations and the actions that call `Outbox::record()`:

| `aggregate_type` | Emitted by | Contact reachable within 1-2 hops? | Verdict |
|---|---|---|---|
| `vendor_order` | `Domain\Marketplace\Actions\UpdateVendorOrderStatus` (`vendor_order.decided.v1`, `vendor_order.complaint_filed.v1`) | **Yes, 0 hops.** `vendor_orders` has inline `customer_name`/`customer_phone`/`customer_email` (NOT NULL), plus `vendor_id` (NOT NULL, FK to `vendors`) for the Vendor-column scope. | **Fix in this batch.** |
| `marketplace_order` | `Domain\Marketplace\Actions\PlaceMarketplaceOrder` (`marketplace_order.submitted.v1`) | **Yes, 1 hop when authenticated; NOT NULL column but two different content shapes.** `Checkout::placeOrder()` calls `PlaceMarketplaceOrder::handle(customerRef: auth()->check() ? (string) auth()->id() : session()->getId(), ...)` — `marketplace_orders.customer_ref` is a digit-string `users.id` for a signed-in customer, or a PHP session id (not a `users.id`, not safely comparable to the `bigint` column) for a guest. `marketplace_orders.vendor_id` (NOT NULL, FK) gives the Vendor-column scope directly, 0 hops. | **Fix in this batch.** `marketplaceOrderSubject()` uses `ctype_digit()` (the same test `MarketplaceOrderInfolist` already applies to this exact ambiguity) to resolve `ownerRef` only for the digit-string shape, `null` for the session-id shape — matching the existing anonymous-`booking_draft` pattern: no owner reference exists for a guest order, so no customer recipient is emitted, not a bug. |
| `payment_session` | `Platform\Payment\Actions\ApplyPaymentSettlement` (`payment.outcome_failed.v1`) | **No.** `payment_sessions` (migration `2026_08_09_100100`) carries no customer/order reference at all — only `merchant_ref`/`badan_usaha_ref` (opaque strings, not FKs) and provider fields. Its own migration doc block states the table "ships EMPTY, on purpose, and stays empty" — no code path writes a row today (Wave 1b ruling 1b-L3-01 Step 3). There is nothing to add a subject method for; the aggregate has no contact data by design, not by oversight. | **Scoped OUT.** Needs a real schema/design decision (does a payment session need to carry an order/customer reference at all, given it's unpopulated?) — that's product+schema work, not a recipient-resolution wiring fix. |
| `work_evidence` | `Domain\VendorFulfillment\Actions\UploadEvidence` (`vendor.evidence_uploaded.v1`) | **No, 3+ hops, and the chain is nullable.** `work_evidence.work_order_id` -> `work_orders` (no customer reference; `care_plan_id` is a product/catalog FK, not a customer) -> the only path toward a customer is `work_orders.subscription_cycle_id` -> `subscription_cycles` -> `care_subscriptions` -> customer, and `subscription_cycle_id` is nullable (one-off work orders have none at all). This exceeds the plan's own 1-2 hop bar and the last hop isn't guaranteed to exist. | **Scoped OUT.** Would need either a denormalized contact reference on `work_orders`/`work_evidence`, or a guaranteed non-null path to a subscription/customer — a schema change, not a wiring fix. |

Also verified per the plan's explicit caution: `vendor.evidence_uploaded.v1` is emitted by
`Domain\VendorFulfillment\Actions\UploadEvidence` with `aggregateType: 'work_evidence'` — a different
domain from the marketplace `vendor_orders` table above. The "vendor" wording overlap between
`Domain\VendorFulfillment` and `Domain\Marketplace`'s vendor-order rows is coincidental, exactly as the
seed migration's own doc block (`2026_08_09_100020_seed_notification_templates_from_matrix.php`) already
states. No code currently confuses the two; this batch does not touch `work_evidence`/`UploadEvidence` at
all, precisely to avoid introducing that confusion.

**Scope for this batch: `vendor_order` and `marketplace_order` only.** `payment_session` and `work_evidence`
are left at zero recipients, honestly, pending separate schema-design work.

## NOTIF-02 design

### `vendor_order`

New private method `vendorOrderSubject(string $vendorOrderId)`:
- Reads `vendor_orders` by `id` (the table's own auto-increment PK — `UpdateVendorOrderStatus` records
  `aggregateId: $order->getKey()`, and `VendorOrder` has no custom `$keyType`, so this is the same `id`).
- `ownerRef`: a new opaque prefix, `VENDOR_ORDER_CUSTOMER_PREFIX = 'vendor_order_customer:'` . the vendor
  order's own id — NOT the row's `customer_email` directly (mirrors the existing
  `GUEST_ORDER_PARTY_PREFIX` pattern: `RecipientResolver`/`RecipientResolutionSubject` never interpret
  `ownerRef`'s content, only `Contracts\RecipientAddressResolver`'s implementation does, and address
  resolution — not subject resolution — is where "look up this row's stored contact" belongs). Since
  `customer_email` is NOT NULL on `vendor_orders`, this branch has no null case to guard, unlike order
  parties' optional contact.
- `scopeEntityType`/`scopeEntityId`: `ScopeEntityType::VENDOR` / `vendor_orders.vendor_id` — confirmed
  end-to-end wired already: `ProvisionalScopeEntityRecipientRoleSource` maps `ScopeEntityType::VENDOR` to
  `RecipientRole::VENDOR`, and `RecipientResolver::ROLE_COLUMNS` maps that role to the matrix's "Vendor"
  column, which is `IN_APP` for "Vendor accepted/rejected" (the matrix row `vendor_order.decided.v1` maps
  to). `ScopeAssignmentResolver::actorsForEntity()` already resolves `ScopeEntityType::VENDOR` grants (used
  today by `FinanceVendorPayableAuthorizer`, `FinanceOrRestrictedAdminPayoutAuthorizer`,
  `VendorPanelAccessPolicy`), so no new plumbing is needed there.
- A missing `vendor_orders` row (unknown id) returns `null`, same failure-mode contract as every other
  branch in this class.

### `marketplace_order`

New private method `marketplaceOrderSubject(string $marketplaceOrderId)`:
- Reads `marketplace_orders` by `id`.
- `ownerRef`: `customer_ref` passed through only when `ctype_digit()` is true (a real `users.id`), `null`
  otherwise. **Correction after actually tracing the write path**: `customer_ref` is NOT NULL on this table,
  but for a guest checkout `Checkout::placeOrder()` sets it to `session()->getId()` — a PHP session id, not
  a `users.id`, and not safely comparable to the `bigint` `users.id` column (a raw `DB::table('users')->
  where('id', $sessionId)` would raise a database type error, not just fail to match). No prefix is needed
  for the authenticated shape — it matches the "plain `users.id`" case `Contracts\RecipientAddressResolver`'s
  doc block already documents — but the subject source itself must filter out the session-id shape before
  handing it through, which the original draft of this plan missed.
- `scopeEntityType`/`scopeEntityId`: `ScopeEntityType::VENDOR` / `marketplace_orders.vendor_id` (NOT NULL).
- A missing `marketplace_orders` row returns `null`.

### `EloquentRecipientAddressResolver` change

Add one branch: an `actorRef` starting with `VENDOR_ORDER_CUSTOMER_PREFIX` resolves via
`DB::table('vendor_orders')->where('id', $vendorOrderId)->value('customer_email')`. The existing two
branches (guest-order-party prefix, plain `users.id`) are unchanged. Order of `str_starts_with` checks
matters only in that both prefixes must be checked before falling through to the `users` lookup — no
overlap risk since the two prefix strings are distinct.

## NOTIF-03 design

New migration `2026_09_06_130000_add_v2_notification_templates_for_zero_recipient_events.php` (expand-only,
never touches version-1 rows):

For each of the two events this batch newly makes reachable end-to-end (`Vendor accepted/rejected` →
`vendor_order.decided.v1`, `Marketplace order submitted` → `marketplace_order.submitted.v1`), plus keeping
the migration honest about the trigger's shape:

- `INSERT INTO notification_template_versions` with `version = 2`, a real Indonesian `subject` and `body`,
  `variable_allowlist` as a JSON array (non-empty — lists the real interpolation variables each message
  actually needs), `restricted_fields` as a JSON array (kept identical to version 1's PII-guard list:
  `ktp`, `kk`, `death_certificate`, `bank_details`, `full_address` — no new sensitive field is introduced),
  `created_by = 'seed:batch2e-notification-templates'`, `created_at = now()`. `updated_at` is intentionally
  omitted — the table has no such column.
- `UPDATE notification_templates SET active_version_id = <new version's id> WHERE id = <template_id>` —
  this column has no trigger guard (only `notification_template_versions` rows are protected), so this is
  an ordinary update.
- Version-1 rows for these two templates are **never** touched — no `UPDATE`, no `DELETE` — verified by a
  migration test that re-asserts the trigger still rejects a raw `UPDATE`/`DELETE` against version 1 after
  this migration runs, and that the version-1 row's `body` still reads the original matrix-snapshot string.
- Respects the unique partial index `(template_id, version)` (new `version = 2` row for the same
  `template_id`, not a duplicate) and the composite FK `(active_version_id, id) -> (id, template_id)` on
  `notification_templates` (the flip targets a version row whose own `template_id` matches the template
  being updated, by construction, since the migration inserts and reads the version id from the same
  `$templateId` it queries).
- **Correction after re-reading the trigger closely**: the trigger (`BEFORE UPDATE OR DELETE ... FOR EACH
  ROW ... RAISE EXCEPTION`) is unconditional on `version` — it protects every row in
  `notification_template_versions`, not just version 1. So the version-2 rows this migration inserts are
  *also* immutable the instant they exist. `down()` therefore cannot delete them; it only reverts
  `notification_templates.active_version_id` (an ordinary, untriggered column) back to each template's
  version-1 id, leaving the harmless, now-inactive version-2 rows in place — the same non-destructive shape
  the original seed migration's own `down()` already uses.

Only the two templates this batch actually wires end-to-end get a version-2 row. `payment.outcome_failed.
v1` and `vendor.evidence_uploaded.v1` templates are left at their version-1 placeholder — giving them real
copy while their recipient resolution is still zero would be copy that nothing ever sends, and risks
implying those events are fixed when they are not.

## Test plan

- `tests/Unit/Platform/Notification/ProvisionalAggregateNotificationSubjectSourceTest.php`: add cases for
  `vendor_order` (real row resolves prefixed owner ref + vendor scope; missing row resolves null) and
  `marketplace_order` (authenticated `customer_ref` resolves owner ref + vendor scope; `null` `customer_ref`
  resolves no owner but keeps vendor scope; missing row resolves null). Reuse `Tests\Support\
  MakesVendorOrderFixtures` for the vendor-order fixture (vendor + listing + vendor order + granted actor)
  where useful, though the subject-source tests don't need the granted-actor part.
- `tests/Unit/Platform/Notification/EloquentRecipientAddressResolverTest.php`: add a case resolving the new
  `VENDOR_ORDER_CUSTOMER_PREFIX` shape to the vendor order's `customer_email`, and a case for a prefixed
  reference whose vendor order no longer exists resolving `null`.
- New `tests/Feature/Database/NotificationTemplateVersion2MigrationTest.php` (or similar): runs the new
  migration (already applied via `RefreshDatabase`), asserts (a) the two targeted templates' version-1 rows
  are byte-for-byte unchanged from the original seed migration's placeholder text, (b) a raw `DB::table(...)
  ->where('version', 1)->update(...)` against either template's version-1 row still throws (trigger still
  fires), (c) `notification_templates.active_version_id` for both now points at the version-2 row, (d) the
  version-2 rows' `variable_allowlist`/`restricted_fields` are valid JSON arrays satisfying the CHECK
  constraint (implicitly proven by the insert not throwing under real Postgres).
- End-to-end recipient test: extend or add alongside `RecipientResolverTest`/`DispatchNotification`-level
  tests (whichever the existing suite uses for the order/renewal end-to-end proof) showing
  `vendor_order.decided.v1` and `marketplace_order.submitted.v1` now resolve at least one recipient each,
  where before this batch they resolved zero (mirroring the class doc block's own "116 combined order/quote
  events, 0 recipients" framing for its historical fix).

## Explicitly out of scope (record, don't re-propose without a schema change)

- `payment_session` aggregate recipient resolution — table has no customer/order reference and ships empty
  by design; needs a product/schema decision, not a wiring fix.
- `work_evidence` aggregate recipient resolution — no guaranteed-non-null path to a customer within 1-2
  hops; `subscription_cycle_id` is nullable and the chain is 3+ hops even when present.
- Any change to `Domain\VendorFulfillment\Actions\UploadEvidence` or the `work_evidence` table — verified
  during investigation that "vendor" in that domain is unrelated to the marketplace `vendor_orders` table
  this batch does touch; not touching either the action or the table avoids conflating them.
- Real copy for `payment.outcome_failed.v1` and `vendor.evidence_uploaded.v1` templates — left at their
  version-1 placeholder since their recipient resolution isn't fixed in this batch.

## Verification

- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `bash ci/verify-docs.sh` clean.
- New/changed tests green via `vendor/bin/phpunit <path>` inside the pinned CI-parity Docker image against
  real PostgreSQL 18 and Redis 8.2 (not SQLite), per `AGENTS.md` §Testing and the
  `feedback_verify_against_real_db_not_sqlite` lesson.
- Full existing `tests/Unit/Platform/Notification/` and `tests/Feature/Domain/Marketplace/` suites still
  green (regression check on the touched files).

## Sequencing

One PR, one worktree (`.worktrees/batch2e-notification-recipients-templates`), branch
`fix/batch2e-notification-recipients-and-templates`, targeting trunk `docs/design-system-and-planning`.
