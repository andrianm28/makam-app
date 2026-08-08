# Traceability Matrix — v0.5

## Status legend

- `Specified` — spec and screen exist; **no test evidence yet**.
- `Specified (gated fallback)` — `Specified`, and the user-facing step is required even when an external gate is closed (see section C).
- `Covered` — an automated test exists, is named in this row's **Test evidence** cell, asserts what this row claims, and passes in CI. `AGENTS.md`: *"Every traceability item marked `Covered` needs test evidence."*

`ci/verify-docs.sh` GATE 7 enforces the mechanical half of `Covered`: every section B row marked `Covered` must name at least one test path in its **Test evidence** cell, and every path it names must exist on disk. What a gate cannot check is whether that test actually asserts what the row claims — section D records that reading per row, with the specific test methods, so a reviewer can re-check it instead of trusting a filename.

**No browser-level test suite exists in this repository.** There is no Dusk, Playwright, or Cypress harness and no `*.spec.ts` / `*.cy.js` file (verified 08 Aug 2026). Every `E2E-*` value in the **Test family** column therefore names a family that is still unimplemented; the evidence behind the `Covered` rows below is server-side HTTP/Livewire feature tests. `AGENTS.md` §Testing separately requires *"Browser tests cover all four homepage routes, nine booking steps, marketplace flow, renewal flow, FAQ, admin, and vendor"* — **that requirement is not satisfied by any row in this file.**

### Revision history

v0.4 — 25 July 2026, finding H-3: statuses in section B previously read `Covered` for items that were specified but untested; all 31 were corrected to `Specified`. The repository currently contains no test files.

**Correction — finding T-A, 08 August 2026.** The v0.4 note above is kept verbatim rather than rewritten, per this repository's convention of recording superseded reasoning (see `docs/planning/sprint-plan.md` findings N-10/N-11). Two parts of it have since gone stale:

1. *"The repository currently contains no test files"* was true when written and is now false. Verified 08 Aug 2026 by direct count: **90 test files** under `tests/`, **503 `test_*` methods**, zero of them the Laravel skeleton's `ExampleTest.php` stubs (both were deleted). The whole suite passes in CI — GitHub Actions run `30196713664`, job `PHP (validate, lint, analyse, test)` (id `89779419790`), step *Tests* → `success`, on commit `3f5d14f`, which is the current `HEAD` of `docs/design-system-and-planning`.
2. The v0.4 status legend said `Covered` was *"unusable until tests land"*. Tests have landed, so that sentence is removed from the legend above and preserved here.

H-3's blanket downgrade of all 31 rows was correct in July and became wrong in the opposite direction as real tests landed: rows whose tests genuinely exist and pass kept reporting `Specified`, understating coverage as badly as the pre-H-3 file overstated it. **10 rows are raised to `Covered` in v0.5** (HOME-01…HOME-04, FAQ-01…FAQ-06) — each one read against its test file first, not raised on the strength of a filename. The other 21 stay `Specified`; section D says why for the ones where a test file exists but does not cover the row's claim.

## A. RKS authority

| RKS | Capability | Spec | Gate/control |
|---|---|---|---|
| K23 | TPU/TPS directory and map | `cemetery-directory-and-availability` | Admin master data |
| K24 | Availability | `cemetery-directory-and-availability` | Manual confirmation by default |
| K25 | New, overlapping, Urgent, Pre-Need | `public-booking-wizard`, `at-need-booking`, `pre-need-contracting` | Ops/legal gates |
| K26 | Basic/add-on services | `package-and-service-bundles`, service catalog | Versioned price/availability |
| K27 | Booking and status | `public-booking-wizard`, `booking-and-order-orchestration` | No early payment |
| K28 | Deceased data/documents | `public-booking-wizard`, booking orchestration | Private, short URL, audit |
| K29 | Funeral marketplace | `funeral-marketplace-and-vendor-portal` | Single-vendor MVP |
| K30 | Vendor portal | `funeral-marketplace-and-vendor-portal` | Query scope; payout gate |
| K31 | Renewal | `renewal-and-grave-registry` | Tariff source; external marking |
| K32 | Registry/search/reminder | `renewal-and-grave-registry` | Data/privacy gate |
| K33 | Recurring care | `recurring-care-subscriptions`, `grave-care-fulfillment` | Billing != fulfillment |
| K34 | Operator dashboard | `cemetery-operator-dashboard` | Non-blocking fallback |
| K35 | Admin dashboard | `admin-operations` | Audited changes |

## B. Stakeholder Workflow MVP

The **Test evidence** column holds repo-relative paths to the tests backing a `Covered` row, and `—` for a row with none. GATE 7 reads this column; see section D for the method-level trail.

| ID | Expectation | Canonical doc/spec | Screen | Test family | Test evidence | Status |
|---|---|---|---|---|---|---|
| HOME-01 | Pemesanan Makam menu | `public-home-and-navigation` | PUB-001 | E2E-HOME | `tests/Feature/Livewire/Public/HomePageRouteTest.php` | Covered |
| HOME-02 | Layanan Pemakaman menu | `public-home-and-navigation` | PUB-001 | E2E-HOME | `tests/Feature/Livewire/Public/HomePageRouteTest.php` | Covered |
| HOME-03 | Perpanjangan menu | `public-home-and-navigation` | PUB-001 | E2E-HOME | `tests/Feature/Livewire/Public/HomePageRouteTest.php` | Covered |
| HOME-04 | FAQ menu | `public-home-and-navigation` | PUB-001 | E2E-HOME | `tests/Feature/Livewire/Public/HomePageRouteTest.php` | Covered |
| BOOK-01 | Lima kota awal | directory + wizard | PUB-010 | E2E-BOOK-01 | — | Specified |
| BOOK-02 | TPU/TPS detail | directory | PUB-011 | E2E-BOOK-02 | — | Specified |
| BOOK-03 | Empat jenis layanan | wizard | PUB-012 | E2E-BOOK-03 | — | Specified |
| BOOK-04 | Basic/add-on services | service catalog + wizard | PUB-013 | E2E-BOOK-04 | — | Specified |
| BOOK-05 | Ringkasan | wizard/quote | PUB-014 | E2E-BOOK-05 | — | Specified |
| BOOK-06 | Data pemesan | wizard | PUB-015 | E2E-BOOK-06 | — | Specified |
| BOOK-07 | Almarhum + documents | wizard | PUB-016 | E2E-BOOK-07 | — | Specified |
| BOOK-08 | Payment | wizard/payment contract | PUB-017 | E2E-BOOK-08 | — | Specified (gated fallback) |
| BOOK-09 | Confirmation/invoice/notification | wizard + notification matrix | PUB-018 | E2E-BOOK-09 | — | Specified |
| MKT-01 | Flower categories | marketplace catalog | PUB-020/021 | E2E-MKT | — | Specified |
| MKT-02 | Gravestone categories | marketplace catalog | PUB-020/021 | E2E-MKT | — | Specified |
| MKT-03 | Care intervals | marketplace catalog | PUB-020/021 | E2E-MKT | — | Specified |
| MKT-04 | Checkout/payment/vendor processing | marketplace spec | PUB-022–024 | E2E-MKT | — | Specified |
| REN-01 | City | renewal spec | PUB-030 | E2E-REN | — | Specified |
| REN-02 | TPU/TPS | renewal spec | PUB-030 | E2E-REN | — | Specified |
| REN-03 | Grave search | renewal spec | PUB-031 | E2E-REN | — | Specified |
| REN-04 | Fee | renewal spec | PUB-032 | E2E-REN | — | Specified |
| REN-05 | Payment | renewal spec | PUB-033 | E2E-REN | — | Specified (gated fallback) |
| REN-06 | Confirmation/invoice | renewal spec | PUB-034 | E2E-REN | — | Specified |
| FAQ-01 | Cara memesan | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php`<br>`tests/Feature/Domain/Faq/FaqCategorySeedTest.php` | Covered |
| FAQ-02 | Dokumen | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php`<br>`tests/Feature/Domain/Faq/FaqCategorySeedTest.php` | Covered |
| FAQ-03 | Pembayaran | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php`<br>`tests/Feature/Domain/Faq/FaqCategorySeedTest.php` | Covered |
| FAQ-04 | Perpanjangan | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php`<br>`tests/Feature/Domain/Faq/FaqCategorySeedTest.php` | Covered |
| FAQ-05 | Pembayaran gagal | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php`<br>`tests/Feature/Domain/Faq/FaqCategorySeedTest.php` | Covered |
| FAQ-06 | Customer service | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php`<br>`tests/Feature/Domain/Faq/FaqCategorySeedTest.php` | Covered |
| ADMIN-01 | Admin dashboard modules | `admin-operations` | ADM-* | E2E-ADMIN | — | Specified |
| VENDOR-01 | Vendor dashboard modules | marketplace/vendor spec | VND-* | E2E-VENDOR | — | Specified |

## C. Gate interpretation

`Specified (gated fallback)` means the user-facing step and outcome are required even when an external capability is inactive. A closed gate cannot be used to remove Step 8, hide the feature silently, or report a false success.

## D. Evidence trail for the `Covered` rows

Written 08 Aug 2026 (finding T-A) by reading each test file, not its filename. Test-method names below are the real ones; if a rename breaks this trail, the trail is what is wrong, not the row.

### HOME-01…HOME-04 — the four primary homepage menus

All four rows rest on `tests/Feature/Livewire/Public/HomePageRouteTest.php`, which drives real HTTP requests against `/` with real seeded data.

- **Shared, covers all four rows:** `test_all_four_menus_appear_in_ac1s_exact_order` asserts that "Pemesanan Makam", "Layanan Pemakaman", "Perpanjangan Makam", and "FAQ" all appear, and appear in that order, scoped from `<body>` onward so the page `<title>` cannot satisfy the match. `test_viewing_the_homepage_records_menu_impressions_without_sensitive_data` asserts exactly four impression rows with `menu_key` `['pemesanan', 'layanan', 'perpanjangan', 'faq']` — a per-menu assertion, not an aggregate one. `test_homepage_returns_ok` asserts PUB-001 renders at all.
- **HOME-01** additionally: `test_pemesanan_makam_is_the_primary_call_to_action` (`Pesan Makam` CTA plus `href="/pemesanan-makam"`) and `test_pemesanan_makam_stub_route_returns_ok_not_404`.
- **HOME-02** additionally: `test_marketplace_stub_route_returns_ok_not_404` — `/marketplace`, the `layananHref` target in `resources/views/components/mk/header.blade.php`, returns 200.
- **HOME-03** additionally: `test_perpanjangan_stub_route_returns_ok_not_404` — `/perpanjangan` returns 200.
- **HOME-04** additionally: `test_faq_highlights_link_into_the_real_faq_routes` asserts the homepage links to the real `faq.index` and `faq.show` routes, and `test_faq_highlights_degrade_gracefully_when_the_faq_query_fails` asserts the homepage still renders the four menus when the FAQ table is dropped underneath it.

**Scope of the claim.** These rows claim the *menu* exists on PUB-001, in the mandated order, and leads to a route that answers 200. They do **not** claim the destination is built: `/pemesanan-makam`, `/marketplace`, and `/perpanjangan` are explanatory "Segera Hadir" stubs, which is why every BOOK-*, MKT-*, and REN-* row below stays `Specified`.

### FAQ-01…FAQ-06 — the six mandated FAQ categories

Each row is one of the six categories `AGENTS.md` §FAQ requires be seeded and preserved.

- **Every row, category identity:** `tests/Feature/Domain/Faq/FaqCategorySeedTest.php::test_exactly_six_categories_are_seeded` and `::test_categories_have_the_exact_catalogue_codes_and_labels_in_order` assert exactly six categories with the exact codes, display labels, and sort order from `docs/product/faq-catalog.md` — `CARA_MEMESAN` (FAQ-01), `DOKUMEN` (FAQ-02), `PEMBAYARAN` (FAQ-03), `PERPANJANGAN` (FAQ-04), `PEMBAYARAN_GAGAL` (FAQ-05), `CUSTOMER_SERVICE` (FAQ-06). `::test_find_by_code_resolves_every_known_code` covers each code individually.
- **Every row, public reachability (PUB-040):** `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php::test_category_chip_links_never_include_the_real_seeded_draft_articles_category_as_leaking_its_content` loops all six category slugs, asserting each returns 200 and none leaks the seeded draft. `::test_faq_index_returns_ok_and_lists_seeded_published_articles` covers `/faq` itself.
- **Every row, content presence:** `tests/Feature/Domain/Faq/FaqArticleSeedTest.php::test_each_category_has_its_exact_catalogue_question_count` is data-provided per category and asserts the exact `faq-catalog.md` article count for each of the six (4/3/4/4/4/4), not merely "more than zero".
- **Per-row extras:** FAQ-01 — `FaqIndexRouteTest::test_faq_index_returns_ok_and_lists_seeded_published_articles` and `tests/Feature/Livewire/Public/Faq/FaqArticleDetailRouteTest.php::test_a_real_seeded_published_article_renders_title_body_updated_date_and_cs_cta` (PUB-041, article `bagaimana-cara-memesan-makam`). FAQ-02 — `FaqIndexRouteTest::test_faq_category_route_narrows_the_list_to_that_category_only` and `::test_empty_category_state_renders_when_a_category_has_zero_published_articles`, both against `dokumen`. FAQ-03 — `::test_search_finds_matching_published_articles_across_categories` surfaces the Pembayaran article `Kapan invoice diterbitkan?`. FAQ-06 — `::test_search_with_no_results_shows_related_categories_and_support_path_not_a_bare_empty_state` asserts the Customer Service category and `Hubungi Customer Service` / `/bantuan` path.
- **`AGENTS.md` §FAQ "draft/unpublished articles must never be public"**, which every FAQ row inherits: `tests/Feature/Domain/Faq/FaqArticleDraftExclusionTest.php` plus `FaqIndexRouteTest::test_search_never_returns_the_real_seeded_draft_article`, `::test_a_freshly_created_draft_and_unpublished_article_never_appear_on_the_index`, and `FaqArticleDetailRouteTest::test_the_real_seeded_draft_article_slug_404s_not_a_gated_or_hidden_page`.

**Scope of the claim.** FAQ-04 and FAQ-05 rest on the shared per-category evidence only (identity, exact article count, route 200, no draft leakage) — there is no test asserting the *wording* of any individual Perpanjangan or Pembayaran Gagal answer. That is coverage of what the row states (the category is present and publicly reachable with its catalogue content seeded), not of answer correctness, which no automated test in this repository checks for any category.

### Rows deliberately left `Specified` despite nearby test files

- **BOOK-01 (Lima kota awal).** Tests touching the five launch cities exist — `tests/Feature/Domain/CemeteryDirectory/CemeterySeedTest.php::test_every_launch_city_has_at_least_one_seeded_cemetery` asserts all five, and `HomePageRouteTest::test_section_5_shows_published_dummy_cemeteries_and_excludes_the_draft_one` renders seeded cemeteries on PUB-001. Neither exercises PUB-010, the booking wizard's city step, which is what BOOK-01 claims and which does not exist yet.
- **BOOK-02…BOOK-09, MKT-01…MKT-04, REN-01…REN-06.** The wizard, marketplace, and renewal screens are not built; their routes are "Segera Hadir" stubs. No test asserts any of these rows.
- **ADMIN-01.** Real admin-side tests exist — `tests/Feature/Filament/Admin/Faq/**` (six files) covers the FAQ admin resource, and `tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php` covers panel access control. Both are scoped to one resource and to authorization respectively, not to ADMIN-01's claim of the full `admin-operations` dashboard module set.
- **VENDOR-01.** No vendor panel and no vendor panel test exists; the only vendor-named code in the repository is `app/Domain/Marketplace/VendorProcessingStatus.php`.
