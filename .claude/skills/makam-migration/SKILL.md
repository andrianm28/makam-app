---
name: makam-migration
description: How migrations, seed data, and master data actually ship in this repository — data migrations instead of the dead database/seeders/ directory, string columns for closed lists and the three guarded Postgres CHECKs, named composite indexes, exact-reversal down(), additive backfills, pre-assigned timestamp slots, and the dummy-data honesty markers. Use when writing any migration, adding a column, shipping catalogue or master data, or backfilling existing rows.
---

# Migrations

40 files in `database/migrations/`. Every one is `return new class extends Migration`; all except the three `0001_01_01_*` Laravel scaffold files declare `declare(strict_types=1)`.

## `database/seeders/` is effectively dead — use a data migration

`DatabaseSeeder.php` is the untouched Laravel scaffold (one `User::factory()` call). **Nothing in CI, the Dockerfile, or any deployment script runs `php artisan db:seed`** — `docs/planning/agent-execution-plan.md` states this too. All catalogue and master data ships as a timestamped data migration.

`2026_07_26_170400_seed_faq_categories_and_articles.php` gives the reasoning:

> `2026_07_26_120400_seed_feature_gate_registry.php` already established the actual precedent for "real content that must exist in every environment automatically": a migration, which runs everywhere `php artisan migrate` does. […] a `Database\Seeders\FaqSeeder` class would silently never run outside a developer's own manual `db:seed` call.

A seeder class here is not a style preference — it is code that never executes.

## Closed-list columns and the three CHECK constraints

A closed list backing a column is `$table->string(N)` (`publish_state`, 16; `type`, 8; `category`, 16/32; `code`, 32/64), validated in the model's `booted()` hook. See `makam-domain-module` for the class shape.

A Postgres `CHECK` appears in exactly **three** places, all for `app/Platform` protocol values with a native enum in front of them, and all guarded to `pgsql`:

| Migration | Constraint |
| --- | --- |
| `2026_07_26_110000_create_audit_events_table.php` | `outcome IN ('allowed','denied','failed')` |
| `2026_07_26_140000_create_outbox_events_table.php` | `classification IN ('PUBLIC','INTERNAL','CONFIDENTIAL','RESTRICTED')` |
| `2026_07_26_200000_create_menu_interaction_events_table.php` | `interaction IN ('impression','click')` |

The guard is `if (DB::connection()->getDriverName() === 'pgsql')`, because SQLite's `ALTER TABLE` has no `ADD CONSTRAINT` and `phpunit.xml` defaults to SQLite while CI overrides to Postgres. `2026_07_26_120000_create_feature_gates_table.php` deliberately declines a CHECK on `state` and explains why. Do not add a fourth without the same explicit reasoning.

## Indexes

A composite index gets an explicit `<table>_<purpose>_idx` name **and** a comment naming the query it exists for:

```php
// Public listing/filter's primary access path: "published
// articles in this category, in display order" — see
// FaqArticle::scopePublished()/::scopeInCategory().
$table->index(['category_id', 'publish_state', 'sort_order'], 'faq_articles_category_state_order_idx');
```

Single-column indexes use Laravel's generated name. The earliest Sprint 3 tables (`audit_events`, `actor_sessions`, the MFA tables) predate this and leave their composites unnamed — they are the exception, not the pattern to copy. `scope_assignments` is the best example, including a comment on a constraint it deliberately does **not** add.

## `down()` reverses exactly what `up()` wrote

Never a blanket truncate. Two shapes, both worth reading:

- **`2026_07_26_170400_seed_faq_categories_and_articles.php`** inserted rows, so `down()` deletes exactly those rows: `faq_article_versions` where `published_by = 'seed:public-faq-catalog'`, then `faq_articles` by an enumerated list of all 23 slugs, then `faq_categories` scoped to `FaqCategoryCode::KNOWN_CODES`.
- **`2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php`** only *filled columns* on rows another migration created, so `down()` `UPDATE`s those eight columns back to `null` for the ten named slugs and leaves the cemeteries standing. Its own comment: *"rolling it back must not delete the cemeteries themselves."* `2026_07_26_220000`'s `down()` is the same shape — delete the `price_versions` rows it added, null the columns it filled.

`AGENTS.md` §Database: migrations follow expand/contract and production rollback does not rely on a destructive `down()`.

## Backfills are new migrations, never edits

A migration that has already run is frozen. `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php` states the failure mode plainly: a host that already ran the original would never re-run the edited version, silently drifting from a host that migrates fresh. It expands (two nullable columns) and backfills the nine existing rows in the same `up()` — the standard shape.

## Pre-assign timestamp slots for a concurrent batch

Any batch touching `database/migrations/` must hand each agent a timestamp range in its brief. **Three pairs of colliding timestamps already exist** from a batch that skipped this: `…_180000` (products / service_definitions), `…_180100` (product_variants / service_packages), `…_180200` (service_package_versions / seed_marketplace_products_and_variants). Load **`makam-agent-batch`** before fanning out; `2026_07_26_140000`'s doc block shows a migration recording its own assigned slot.

## Dummy data must be unmistakable

Several migrations ship deliberately fictional data so the public `dev.makam.co.id` host renders something end to end. This is authorized and correct (`docs/operations/dev-staging-environment.md` §4: synthetic data is the right content type there) — but the marker convention is not optional.

- **`2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`** — every address is a literal `Jl. Contoh …` street ("Contoh" = Indonesian for "Example"). Ten fictional cemeteries, no real operator.
- **`2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php`** — `price_source` is the generic `'Estimasi internal (data contoh)'`, never a named authority. Coordinates are 2-decimal city-area points, not the 6–7 decimals a real geocode would carry. `google_maps_url` is an honest text-*search* link rather than a fabricated pin drop.
- **`2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php`** — opens with a shouted "THIS IS DUMMY / PLACEHOLDER DATA" header and an itemised "None of the following is real" list. Vendor names are chosen specifically *not* to resemble any findable real vendor; photos are hand-authored abstract SVGs rather than fabricated photographs.

The same discipline outside migrations: `app/Support/CompanyInfo.php` (`PT Contoh Makam Digital Indonesia`) and `app/Support/ContactInfo.php`, whose `PHONE` uses an obviously-placeholder digit pattern (`+62 812-0000-1234`) inside a valid Indonesian format so the digits cannot belong to a real person.

The rule: fake data must read as an example at a glance, must never carry a specific false attribution, and every such migration must say in its doc block that a later real-data batch replaces it. Inventing a plausible-looking figure without the marker is fabricated business data, not a placeholder.

## The doc block explains reasoning, not schema

The schema is visible in the code. The doc block carries what is not: which document the shape came from, which judgement calls were made, and what was deliberately left out.

`2026_07_26_140000_create_outbox_events_table.php` is the exemplar. It reconciles a real column-name conflict — `.kiro/specs/platform-outbox/design.md` says `event_type`/`correlation_id`/`claimed_at`/`attempts`; `queue-and-outbox.md` §5 and `outbox-event-contract.md` say `event_name`/`trace_id`/`locked_at`/`attempt_count` — and resolves in favour of the second set because `requirements.md`'s own Authority line names those two documents and does not name `design.md` at all, and they independently agree. It then records two further judgement calls (no `actor_type`/`actor_id`; `available_at` included) rather than resolving them silently. The reconciliation is finding **N-11** in `docs/planning/sprint-plan.md`, which appends a correction to **N-10** instead of rewriting it. Follow that pattern.

Then verify: `bash ci/verify-docs.sh`, and `makam-verify` for what can and cannot be proven on this host — migrations cannot be run here.
