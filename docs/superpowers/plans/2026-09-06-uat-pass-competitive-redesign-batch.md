# UAT Pass — Competitive-Redesign Batch (PRs #226, #232–238)

**Status:** Not yet executed — this is the plan, not the pass. Every box below is `[ ]` until someone (human or agent) actually performs the step against the real deployed environment and records the result.

**Scope:** The 8 PRs merged into `docs/design-system-and-planning` on 2026-09-06 and deployed the same day to dev (`http://<dev-host>:8081`) and beta/production (`https://makam.co.id`) at commit `53626f59`, image digest `sha256:149ac33766fbd1056afa2fc01c19ada139885348d0d46ac5113776d43569c56e`:

| PR | Feature |
|---|---|
| #226 | Pre-demo known-gap closure (service complaints admin flow, marketplace listing details, wizard polish) |
| #232 | Auth actor-copy on `/masuk` and `/daftar` |
| #233 | Marketplace product go-live photo gate |
| #234 | Homepage live plot-availability preview |
| #235 | Memorial visit check-in ("Catat kunjungan") |
| #236 | Booking wizard shows selected grave plot on Ringkasan Pesanan |
| #237 | SvelteKit rewrite feasibility analysis (docs only — **no UAT needed**, excluded below) |
| #238 | Recovered UAT hardening fixes (invoices, reports, admin crashes, pricing) |

**Out of scope:** re-testing anything NOT touched by this batch. `docs/testing/release-gates.md` remains the authority for the product's full historical scope; this document only re-verifies what changed today.

## Global constraints

- Every box is checked only against a real, currently-passing automated test (file + test name) **or** a real manual step performed against the actual live dev/beta URL and its actual observed result — never narrated without evidence, per `AGENTS.md` §Infrastructure-agent execution: "Never report PASS for a check that was not executed."
- Manual steps target **dev** first (`http://<dev-host>:8081`, no real customer traffic) and are re-run against **beta/production** (`https://makam.co.id`) only for the items marked "Also verify on beta" — beta is live customer-facing traffic, so its manual pass should be the minimum needed to confirm the same behavior, not a second full click-through.
- `G-MEM-01` (Memorial/QR, including check-in) is seeded **closed**. Every #235 item below is a **code-path verification against dev with the gate temporarily inspected, not a claim that the feature is visible to real users** — this batch does not open the gate, and this UAT pass must not imply it does.
- A partial pass gets its specific remaining gap named in the box's own line — never checked prematurely.

---

## 1. PR #232 — Auth actor-copy

- [ ] **Automated coverage already in CI.** `tests/Feature/Livewire/Public/Auth/LoginPageTest.php::test_the_page_names_all_three_account_types_and_links_the_other_two_portals` and `RegisterPageTest.php::test_the_page_states_that_registering_here_creates_a_customer_account_only` — both passed in CI run for commit `8c2026e7` (PR #232). Cite the run id when checking this box.
- [ ] **Manual — dev:** open `/masuk`. Confirm the subhead names all 3 account types and the two links (`Vendor Jasa`, `Pengelola TPU`) are real, clickable, and each lands on its real Filament panel login (`/vendor/login`, `/operator/login`) without a 404 or blank page.
- [ ] **Manual — dev:** open `/daftar`. Confirm the "akun Pelanggan" clarification line renders.
- [ ] **Also verify on beta:** open `https://makam.co.id/masuk` and confirm the same two links resolve on production's real domain (not just dev's).

## 2. PR #233 — Marketplace photo go-live gate

- [ ] **Automated coverage already in CI.** 184 tests / 1026 assertions across `ProductCatalogueSeedTest.php`, `ProductResourceTest.php`, `MarketplaceIndexRouteTest.php`, `ProductDetailRouteTest.php` — passed in CI for commit `2f93a19b` (PR #233).
- [ ] **Manual — dev, admin panel:** as an admin, open a marketplace product in the admin panel, clear its photo, and attempt to save with "Aktif" toggled on. Confirm a real Filament validation error appears (not a raw exception page) and the save is refused.
- [ ] **Manual — dev, public:** browse `/marketplace`. Confirm every currently-active listing shows a real photo (the 9 seeded products) and that no listing shows a broken `<img>` icon.
- [ ] **⚠️ Known, flagged-not-fixed gap — do not test as if it works:** a NEW admin-uploaded photo will 404 until a human runs `php artisan storage:link` on both dev and beta (see PR #233 body). If this hasn't been run yet, do not check this box based on testing a fresh upload — that path is expected to fail until that command runs. Confirm whether `storage:link` has been run (`ls -la public/storage` on the host) before deciding whether to include a fresh-upload check here.

## 3. PR #234 — Homepage live plot-availability preview

- [ ] **Automated coverage already in CI.** 22 tests / 102 assertions across `PlotAvailabilityPreviewTest.php`, `PlotAvailabilityPreviewNeverMutatesTest.php`, `HomePageRouteTest.php` — passed in CI for the final commit on PR #234 (`33f46e93`), including the whole-branch review's mutation-tested fixes (Pint violation, vacuous tier-guard test).
- [ ] **Manual — dev and beta:** load the homepage. Confirm it renders normally with **no visible plot-preview section** — this is the documented, correct honest-empty state, since no cemetery in real data is `plot_tracking_mode = granular` yet (spec §3/§8). A visible section here would actually indicate something unexpected changed; the absence IS the passing result.
- [ ] **Manual — dev only, DB-level:** confirm via `php artisan tinker` or a direct query that `cemeteries.plot_tracking_mode` is genuinely `aggregate` for all real cemeteries today, so the "honest empty" result above is verified as the correct state, not an accidental failure silently swallowed by the component's own try/catch. (`SELECT slug, plot_tracking_mode FROM cemeteries;`)
- [ ] **Manual — dev, real-data activation smoke test (optional, higher-effort):** if there's time, flip ONE dev-only cemetery to `granular` via `SetCemeteryPlotTrackingMode`, add one `CemeteryBlock`/`GravePlot`, reload the homepage, and confirm the section now renders the real block/slot/badge — then flip it back. This is the one path CI cannot fully exercise against real production-shaped data and is worth a real human look at least once.
- [ ] **Regression check — this was the one that broke CI (k6 + Playwright jobs) before the cache fix:** hit the homepage twice in a row on dev (`curl` or browser refresh) and confirm the SECOND load (a cache hit) also returns 200 — this is the exact scenario the `__PHP_Incomplete_Class` bug broke, and it's now fixed by returning plain arrays instead of Eloquent-model Collections from the cache. Already confirmed once during deployment smoke-testing on 2026-09-06 (both dev and beta homepage returned 200) — re-check here is for a second, independent confirmation at leisure, not because the first one is in doubt.

## 4. PR #235 — Memorial visit check-in ("Catat kunjungan")

**Gate is closed (`G-MEM-01`) — nothing here is visible to a real visitor today.** Every item below verifies the CODE is correct and ready, not that a real user can see it.

- [ ] **Automated coverage already in CI.** 83 tests across the full Memorial domain + public/family Livewire + admin suites (final count after the whole-branch-review fix wave) — passed in CI for the final commit on PR #235 (`a534842e`), including the reuse-of-`ResolveMemorialQr` proof, the rate-limit bucket-isolation/decay proof, the family-dashboard note-never-renders proof, and the delete-guard extension.
- [ ] **Manual — dev, gate temporarily opened in a throwaway test (do NOT do this on beta):** using a `tinker` session against DEV ONLY, temporarily flip `G-MEM-01` open for one test memorial profile, scan/visit its `/m/{token}` URL, click "Catat kunjungan," and confirm: (a) the success notice appears, (b) a row lands in `memorial_visit_checkins`, (c) the family dashboard (`/kenangan/{profileId}`) shows the count/visitor_label but never the note, (d) the admin relation manager shows the note. Revert the gate to closed afterward — this must not leave `G-MEM-01` open on dev by accident.
- [ ] **⚠️ Two flagged, unresolved product/privacy items — confirm these are still tracked, not silently forgotten:** (1) retention/deletion policy for `memorial_visit_checkins` rows is genuinely undecided (spec §0); (2) the family dashboard shows unmoderated `visitor_label` text with no removal path (final review finding #2, deliberately not auto-fixed). Neither blocks this UAT pass, but this box exists so a UAT sign-off doesn't imply either was resolved.

## 5. PR #236 — Grave plot detail on Ringkasan Pesanan

- [ ] **Automated coverage already in CI.** 3 new tests (Screen 2 with a held plot, Screen 2 with none, CONFIRMATION after draft-to-order conversion) plus the full 168-test booking suite — passed in CI for commit `ed323705` (PR #236).
- [ ] **Manual — dev, full booking flow with a real held plot:** this requires a `granular`-tier cemetery with real block/plot data, which — per PR #234's own finding — does not exist in real seed data today. Either (a) temporarily provision one dev-only cemetery/block/plot as in PR #234's optional check above and run a real booking through it, confirming "Petak: Blok ... Slot ... — <cemetery>" appears on both the Screen 2 summary and the final confirmation screen, or (b) if no time for that setup, explicitly record this box as **NOT TESTED — no granular-tier cemetery exists in current data to exercise this path live** rather than checking it based on CI alone.
- [ ] **Manual — dev, aggregate-tier booking (the common real path today):** run an ordinary booking against any real (aggregate-tier) cemetery and confirm Ringkasan Pesanan shows no "Petak:" line and no visual regression versus before this PR.

## 6. PR #226 + #238 — Service complaints, reports, admin fixes, pricing correction

- [ ] **Automated coverage already in CI.** PR #226: full existing suite green at merge. PR #238: 89 tests / 266 assertions across the 11 touched/new test files (report CSV exports on all 5 tabs, Memorial Profile infolist, marketplace order crash, preneed cert check, invoice labels, pricing migration) — passed in CI for commit `1a156e5e`.
- [ ] **Manual — dev, admin panel — CSV export (the crash PR #238 fixed):** as an admin, open EACH of the 5 report tabs (Orders, Outgoing Payments, Receipts, Renewal Period, Vendor Performance) and click "Export CSV" on each. Confirm a real CSV downloads with no `TypeError` on any of the 5 — this was broken on ALL 5 before this fix, so all 5 need a real click, not just one representative tab.
- [ ] **Manual — dev, admin panel:** open a Memorial Profile in the admin panel and confirm the view page now shows a real infolist (previously missing/blank).
- [ ] **Manual — dev, admin panel:** open a marketplace order in the admin panel and confirm the product name, customer name, and payable amount all render without a crash.
- [ ] **Manual — dev, service complaints:** as an admin, walk one service complaint through Start Investigating → Resolve (or Dismiss) and confirm the transition succeeds and the resource list/infolist reflect the new state.
- [ ] **Manual — dev, DB spot-check:** confirm the pricing-fixture correction actually landed — query `vendor_listings.price_minor`/`service_areas.delivery_fee_minor` for the 3 fictional demo vendors named in `RealisticMarketplacePricingExampleData::vendors()` and confirm the values are now 100x what they were pre-migration (e.g. "Karangan Bunga Papan" priced at Rp 650.000, not Rp 6.500) — **only meaningful if this environment ever ran the buggy seed migration with `SEED_REALISTIC_MARKETPLACE_PRICING=true`; if it never did, this migration is a documented no-op and this box should read NOT APPLICABLE, not PASS.**
- [ ] **Manual — dev, preneed:** check a preneed certificate's status using the real order REFERENCE (not the raw UUID) and confirm it now resolves correctly.
- [ ] **Manual — dev, invoices:** open a receipt/invoice summary and confirm the product-type label renders as real Indonesian text, not a raw enum value.

---

## Sign-off

- [ ] All boxes above are either checked with real evidence, marked NOT TESTED with a named reason, or marked NOT APPLICABLE with a named reason — no box left silently blank.
- [ ] Any box that surfaces a NEW regression gets its own ledger entry / issue, not silently reverted or ignored.
- [ ] This pass's results are folded into `docs/testing/release-gates.md` wherever a bullet there overlaps with what this batch changed (none currently do directly, since all 8 PRs are additive features/fixes rather than changes to the v0.5 MVP scope gates — confirm this is still true before signing off, since release-gates.md is the durable record and this document is not).

## Verification

This document is a plan, not a report. "Verification" for THIS document means: every box above has been either checked with a cited test/manual result, or explicitly marked NOT TESTED / NOT APPLICABLE with a reason — before this file is considered closed out. No box should ever be left as bare `[ ]` in a document claimed as "done."
