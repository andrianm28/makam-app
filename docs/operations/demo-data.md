# Demo Seed Data

Generates realistic, safely-tagged demo data across every major makam-app
journey for a live demo — safe to run directly on the makam.co.id beta
host, and safe to fully remove afterward.

## Running it

```bash
php artisan demo-data:seed
```

Prints a batch id and a per-domain summary. **Never run this against
anything but a database you have personally confirmed is either your own
local/disposable Postgres, or the live beta host at a moment you have
explicitly decided to seed demo data** — this command has no way to
verify the database it's pointed at is safe; that is the operator's
responsibility every time, not a one-time authorization. Running the code
that adds this command (i.e. merging its PR) does NOT itself authorize
running it — treat every actual invocation as its own decision.

## Removing it

```bash
php artisan demo-data:purge --force
```

Defaults to the most recently seeded batch. Pass an explicit batch id
(printed by `demo-data:seed`, also recorded in the `demo_data_batches`
table) to purge an older batch instead.

## Demo account credentials

Every demo account this subsystem creates — vendor accounts, the
cemetery-operator account, and the dedicated demo customer — uses the same
fixed, deliberately weak password, defined once as the `DEMO_PASSWORD`
constant on `DemoContactData` (`app/Support/ExampleData/DemoContactData.php`)
— read it there rather than duplicating the literal value in this doc, so
there is exactly one place it can drift out of date. This is deliberate —
these are single-purpose demo accounts, not real user accounts, and they
are purged along with everything else after the demo. **Never reuse this
password for a real account.** Demo account emails do NOT all share one
domain — `DemoContactData::email()` rotates `@example.com`/`.org`/`.net`
per record — find the exact seeded addresses via the `demo-data:seed`
command's own summary output, or by querying `users`
`WHERE demo_batch_id = '<batch id>'`.

## Purge only removes what the seeder itself wrote

`demo-data:purge` removes exactly the rows `demo-data:seed` created for a
batch — it has no way to know about, and will never touch, anything a real
person did with that seeded data afterward. If a demo cemetery, vendor, or
order was actually used between seed and purge (the whole point of a live
demo) — someone added a demo vendor listing to a cart, a demo vendor set
their availability, an admin reserved a plot against a demo order, a
manual payment was recorded on a demo marketplace order — that real usage
can create a real row with a `restrictOnDelete()` foreign key back to the
demo data purge is trying to remove. Purge runs as one transaction, so
this fails safely: the whole purge rolls back, nothing is deleted, and you
get an error naming the table that still has a real reference into the
batch. There is no automatic cleanup for this case — manually inspect and
remove (or reassign) the blocking row yourself, then re-run
`demo-data:purge` for the same batch id.

## Known, deliberate scope limits

- **Care-subscription evidence upload is NOT seeded.** `UploadEvidence`
  requires a real, already-scanned `documents` row, and
  `config('document-vault.malware_scanner')` resolves to `null` outside
  the `development` environment — there is currently no way to produce a
  real Accepted document on beta at all, seed data or otherwise. This
  subsystem does not fabricate one. **This is a real, separate platform
  gap worth its own investigation** (does certificate/evidence upload
  work AT ALL on beta today?), independent of this demo-data effort.
- **`WorkOrder.status` never reaches `Completed` through any real Action**
  anywhere in this codebase today — confirmed by reading every Action in
  the vendor-fulfillment domain and grepping for every write of
  `WorkOrderStatus::Completed`. The demo seed data sets this status
  directly as a narrow, documented exception (see
  `CareSubscriptionExampleData`'s own doc block) so a demo work order can
  visibly read as finished; this is a real, separate gap in the
  application's own domain modeling, not something this subsystem
  invented or fixed.
