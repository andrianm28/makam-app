# Platform Vendor Portal — L10 Lane

> **Plan slug:** `platform-vendor-portal`
> **Lane:** L10
> **Date:** 2026-08-12
> **Builds on:** L11 marketplace-checkout (a361de4 + 79e4e65, cherry-picked)

## Goal

Build the vendor panel per MVP scope: products, orders, calendar, evidence,
transaction history, payout status. Routes at `/vendor/*` per
`information-architecture.md` §5.

---

## Tech Stack

Laravel 13, PHP 8.5, PostgreSQL 18 (CI/production), SQLite (local test),
Livewire 4, Filament 5.

---

## What L11 Delivered (cherry-picked)

| Commit | Content |
|--------|---------|
| `a361de4` | `vendors`, `vendor_users` tables + models |
| `79e4e65` | `vendor_listings`, `service_areas`, `vendor_availability` tables + models |

---

## What This Lane Adds

### Task 1 — `vendor_orders` table and model

One row per vendor order, tracking the full lifecycle from customer purchase
through fulfilment. `status` is the `VendorProcessingStatus` closed list
(`MENUNGGU_VENDOR` → `SELESAI` / `KOMPLAIN` / `DIBATALKAN`). Evidence uploads
are a separate `vendor_order_evidences` table (one order → many evidence files).

Schema:

```sql
vendor_orders
  id              BIGSERIAL PRIMARY KEY
  uuid            UUID NOT NULL UNIQUE
  vendor_id       UUID NOT NULL → vendors(id)
  listing_id      BIGINT NOT NULL → vendor_listings(id)
  customer_name   VARCHAR(255) NOT NULL
  customer_phone  VARCHAR(32) NOT NULL
  customer_email  VARCHAR(255) NOT NULL
  status          VARCHAR(32) NOT NULL DEFAULT 'MENUNGGU_VENDOR'
  notes           TEXT NULL
  created_at      TIMESTAMP
  updated_at      TIMESTAMP

vendor_order_evidences
  id                BIGSERIAL PRIMARY KEY
  vendor_order_id   BIGINT NOT NULL → vendor_orders(id)
  file_path         VARCHAR(500) NOT NULL   -- quarantine path
  evidence_type     VARCHAR(32) NOT NULL   -- PHOTO | DOCUMENT
  uploaded_at       TIMESTAMP NOT NULL
  created_at        TIMESTAMP
```

`status` enum values per `VendorProcessingStatus::KNOWN_STATUSES`.
`paid ≠ completed` invariant: no `is_paid` column on this table — payment state
lives on the journal/financial ledger side, not here.

- [ ] **1.1** — Migration for `vendor_orders`
- [ ] **1.2** — Migration for `vendor_order_evidences`
- [ ] **1.3** — `VendorOrder` Eloquent model with status scope, vendor/listings
        relations
- [ ] **1.4** — `VendorOrderEvidence` model with vendor_order relation

---

### Task 2 — Filament Vendor Panel shell

A minimal Filament panel at `/vendor` — no auth yet (auth is L12 scope per
`sprint-plan.md`), just the route registration and panel configuration with the
six navigation items pre-wired.

Routes per `information-architecture.md` §5:

```
/vendor/products     -- VendorListingResource
/vendor/orders      -- VendorOrderResource
/vendor/calendar    -- VendorAvailabilityResource
/vendor/evidence    -- evidence review (list of VendorOrderEvidence)
/vendor/transactions -- order history (VendorOrderResource list, read-only intent)
/vendor/payouts     -- VendorPayableResource (read-only, links to FinancialLedger)
```

- [ ] **2.1** — `app/Filament/Vendor/VendorPanel.php` with six navigation items
- [ ] **2.2** — Panel provider registration in `AppServiceProvider`
- [ ] **2.3** — Route registration in `routes/web.php`

---

### Task 3 — Products page (`/vendor/products`)

`VendorListingResource` — vendor's own listings only (scoped by authenticated
vendor via `vendor_id` matching the session's vendor context).

List table: product name, category, price, availability mode, stock, status,
actions (create / edit / toggle active).

Form: product_id (dropdown, disabled after create), price, availability mode,
stock_quantity, production_lead_time_days, cancellation_policy,
evidence_requirement, is_active.

All states: loading skeleton on table, empty state with "no products yet" CTA,
error state with retry.

- [ ] **3.1** — `VendorListingResource` list table
- [ ] **3.2** — `VendorListingResource` create/edit form
- [ ] **3.3** — Loading / empty / error / success states

---

### Task 4 — Orders page (`/vendor/orders`)

`VendorOrderResource` — incoming orders for the authenticated vendor, read-write
on status.

List table: order uuid (truncated), customer, product, status badge, date,
actions (view detail, update status).

Detail view: full order info + status timeline + evidence upload widget.

Status update: accept / reject / mark processing / mark sent-scheduled / mark
completed / mark complained. Each transition is a Filament simple action or
custom page.

All states: loading, empty ("no orders yet"), error, pending (status
transition in progress), success.

- [ ] **4.1** — `VendorOrderResource` list table scoped to current vendor
- [ ] **4.2** — `VendorOrderResource` detail page with status timeline
- [ ] **4.3** — Status transition actions (accept/reject/process/complete)
- [ ] **4.4** — Loading / empty / error / pending / success states

---

### Task 5 — Calendar page (`/vendor/calendar`)

`VendorAvailabilityResource` — manage blocked dates and capacity per
`vendor_availability` table from L11.

List: date, capacity, is_blocked badge.
Form: available_date, capacity, is_blocked toggle.

Vendor can only see/manage their own availability (scoped by vendor_id).

All states: loading, empty, error.

- [ ] **5.1** — `VendorAvailabilityResource` list + form
- [ ] **5.2** — Scoped to authenticated vendor

---

### Task 6 — Evidence page (`/vendor/evidence`)

Read-only list of evidence uploads across all the vendor's orders.
Links to the parent order.

Table: order ref, evidence type, uploaded at, file link (signed expiring URL).

- [ ] **6.1** — Evidence list page scoped to vendor
- [ ] **6.2** — Signed expiring URL generation for downloads

---

### Task 7 — Transaction History (`/vendor/transactions`)

Read-only order history for the vendor, ordered by date descending.
Shows: order uuid, customer, product, total, status, created at.

This is the orders page with a different intent (historical, read-heavy) and
different empty state language.

- [ ] **7.1** — Transaction history as a VendorOrderResource list variant

---

### Task 8 — Payout Status (`/vendor/payouts`)

Read-only view of `vendor_payables` rows for the authenticated vendor, via the
existing `VendorPayable` model from FinancialLedger.

Table: period reference, amount, state badge (held/payable/paid), eligible at,
paid at, payout reference (if paid).

Links to the payout detail if a `payouts` row exists.

- [ ] **8.1** — Payout status page scoped to vendor via `vendor_id`
- [ ] **8.2** — State badge rendering

---

## Out of Scope (owned by other lanes)

| Concern | Owner |
|---------|-------|
| Vendor authentication / login | L12 (per sprint-plan.md) |
| GuardPaymentSession / GuardCondition | L7 |
| QuoteRenewal / GuardRenewalPaymentOpening | L8 |
| SaveBookingDraftStep | L6 |
| Marketplace cart / checkout flow | L11 |
| Auto vendor payout automation | Post-MVP |

---

## Verification

- [ ] `php artisan test` — all passing, new tests for vendor panel
- [ ] `vendor/bin/pint --test` — clean
- [ ] `vendor/bin/phpstan analyse` — no new errors on touched paths
- [ ] `bash ci/verify-docs.sh` — clean
- [ ] `git diff --check` — no whitespace errors
