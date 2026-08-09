# Retrofit: Marketplace — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `Marketplace` module's shipped **browse skeleton** (Kiro spec `funeral-marketplace-and-vendor-portal`, AC1–AC3 as far as they were built; screens PUB-020/PUB-021) the two-tier Superpowers SDD review it never got, close any Critical/Important findings with a bounded fix wave and real regression tests, remove the confirmed dead `MarketplaceComingSoon` stub, and give explicit disposition to every self-flagged gap already on record (AC2's four missing columns, the category-code OPEN QUESTION, the `{productSlug}` IA drift, §6.1/§6.6/§6.8 unimplemented states, accessibility geometry).

**Architecture:** Review-and-fix retrofit, matching the recipe the four prior retrofits this session exercised (`CemeteryDirectory`+`CemeteryCapability` pilot, `IdentityAccess/Mfa`+`Reauthentication`, `Faq`, `GraveRegistry`). Work happens in an isolated worktree/branch off `docs/design-system-and-planning`, fans task-scoped review out by functional slice, runs one whole-module reviewer over the combined findings plus the code holistically, then applies one bounded fix wave with a scoped re-review.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, PostgreSQL 18 (CI oracle) / SQLite (local), Pest via PHPUnit, `ci/verify-docs.sh`.

---

## Scope note, stated up front: this reviews the browse skeleton ONLY

`Marketplace` is a **browse-only skeleton**. `app/Domain/Marketplace/Actions/` is empty (`.gitkeep` only) — there are no write-side Actions, no cart, no checkout, no vendor portal, no `vendors`/`carts`/`marketplace_orders`/`vendor_orders` tables, and no Filament vendor panel. `design.md`'s "Data" block names fifteen tables; exactly **two** of them exist (`products`, `product_variants`).

**This retrofit does not build cart, checkout, or the vendor portal.** It reviews what shipped. Anything AC3 names after "browse/select", all of AC5–AC11, AC13, and AC14 are out of scope by construction — they have no code to review. They get *disposition*, not implementation.

`ServiceCatalog` is the **other half** of `retrofit-backlog.md` §1 item 7 and is being retrofitted **in parallel by a different agent**. Do not read, review, or modify anything under `app/Domain/ServiceCatalog/**`, `database/migrations/*service_*`, or `.kiro/specs/package-and-service-bundles/**`. Item 7's backlog row is shared between the two lanes; expect a small merge conflict on that one table row when both PRs land — whoever merges second resolves it by keeping both halves.

---

## OPEN DEPENDENCY (recorded verbatim, deliberately NOT resolved here)

A **confirmed scope contradiction** exists between two authoritative planning documents about whether cart and checkout are in the MVP. Both sides are quoted verbatim from the committed files:

- **`docs/product/mvp-scope.md` line 34**, under the heading `## 3. Marketplace MVP` — which sits under this document's unconditional **"MUST IMPLEMENT"** framing — reads:

  > `- Cart dan checkout.`

- **`docs/planning/sprint-plan.md` line 162** describes the identical scope as deferred outright:

  > **Feature specs deferred entirely:** `funeral-marketplace-and-vendor-portal` (cart, checkout, **vendor portal**) · …

  and the same file pushes it to Sprint 11–12 in three further places — line 151 (`| Marketplace — category/product browse from seeded canonical catalog | ⚠️ Skeleton (no cart, no checkout, no vendor portal) |`), line 777 (`| **11–12** | `funeral-marketplace-and-vendor-portal` — cart, single-vendor checkout, **vendor portal (9 screens)** | — | Sprint 8–9 |`), and line 1034 (`| 11–12 | `funeral-marketplace-and-vendor-portal` (full + vendor portal) |`).

This matters because `AGENTS.md` §Source precedence ranks `docs/product/mvp-scope.md` **above** approved specs and plans, and states: *"Never remove a stakeholder MVP item merely because an external gate is closed. Implement the documented fallback."* `sprint-plan.md` is not a gate — it is a schedule — so the deferral is not obviously a legitimate application of that rule.

**This retrofit does not resolve the contradiction, and cannot.** It is a product/stakeholder call, not an engineering one, and it does not block this retrofit — which reviews already-built code and builds no cart. It is recorded here as an **explicit open dependency**:

> **Marketplace's SECOND retrofit — the one that reviews cart/checkout once they are actually built — MUST NOT START until a human resolves whether `mvp-scope.md` line 34 or `sprint-plan.md`'s Sprint 11–12 deferral governs.** Building cart/checkout against an unresolved precedence conflict would produce work whose acceptance criteria nobody can state.

Task 5 carries this same paragraph into `docs/planning/retrofit-backlog.md` §2 so it survives outside this plan doc. Task 2's whole-module reviewer must reproduce it verbatim in its report. **No agent on this lane may "resolve" it by picking a side.**

---

## Global Constraints

- Never hardcode a design value or use a Tailwind arbitrary value — `ci/verify-docs.sh` gates 2/3/11/12 enforce this over `resources/` and `app/`.
- `AGENTS.md` §Marketplace: *"Support the exact catalog in `marketplace-catalog.md`."* / *"Do not implement land rights listing through generic marketplace code."* / *"Paid does not mean completed."*
- `AGENTS.md` §Documentation: *"Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations."* `ProductCode`, `MarketplaceProductCategory`, and `VendorProcessingStatus` are the one PHP-side definition each; `docs/product/marketplace-catalog.md` is authoritative over all three.
- `AGENTS.md` §Testing: every bug fix requires a regression test. CI (PostgreSQL 18) is the oracle.
- `AGENTS.md` §Infrastructure-agent execution: *"Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly."* **This host has no `vendor/` and `CLAUDE.md` forbids `composer install`/`npm run build` here** — no reviewer may report a PHPUnit result it did not run. `php -l` and `bash ci/verify-docs.sh` are the only executable local checks.
- `AGENTS.md` §Development methodology: Kiro spec files stay the "what to build" authority. This plan implements review over them; it does not restate acceptance criteria.
- **Citation-accuracy rule, learned from all four prior retrofits this session:** verify every claimed quote against the real committed file before putting it in a review report. Two of this module's most-quoted claims live in **class doc blocks**, not in `design.md` — `MarketplaceProductCategory.php:14-58` (the category-code open question and its narrow resolution) and `MarketplaceIndex.php:46-100` (why `?kategori=` is not a slug, and why no `wire:loading` skeleton exists). `design.md` for this spec is 59 lines and contains none of that reasoning. Cite the source that actually says it.

---

## Current shipped state

### Domain layer (`app/Domain/Marketplace/`)

- `ProductCode.php` (151 lines) — closed list of the nine `products.code` values, quoted from `marketplace-catalog.md` L7–L30 in that document's own order. Plain-string-const class, not a PHP backed enum, deliberately: its doc block argues every *other* closed list backing a DB column in this codebase (`FaqCategoryCode`, `MfaEnrolmentStatus`, `ScopeEntityType`) uses this shape. Also holds `GRAVESTONE_CODES` (the 3 Batu Nisan codes), `isKnown()`, `assertKnown()`, `requiresVariants()`, and `categoryFor()`.
- `MarketplaceProductCategory.php` (118 lines) — closed list of the three `products.category` keys (`FLOWERS`/`GRAVESTONES`/`GRAVE_CARE`) plus `label()` returning the catalogue's exact Indonesian headings. Its doc block carries the narrow resolution of the category-code open question (grouping column yes, public slug no).
- `MarketplaceCatalogQuery.php` (75 lines) — the read entry point: `activeProducts()`, `activeProductsInCategory()`, `findActiveByCode()`, `variantsFor()`. **Note the exact wording of its own doc block: "The *recommended* read entry point"** — weaker than `GraveRegistryPublicQuery`'s "the ONE public read entry point" and `FaqPublicQuery`'s equivalent. Whether that weaker phrasing is matched by weaker real enforcement is a Task 1 question, not an assumption.
- `VendorProcessingStatus.php` (105 lines) — the 8 fulfilment status codes. **Wired to nothing**: no `vendor_orders` table, no state machine, no `StatusIntent` call site. Exists ahead of its table so a later batch consumes one definition instead of inventing a literal list. `DIBAYAR` deliberately absent (AC12).
- `Models/Product.php` (125 lines), `Models/ProductVariant.php` (94 lines) — Eloquent models with `active()`/`inCategory()`/`orderedForDisplay()` scopes and a `booted()` guard on `ProductVariant` restricting variant rows to `GRAVESTONE_CODES`.
- `Actions/` — empty (`.gitkeep` only). No write path exists.

### Migrations (4)

`2026_07_26_180000_create_products_table.php` (146 lines), `2026_07_26_180100_create_product_variants_table.php` (111), `2026_07_26_180200_seed_marketplace_products_and_variants.php` (189), `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php` (165).

**Filename-timestamp collision to check, not assume:** `2026_07_26_180000_create_service_definitions_table.php`, `..._180100_create_service_packages_table.php`, and `..._180200_create_service_package_versions_table.php` share the first three timestamps with the three Marketplace migrations above. Laravel orders migrations by full filename, so `create_products_table` sorts before `create_service_definitions_table` deterministically — but the schema reviewer must confirm no Marketplace migration depends on a ServiceCatalog table (or vice versa) across a colliding pair. **Report only; do not modify any `*service_*` migration — the parallel `ServiceCatalog` lane owns those files.**

### Public UI (`app/Livewire/Public/Marketplace/`)

- `MarketplaceIndex.php` (154 lines) → `resources/views/livewire/public/marketplace/index.blade.php` (263 lines). Route `/marketplace`, `marketplace.index`. Category filtering via `#[Url(as: 'kategori')]` carrying a `MarketplaceProductCategory` key verbatim — **not** a slug. Unknown key falls back to the unfiltered catalogue with a §6.3-shaped explanation rather than 404 or a leaked `InvalidArgumentException`. `catalogueUnavailable` gives §6.5. **Zero Livewire actions** — every chip is a plain `<a href>`.
- `ProductDetail.php` (116 lines) → `product-detail.blade.php` (219 lines). Route `/marketplace/produk/{productCode}`, `marketplace.product`. Routed by product **code**, not the IA's `{productSlug}` (no slug column exists and none was minted). `abort(404)` covers "no such code" and "code exists but inactive" indistinguishably. Variants are **displayed, not selected** — no selector, because a control with nowhere to send its value is worse than none.

### Confirmed dead code

`app/Livewire/Public/ComingSoon/MarketplaceComingSoon.php` (33 lines) — superseded by `MarketplaceIndex`, and `routes/web.php` registers no route for it. `routes/web.php:52-64` says so itself, verbatim:

> `| ... `BookingWizardComingSoon`, `MarketplaceComingSoon`, and `RenewalComingSoon` (app/Livewire/Public/ComingSoon/) are now dead code, deliberately left in place rather than deleted in this same change (no test depends on either; removing them is separable cleanup).`

**CORRECTION, 09 Aug 2026, before the fix wave started — this retrofit LEDGERS the stub, it does not delete it.** The plan as first committed (`369076a`) had Task 3 delete `MarketplaceComingSoon.php` and amend the `routes/web.php` comment. The team lead superseded that after the parallel `Renewal` lane independently found the identical pattern on `RenewalComingSoon`: removing either stub properly touches shared files (`routes/web.php`, `app/Livewire/Public/Booking/BookingWizard.php`) that both lanes would contend for if each cleaned up inline. The original text above is left as written, per this repository's append-correction convention.

**Binding instruction for Task 3:** do **not** delete `MarketplaceComingSoon.php`, and do **not** touch `routes/web.php` or `BookingWizard.php` at all. The stub is disposed as a **ledgered gap** in Task 5, recorded as a combined future cleanup item worded *"paired with `RenewalComingSoon`, same dead-code pattern, one future PR should remove both together."* Neither lane deletes either stub this wave. The Task 1 schema/dead-code slice still gathers the full reference-grep evidence — it now feeds the ledger entry instead of authorising a deletion.

### Tests (4 files, 42 test methods)

- `tests/Feature/Domain/Marketplace/ProductCatalogueSeedTest.php` (212 lines, 8 methods) — including `test_product_code_enum_matches_the_live_catalogue_document_exactly`, which **re-parses `marketplace-catalog.md` at test time**. This is the catalogue-drift CI check: drift between the document and the enum fails the build.
- `tests/Feature/Domain/Marketplace/ProductVariantSeedTest.php` (126 lines, 5 methods).
- `tests/Feature/Livewire/Public/Marketplace/MarketplaceIndexRouteTest.php` (318 lines, 16 methods) — including the browse-only guarantees `test_the_landing_page_offers_no_cart_or_checkout_affordance`, `test_the_component_exposes_no_livewire_actions_to_call`, and the blocked-route guard `test_the_category_slug_route_is_still_blocked_and_deliberately_unregistered`.
- `tests/Feature/Livewire/Public/Marketplace/ProductDetailRouteTest.php` (211 lines, 13 methods) — including `test_the_detail_page_offers_no_cart_or_checkout_affordance`, `test_a_deactivated_product_404s_indistinguishably_from_a_code_that_never_existed`, `test_placeholder_variant_preview_image_paths_are_never_rendered`.

### `sprint-plan.md` S4-T8 row (line 630, verbatim excerpt)

> ✅ Done (08 Aug 2026, agent team), CI green — run `31248602859`, commit `a150a3b`. AC1 and browse shipped at `/marketplace` + `/marketplace/produk/{productCode}`; browse-only is test-enforced (no cart/checkout affordance, no callable Livewire action). **Partial:** AC2 cannot be completed as specified — `products`/`product_variants` have no schedule, service-area, delivery-fee, or stock/availability column. AC3's cart→checkout→payment→vendor sequence is Sprint 11–12 as planned. `/marketplace/kategori/{categorySlug}` stays deliberately **unregistered and BLOCKED** pending a product decision …

Already specific and honest, and it cites a real CI run. Task 5's correction should therefore be **narrow and additive**: this retrofit's PR number, CI run, finding counts, and the dead-code removal. Do not restate what is already accurate.

### Kiro spec self-reported gaps (`tasks.md`, quoted not summarised)

- **AC2 — four missing columns.** *"not started, and **currently unimplementable as specified**… There is **no schedule, service-area, delivery-fee, or stock/availability column on either table**, so three of AC2's required fields have nowhere to live yet."* (The text says "three"; it lists four missing columns. Task 1's schema reviewer confirms the real count against the migrations and Task 5 corrects the number if it is wrong.)
- **Category-code OPEN QUESTION.** *"still open and still BLOCKED after S4-T8… category routing is **BLOCKED**, not merely undocumented."* Owner: product owner. Not resolvable by this retrofit.
- **§6.1 loading / §6.6 duplicate-retry / §6.8 success.** *"**Not implemented:** §6.1 loading has no test, and §6.6 duplicate/retry-safe and §6.8 success have no mutation to guard."*
- **Accessibility geometry.** *"**Touch targets, focus ring, and the mobile reflow are NOT TESTED** — no browser or headless harness exists in this repository, so no geometry was measured."* Re-verify this is still true with a cheap grep rather than inheriting the claim — every prior retrofit this session did.
- **The `{productSlug}` IA drift.** `ProductDetail.php:19-41` reports `information-architecture.md` §1's `/marketplace/produk/{productSlug}` as stale against a schema with no slug column, *"reported as a documentation gap for this batch's owner to resolve; it is not silently 'resolved' here."* **This retrofit is that owner.** Task 2 must rule on it: fix the IA doc, or ledger it with a named reason.
- **`tasks.md` §NOT TESTED bullets 2–5** — commercial completeness, product-owner review of the label mapping, and the two unresolved `ekspektasi-vs-specs.md` D3/D4 questions. All product decisions, not engineering gaps. Ledger.

### What a design-time `brainstorming` pass would have asked, had one run before S4-T8

Reconstructed retrospectively, per the retrofit recipe:

1. *"`mvp-scope.md` says cart and checkout are MUST IMPLEMENT and `sprint-plan.md` says they are deferred entirely — which one governs, before we build a page that deliberately has no cart?"* **Never asked.** This is the open dependency above; it would have been far cheaper to resolve before S4-T8 than after.
2. *"The IA route is `{productSlug}` and there is no slug column — do we add the column, change the IA doc, or route by code?"* Asked and answered well inside `ProductDetail`'s doc block (route by code, report the drift), but the reported drift was then never routed to anyone who could fix the document.
3. *"AC2 names nine required product fields and the table has five of them — is AC2 partially shippable, or does the batch stop and add columns first?"* Asked and answered honestly (ship what exists, disclose the gap) — this is the one that went right.

---

## Review scope

**In scope:**
- `app/Domain/Marketplace/**` — all 6 PHP files (`ProductCode`, `MarketplaceProductCategory`, `MarketplaceCatalogQuery`, `VendorProcessingStatus`, `Models/Product`, `Models/ProductVariant`).
- `app/Livewire/Public/Marketplace/**` — `MarketplaceIndex.php`, `ProductDetail.php`.
- `resources/views/livewire/public/marketplace/{index,product-detail}.blade.php`.
- `app/Livewire/Public/ComingSoon/MarketplaceComingSoon.php` — for deletion only.
- The 4 Marketplace migrations listed above.
- All 4 Marketplace test files listed above.
- `routes/web.php` — only lines 33-34, 52-64, and 85-107 (the marketplace route registrations and the dead-code comment).

**Out of scope (do not read, review, or modify):**
- `app/Domain/ServiceCatalog/**`, every `*service_*` migration, `.kiro/specs/package-and-service-bundles/**` — the parallel `ServiceCatalog` lane owns them.
- `BookingWizardComingSoon.php`, `RenewalComingSoon.php`, `resources/views/livewire/public/coming-soon.blade.php` — other lanes / still-live surface.
- Cart, checkout, vendor portal, `vendor_orders`, payment, payout — unbuilt; disposition only, never implementation.
- The category slug decision, the `mvp-scope`/`sprint-plan` contradiction, and the two `ekspektasi-vs-specs.md` D3/D4 questions — human/product calls. Record, never decide.
- Any accessibility browser/axe harness — program-level gap confirmed by all four prior retrofits.

---

## Task 1: Draft the review briefs and dispatch task-scoped review

**Files:**
- Create (ledger, git-ignored, inside the worktree): `.superpowers/sdd/retrofit-marketplace/task-1-brief-{domain,ui,schema-and-deadcode}.md` and matching `task-1-report-*.md`.
- Read only: everything under "Review scope" above.

**Interfaces:**
- Produces: three independent review reports, each graded against (a) `AGENTS.md` in full, (b) `.kiro/specs/funeral-marketplace-and-vendor-portal/{requirements,design,tasks}.md` in full, (c) `mattpocock-skills:codebase-design` deep-module vocabulary, (d) this plan's "Current shipped state" (verify and extend — do not re-discover).
- Consumed by: Task 2's whole-module reviewer.

Three slices, not four: this module has no write lifecycle and no admin surface, so `Faq`'s five-slice and `GraveRegistry`'s four-slice shapes do not apply. The schema slice absorbs the dead-code disposition because both are "what exists on disk that shouldn't, or doesn't that should."

- [ ] **Step 1: Confirm the worktree and branch already exist**

```bash
cd /home/ubuntu/makam-app/.worktrees/retrofit-marketplace
git status -sb   # expect: ## retrofit-marketplace
git log --oneline -1   # expect: 7bc3b8d Merge pull request #11 from andrianm28/retrofit-graveregistry
```

- [ ] **Step 2: Commit this plan doc first, before any review work starts**

```bash
git add docs/superpowers/plans/2026-08-09-retrofit-marketplace.md
git commit -m "Add retrofit plan doc: Marketplace (browse skeleton)"
```

- [ ] **Step 3: Dispatch three task-scoped review agents in parallel via `superpowers:dispatching-parallel-agents`**

**Slice: domain enums + query layer + catalogue-drift check.** Files: `ProductCode.php`, `MarketplaceProductCategory.php`, `MarketplaceCatalogQuery.php`, `VendorProcessingStatus.php`, `Models/Product.php`, `Models/ProductVariant.php`, `ProductCatalogueSeedTest.php`, `ProductVariantSeedTest.php`. Ask explicitly:
  - `MarketplaceCatalogQuery`'s doc block says "the **recommended** read entry point," not "the ONE entry point." Does anything outside `app/Domain/Marketplace/**` reach `Product::query()`, `Product::all()`, `new Product`, or `ProductVariant` directly today? Run the grep; report the real answer. If the invariant holds only by convention and not by any structural guard, say whether that is a finding or an accepted difference from `GraveRegistryPublicQuery`.
  - Does the closed-list guard on `products.category` and `products.code` actually hold **at the model seam** (`Product::booted()`), or only in the seeder? Read the model, don't trust the doc block.
  - Does the seed migration bypass `booted()` via `DB::table()->insert()` — the exact bug class the pilot, `Faq`, and `GraveRegistry` retrofits each checked for? If it does, are the written values still structurally guarded by `ProductCode`/`MarketplaceProductCategory` constants, or are they hand-typed literals?
  - `ProductCatalogueSeedTest::test_product_code_enum_matches_the_live_catalogue_document_exactly` re-parses `marketplace-catalog.md` at test time. Read its parsing logic: would it actually **fail** if the catalogue gained a tenth product, renamed a code, or reordered the list — or could a drift slip past the regex? This is the module's headline CI guarantee; verify it, don't assume it.
  - `ProductCode::categoryFor()` is the single product-code→category mapping. Does any other file re-derive it? Grep for the category constants.
  - `VendorProcessingStatus` is wired to nothing. Is its existence-ahead-of-its-table justified as its doc block claims, or is it dead code of the same kind as `MarketplaceComingSoon`? Rule on this explicitly — the two cases look similar and are not.

**Slice: Livewire browse UI + the "no cart affordance" guarantee.** Files: `MarketplaceIndex.php`, `ProductDetail.php`, both Blade views, `MarketplaceIndexRouteTest.php`, `ProductDetailRouteTest.php`, and the marketplace lines of `routes/web.php`. Ask explicitly:
  - **The test-enforced browse-only guarantee is this slice's headline claim.** Read the bodies of `test_the_landing_page_offers_no_cart_or_checkout_affordance`, `test_the_detail_page_offers_no_cart_or_checkout_affordance`, and `test_the_component_exposes_no_livewire_actions_to_call`. Do they actually prove the absence they name, or do they assert a weak `assertDontSee` on a few Indonesian words that a future "Tambah ke keranjang" button might not match? Quote the real assertions. This is exactly the weak-assertion defect class `604dd1f` fixed elsewhere in this repo (a nav-label `assertSee` that matched on any page).
  - Does every array/value crossing the Livewire→Blade seam have a precise shape? (The defect class all four prior retrofits found live instances of.)
  - `MarketplaceIndex::render()` catches `Throwable`, calls `report($e)`, sets `catalogueUnavailable`. Does the view actually render a distinct §6.5 state for it, or does it fall through to the §6.2 empty state — i.e. can "the catalogue is down" and "this category is empty" render the same words to a user?
  - Does the unknown-`?kategori=` path genuinely never leak `InvalidArgumentException`'s message (which interpolates the raw user-supplied key)? Confirm against both the component and the view.
  - `ProductDetail::mount()` `abort(404)`s for unknown-or-inactive. Confirm the 404 is genuinely indistinguishable between the two cases — including any difference in logging or response headers, not just body text.
  - Run `bash ci/verify-docs.sh` and report the real gate results for gates 1-3, 11, 12. Run `php -l` on every PHP file in scope. **State BLOCKED for PHPUnit — this host has no `vendor/`.**

**Slice: schema gaps + dead-code disposition.** Files: the 4 Marketplace migrations, `MarketplaceComingSoon.php`, `routes/web.php:52-64`. Ask explicitly:
  - Confirm the AC2 gap against the real migrations: exactly which of AC2's required fields (product/package, variant, photo, price, stock/availability, service area, schedule, delivery fee, evidence requirement) have a column and which do not? `tasks.md` says "three of AC2's required fields have nowhere to live" while listing four missing columns — establish the correct number.
  - **Rule on disposition, do not build:** is adding four columns to `products` in this retrofit correct, or is it out of scope? Consider that stock/availability and service-area are meaningless without a vendor and an order, that this is a review retrofit, and that `AGENTS.md` §Infrastructure-agent execution requires human review before a migration lands. Recommend; Task 2 decides.
  - Check the FK-ordering bug class (`a150a3b`, found live by the pilot retrofit, absent in `Faq`): does any Marketplace test drop or truncate `products`/`product_variants` directly in an order a foreign key would now reject?
  - Check the migration filename-timestamp collision documented above. Confirm ordering is deterministic and that no cross-dependency exists. **Report only — do not modify any `*service_*` file.**
  - Confirm `MarketplaceComingSoon` is genuinely unreferenced: grep the whole tree (routes, views, tests, config, Livewire component registration) for `MarketplaceComingSoon` and for its heading string. Report every hit.
  - `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php` seeds **dummy** vendor names, prices, and photo paths into a public-facing table. Is every value unmistakably fictional to an end user (the `Contoh…` convention `GraveRegistry`'s seed used), and is there any way a dummy price could be mistaken for a real one on a public page?

- [ ] **Step 4: Each reviewer writes its report** to `.superpowers/sdd/retrofit-marketplace/task-1-report-{domain,ui,schema-and-deadcode}.md`:

```markdown
# Task 1 report — <slice>

## Findings
- [Critical|Important|Minor] <file:line> — <what's wrong> — <why it matters, citing the AGENTS.md/spec rule violated>

## Confirmed correct (worth stating, not just silence)
- <thing that was checked and holds, with the evidence that was actually run>

## Checks NOT executed
- <every check that could not run here, marked BLOCKED or NOT TESTED — never PASS>

## Questions for the whole-module reviewer
- <anything needing cross-slice context to judge>
```

- [ ] **Step 5: Commit the briefs + reports to the ledger**

```bash
git add .superpowers/sdd/retrofit-marketplace/
git commit -m "Task 1: task-scoped review, 3 slices (domain, ui, schema+deadcode)"
```

## Task 2: Whole-module review

**Files:**
- Read: all three Task 1 reports, plus the full module read holistically.
- Create: `.superpowers/sdd/retrofit-marketplace/task-2-whole-module-review.md`

**Interfaces:**
- Consumes: Task 1's three reports.
- Produces: one deduplicated, triaged finding list (Critical/Important/Minor) that Task 3's fix wave works from verbatim.

- [ ] **Step 1: Dispatch one reviewer over the whole module** — consolidate, dedupe, and answer every "Questions for the whole-module reviewer."

- [ ] **Step 2: Reproduce the OPEN DEPENDENCY section of this plan verbatim in the review report.** Both citations, both file:line references, and the "second retrofit must not start" sentence. The reviewer **must not** pick a side, recommend a side, or soften either quote.

- [ ] **Step 3: Rule explicitly on these four dispositions**, each with a named reason:
  1. **AC2's missing columns** — add in this retrofit, or ledger as out-of-scope schema work needing human review? (Default expectation: ledger. A review retrofit adding four speculative columns to a public table, for features that do not exist, is scope creep — and `AGENTS.md` requires human review before a migration lands regardless.)
  2. **`MarketplaceComingSoon`** — **superseded: the deletion is off the table** (see the correction under "Confirmed dead code"). Rule instead on how completely the Task 1 grep evidence establishes the stub is unreferenced, so Task 5's ledger entry states a verified fact rather than an assumption. Do not propose deleting it or editing `routes/web.php`.
  3. **The `{productSlug}` IA drift** — `ProductDetail.php` explicitly reported it "for this batch's owner to resolve," and this retrofit is that owner. Fix `information-architecture.md` to say `{productCode}`, or ledger with a named reason.
  4. **`VendorProcessingStatus`'s existence ahead of its table** — justified forward definition, or dead code? (These look alike and are not: one has a documented future consumer and a canonical-catalogue traceability role; the other is a superseded stub.)

- [ ] **Step 4: Triage every finding Critical/Important/Minor**, same triage rule as all four prior retrofits: Critical and Important get the one bounded fix wave; Minor is ledgered and parked unless trivial.

- [ ] **Step 5: Commit the whole-module review**

```bash
git add .superpowers/sdd/retrofit-marketplace/task-2-whole-module-review.md
git commit -m "Task 2: whole-module review, triaged findings + scope-contradiction flag"
```

## Task 3: Bounded fix wave

**Files:** Whatever Task 2's Critical/Important findings name, plus the dead-code deletion. **Do not modify any file not named by a Critical or Important finding or by Step 6 below.**

**Interfaces:**
- Consumes: Task 2's triaged finding list.
- Produces: one commit per finding, each with a regression test; a ledgered list of every Minor finding left unfixed.

- [ ] **Step 1: For each Critical/Important finding, write the failing regression test first.**

Example shape for the most likely finding class (weak browse-only assertions), written against the real test file's existing conventions:

```php
public function test_no_marketplace_page_exposes_any_cart_or_checkout_route_or_form(): void
{
    $response = $this->get(route('marketplace.index'));

    $response->assertOk();
    $response->assertDontSee('<form', escape: false);
    $response->assertDontSee('wire:click', escape: false);
    $response->assertDontSee('/keranjang');
    $response->assertDontSee('/checkout');
}
```

- [ ] **Step 2: Run it and confirm it fails** — `vendor/bin/phpunit --filter <name>`. **This host cannot run PHPUnit (no `vendor/`).** If it cannot be run locally, say so explicitly in the commit message and the ledger using the words `NOT TESTED LOCALLY — CI is the oracle`; never write `PASS`.

- [ ] **Step 3: Write the minimal fix.** No refactors, no drive-by cleanups, no new abstractions.

- [ ] **Step 4: Run the test and confirm it passes** — same BLOCKED/NOT TESTED discipline as Step 2.

- [ ] **Step 5: Commit each fix separately**, message naming the finding it closes.

- [ ] **Step 6: Do NOT delete the dead stub.** Superseded by the correction under "Confirmed dead code" — `MarketplaceComingSoon.php` stays on disk this wave, and `routes/web.php` and `BookingWizard.php` are not touched at all. Its disposition is a Task 5 ledger entry pairing it with `RenewalComingSoon` for one future combined-removal PR.

- [ ] **Step 7: Ledger every Minor finding verbatim**, not fixed, in `.superpowers/sdd/retrofit-marketplace/task-3-minor-findings.md`.

- [ ] **Step 8: If any Critical/Important finding is still open after this one bounded wave, stop and escalate for a human ruling.** Do not start a second wave. Max 5 rounds within this one wave; reaching round 4 is itself an escalation trigger.

## Task 4: Scoped re-review

**Files:** Only the files Task 3 touched.

- [ ] **Step 1: Dispatch one reviewer scoped to exactly the Task 3 diff** (`git diff <task-2-commit>..HEAD`), checking that each fix closes its finding without introducing a new one and that each has a real regression test.
- [ ] **Step 2: Commit** to `.superpowers/sdd/retrofit-marketplace/task-4-rereview.md`.

## Task 5: Explicit disposition of every self-flagged gap + documentation correction

**Files:**
- Modify: `.kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md` — only where it **overclaims**. It is already unusually honest, so expect narrow edits: the AC2 "three fields" count if Task 1 shows it is four, and any checkbox this retrofit actually closed.
- Modify: `.kiro/specs/funeral-marketplace-and-vendor-portal/design.md` — only if Task 2 found it overclaims. Its 15-table "Data" block is a **target** data model, not a claim that the tables exist; do not "correct" a design target into a status report.
- Modify: `docs/product/information-architecture.md` — only if Task 2 Step 3.3 ruled to fix the `{productSlug}` drift.
- Modify: `docs/planning/sprint-plan.md` — S4-T8's row (line 630) gets an **append-correction**. Do not edit or delete a single character of the existing row text.
- Modify: `docs/planning/retrofit-backlog.md` — §1 item 7's **Marketplace half only**, plus a new §2 entry.

- [ ] **Step 1: Give every self-flagged gap an explicit disposition** in a new `retrofit-backlog.md` §2 subsection headed `### funeral-marketplace-and-vendor-portal (Marketplace browse skeleton), retrofitted 09 Aug 2026`, matching items 1-4's existing two-column `| Gap | Disposition | Reason |` table format exactly. One row each for: AC2's missing columns; the category-code OPEN QUESTION; the `{productSlug}` IA drift; §6.1/§6.6/§6.8 unimplemented states; accessibility geometry; `tasks.md` §NOT TESTED bullets 2-5; `VendorProcessingStatus`'s forward definition; **the `MarketplaceComingSoon` dead stub — worded "paired with `RenewalComingSoon`, same dead-code pattern, one future PR should remove both together," carrying the Task 1 grep evidence that it is unreferenced;** **and the `mvp-scope`/`sprint-plan` cart-checkout contradiction, carrying this plan's OPEN DEPENDENCY text including the "second retrofit must not start" sentence.**

- [ ] **Step 2: Append-correct `sprint-plan.md`'s S4-T8 row.** Append to the existing cell; never rewrite it. Add: this retrofit's PR number, its CI run ID, the Critical/Important/Minor counts and how many were closed in-wave, and the `MarketplaceComingSoon` removal.

- [ ] **Step 3: Update `retrofit-backlog.md` §1 item 7 — the Marketplace half only.** Item 7's row reads `| 7 | `ServiceCatalog`, `Marketplace` | Not started | …`. A sibling agent is retrofitting `ServiceCatalog` concurrently and will edit the same row. Change the status to reflect **Marketplace done, ServiceCatalog per that lane** and add the Marketplace PR/CI links, leaving the ServiceCatalog wording untouched so the two edits merge cleanly. If a conflict arises when the second PR lands, resolve by keeping **both** halves.

- [ ] **Step 4: Run `bash ci/verify-docs.sh`** and confirm all gates still pass after the documentation edits (gate 8 specifically checks the marketplace spec does not duplicate the canonical catalogue).

- [ ] **Step 5: Commit.**

```bash
git add .kiro/specs/funeral-marketplace-and-vendor-portal/ docs/planning/ docs/product/
git commit -m "Task 5: disposition of all findings; tasks.md/sprint-plan.md/retrofit-backlog.md corrections"
```

## Task 6: Finish the branch

- [ ] **Step 1: Run `php -l` across every changed PHP file** and `bash ci/verify-docs.sh`. The PHP test suite runs in CI only — state that explicitly rather than claiming a local pass.
- [ ] **Step 2: Use `superpowers:finishing-a-development-branch`** — push `retrofit-marketplace`, open a PR against `docs/design-system-and-planning`. **Do not merge.**
- [ ] **Step 3: Wait for CI.** If red, fix forward within this branch; a CI failure caused by the parallel lanes' merges is a rebase, not a finding.
- [ ] **Step 4: Once the PR exists and CI is green, fill in Task 5's PR/CI placeholders** and push a final correcting commit.
- [ ] **Step 5: Report back to the team lead**: PR number, finding counts and disposition, CI status. Do not merge.

---

## Self-review

**Spec coverage.** AC1 (nine-code catalogue) and the browse half of AC3 get real review across Tasks 1-4 — they are the only ACs with shipped code. AC2 gets explicit disposition of an already-self-flagged, unimplementable-as-specified gap (Task 2 Step 3.1, Task 5 Step 1). AC12's `DIBAYAR`≠`SELESAI` invariant is reviewed where it is actually encoded, in `VendorProcessingStatus`'s omission of `DIBAYAR` (domain slice). AC15's land-rights exclusion is checked by the domain slice's catalogue-drift review — a tenth product code appearing would fail `ProductCatalogueSeedTest`. AC4, AC5-AC11, AC13, AC14 have no code and are out of scope by construction, disposed in Task 5 Step 1, not silently dropped.

**Placeholder scan.** The only bracketed placeholders are Task 5's PR number and CI run ID, which cannot exist until Task 6 creates the PR — the same accepted exception all four prior retrofits took, and Task 6 Step 4 closes them.

**Type consistency.** `ProductCode`, `MarketplaceProductCategory`, `MarketplaceCatalogQuery`, `VendorProcessingStatus`, `MarketplaceIndex`, `ProductDetail`, `MarketplaceComingSoon` are named identically everywhere they appear across tasks, and every class name, line count, route name, and test-method name in "Current shipped state" was read from the committed source in this worktree at `7bc3b8d`, not recalled. The two `routes/web.php` and `sprint-plan.md` quotes were copied from the files, not paraphrased.

## Verification

- [ ] Plan doc exists and is committed **before** any review work starts.
- [ ] Ledger `.superpowers/sdd/retrofit-marketplace/` populated with 3 briefs, 3 reports, the whole-module review, the Minor-findings park, and the re-review.
- [ ] The whole-module review reproduces the `mvp-scope`/`sprint-plan` contradiction **verbatim**, picks no side, and states the second-retrofit precondition.
- [ ] A bounded fix-wave commit exists per Critical/Important finding, each with a regression test; every Minor finding visibly parked.
- [ ] `MarketplaceComingSoon.php` is **still on disk, untouched**, and ledgered in `retrofit-backlog.md` §2 as a combined future cleanup paired with `RenewalComingSoon`. `routes/web.php` and `BookingWizard.php` have zero changes in this branch's diff.
- [ ] Every self-flagged gap has an explicit disposition in `retrofit-backlog.md` §2 — none silently dropped.
- [ ] `sprint-plan.md`'s S4-T8 row is **append-corrected**, original text byte-for-byte intact.
- [ ] `retrofit-backlog.md` §1 item 7's Marketplace half updated without disturbing the ServiceCatalog half.
- [ ] A PR against `docs/design-system-and-planning` exists with CI green. **Not merged by this lane.**
