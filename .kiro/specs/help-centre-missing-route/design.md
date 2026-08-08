# Design — `/bantuan` had no route

## Root cause analysis

Not a compiler or framework defect — a link shipped ahead of its destination, the same class of gap `App\Livewire\Public\Legal\PrivacyPolicy` closed on 26 Jul 2026 for `/privasi` and `/syarat-ketentuan` (commit `1e196bf`).

`<x-mk.header>` was built in Sprint 3 with `bantuanHref` defaulting to `/bantuan`, per `information-architecture.md` §2's requirement that the Bantuan action be persistent on every page. No route by that name was ever registered — nothing in the Sprint 3 or Sprint 4 batches that touched the header owned "build the destination page" as a task, because no spec claims that ownership (see `docs/domain/traceability-matrix.md` §E).

**Why it went undetected for two sprints:** the one test that existed for this path, `tests/Feature/Livewire/Public/Legal/FooterLegalLinksRouteTest::test_bantuan_link_remains_an_honest_unbuilt_forward_reference`, **asserted the 404 as the expected, correct state** — a deliberate, documented choice at the time ("this batch's scope" note in the test), not an oversight. A regression test that pins a known gap protects against silently *widening* the gap, but does nothing to prompt closing it, and gives a false sense that the state is monitored. It surfaced only when a full-repository audit on 8 Aug 2026 cross-referenced every `<a href="/bantuan">` call site against `routes/web.php` and found none.

## Fix strategy

Add `App\Livewire\Public\Support\HelpCentre` (screen PUB-060) and register `Route::get('/bantuan', HelpCentre::class)->name('bantuan.index')`. The page is deliberately static — no domain read, no form — because `design-system.md` §6.5's provider-unavailable copy and the FAQ's empty state send a stuck user here, so it must render even when the database is down. Contact data comes from `App\Support\ContactInfo` / `App\Support\CompanyInfo` only, and the page states plainly that those channels are placeholders, not a staffed line.

Invert, rather than delete, the test that pinned the old 404 — its assertion becomes the regression check that the link now resolves, and its history stays visible in the file (see that test's own doc block).

## Properties validated

1. **Bug reproducible before the fix.** Verified by reading `routes/web.php` at commit `3f5d14f` (parent of the fix): no route named `bantuan.index` or matching URI `bantuan` exists, while `resources/views/components/mk/header.blade.php`, `layouts/app.blade.php`, and six further views already contained `href="/bantuan"` or the `bantuanHref` default at that commit.
2. **Bug resolved after the fix.** `tests/Feature/Livewire/Public/Support/HelpCentreRouteTest.php` (15 methods) asserts the route resolves, returns 200, and renders the required PUB-060 content — channels, hours, emergency disclaimer, no SLA/24-7 claim, works without JavaScript. All 15 ran and passed in CI: GitHub Actions run `31237318086`, job **PHP (validate, lint, analyse, test)** (id `93052168835`), commit `97dfbbf`.
3. **No unintended side effect.** `FooterLegalLinksRouteTest::test_bantuan_link_from_the_footer_reaches_the_real_help_page` (renamed from the old pinning test) confirms `/privasi` and `/syarat-ketentuan` still render and still link `/bantuan`, and that following the link now reaches a real page rather than a different destination. No other route, component, or shared file was edited by the fix. Same CI run confirms this test passed alongside the rest of the suite.

## What this does not resolve

Which feature spec, if any, should own PUB-060's acceptance criteria remains open — that is a spec-authoring decision, not a defect, and is tracked separately (see `docs/domain/traceability-matrix.md` §E and the sprint-plan finding ledger). This bugfix spec closes the 404; it does not assign ownership.
