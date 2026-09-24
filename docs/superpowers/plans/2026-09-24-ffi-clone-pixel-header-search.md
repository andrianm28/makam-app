# FFI Clone Pixel Fidelity — Header Search Bar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a real, working search bar to the desktop header, matching FFI's real shape and position, wired to a real cemetery-directory search capability.

**Architecture:** A plain `<form>`/`<input type="search">` in `header.blade.php`'s desktop bar submits a GET request to `cemeteries.index` with a `q` parameter. `CemeteryDirectoryIndex` gains a `#[Url(as: 'q')]` property; `CemeteryPublicQuery::published()` gains a `name` parameter mapped to a new `Cemetery::scopeMatchingName()` case-insensitive substring scope. No new search backend/infrastructure — an extension of the existing city/type filter pattern.

**Tech Stack:** Laravel Livewire, Blade, PostgreSQL `ilike`.

**Spec:** .scratch/ffi-clone-pixel-fidelity/spec.md (ticket 01: .scratch/ffi-clone-pixel-fidelity/issues/01-header-search-bar.md)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah di bawah keluar dengan status 0.

    <akar specflow>/scripts/check-plan-headings.sh    <rencana ini> <task-brief>
    <akar specflow>/scripts/check-seam-constraints.sh <rencana ini> <task-brief>

## Global Constraints

- Reuses `CemeteryDirectoryIndex`'s existing search capability — **RULING (this plan's own real finding, not assumed by the ticket): no such capability existed.** `CemeteryDirectoryIndex` only had `city`/`type` filters, no free-text search. Added a minimal `name` filter (Cemetery model scope + `CemeteryPublicQuery::published()` parameter + a new `q` Livewire property) as part of this ticket, since a search bar with nothing real to search would fail the ticket's own User Story 2 ("I want it to actually search real cemetery data"). This is a small, contained extension of the existing filter pattern, not new search infrastructure (no new table, no new index — the directory is documented elsewhere as "never a large table").
- Mobile header unchanged — confirmed by reading FFI's own real mobile header (no search bar there either) before implementing.
- No live-query/autocomplete JS — a plain form submit, matching FFI's real `DesktopHeader.tsx` `onSubmit` handler exactly.
- Real, unmodified Heroicons v2.2.0 `magnifying-glass.svg` used for the search icon (fetched directly from the real upstream source, not FFI's own slightly different copy of the glyph) — this project's own established icon-provenance discipline.
- `q` chosen as the URL param name (not `name`) — a conventional short search-query param name, distinct from `CemeteryPublicQuery::published()`'s own `$name` parameter it maps onto.

---

### Task 1: Add the `name` search capability to the cemetery directory's domain layer

**Files:**
- Modify: `app/Domain/CemeteryDirectory/Models/Cemetery.php` (new `scopeMatchingName`)
- Modify: `app/Domain/CemeteryDirectory/CemeteryPublicQuery.php` (`published()` gains `?string $name = null`)
- Modify: `app/Livewire/Public/Directory/CemeteryDirectoryIndex.php` (new `#[Url(as: 'q')] public string $q = ''`, wired into the `published()` call, `resetFilters()`, and the `filtersActive` computed value)
- Test: `tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php`

**Interfaces:**
- Consumes: nothing new from elsewhere.
- Produces: `Cemetery::scopeMatchingName(Builder $query, string $name): void` (case-insensitive `ilike` substring match on `name`). `CemeteryPublicQuery::published(?string $city, ?string $type, ?string $name): Collection` — `$name` follows the exact same `null`-means-unfiltered contract as `$city`/`$type`. `CemeteryDirectoryIndex::$q` (public string, default `''`, `#[Url(as: 'q', history: true)]`) — consumed by Task 2's header form via its `name="q"` input.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `Livewire::test(CemeteryDirectoryIndex::class)`, mengikuti pola `test_city_filter_returns_only_that_citys_published_cemeteries`/`test_type_filter_returns_only_that_type` yang sudah ada di file test yang sama. Cakup SETIAP perilaku task ini MELALUI seam itu: substring match (bukan exact match), case-insensitivity, `null`/`''` berarti tidak ada filter, dan `resetFilters()` benar-benar mereset `q` juga.

- [x] `Cemetery::scopeMatchingName()` added, following `scopeInCity`/`scopeOfType`'s exact style.
- [x] `CemeteryPublicQuery::published()` gains `?string $name = null`, applies `$query->matchingName($name)` when non-null, docblock updated.
- [x] `CemeteryDirectoryIndex::$q` added, wired into the `published()` call (`name: $this->q !== '' ? $this->q : null`), `resetFilters()` (now resets `city`, `type`, `q`), and `filtersActive` (now also true when `$this->q !== ''`).
- [x] Test: `test_q_search_filters_by_name_substring_case_insensitively` — real seeded cemetery name, uppercased substring, asserts match + asserts a non-overlapping cemetery is excluded.
- [x] Test: `test_reset_filters_clears_city_type_and_q` (renamed from `test_reset_filters_clears_both_filters`) — asserts all three reset.
- [x] Commit.

---

### Task 2: Render the header search bar and wire it to the real backend

**Files:**
- Create: `resources/views/components/icon/magnifying-glass.blade.php`
- Modify: `resources/views/components/mk/header.blade.php` (desktop bar only)
- Modify: `resources/views/livewire/public/directory/index.blade.php` (honest search-active acknowledgement)
- Test: `tests/Feature/Livewire/Public/HomePageRouteTest.php`

**Interfaces:**
- Consumes: Task 1's `q` param name and `cemeteries.index` route.
- Produces: nothing consumed by a later task in this plan (last task).

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah HTTP-level: `$this->get('/')` terhadap `Tests\Feature\Livewire\Public\HomePageRouteTest`, per spec induk's Testing Decisions (header/footer shared-layout content diuji lewat halaman ini). Cakup: markup form yang benar (input type, name, action, method), label akses (bukan hanya placeholder), dan bahwa bar mobile TIDAK berubah.

- [x] `icon/magnifying-glass.blade.php` created, real Heroicons v2.2.0 path fetched directly from upstream.
- [x] Search form added to `header.blade.php`'s desktop bar, between logo and nav — `h-10`, `rounded-full`, icon inside via absolute positioning, real `<label for="header-search" class="sr-only">`, submits GET to `cemeteries.index` with `name="q"`.
- [x] Mobile bar (`lg:hidden`) confirmed unchanged.
- [x] Honest search-active acknowledgement added to `directory/index.blade.php` when `$q !== ''` (text + a "Hapus pencarian" clear control reusing `resetFilters()`).
- [x] Test: `test_header_search_bar_renders_on_desktop_and_submits_to_the_cemetery_directory` added to `HomePageRouteTest.php`.
- [x] `bash ci/verify-docs.sh` run, all 19 gates pass.
- [x] Both changed/new Blade files compiled and syntax-checked via a Docker Blade-compile probe (this project's own established practice after a real prior `ParseError` incident) — no errors.
- [x] Commit.

---

**Self-review:**

- **Spec coverage:** every ticket acceptance-criterion checkbox is addressed by Task 1 or Task 2 above, including the real, named gap (no existing search capability) resolved with a real, non-fabricated extension rather than left silent.
- **Placeholder scan:** none — both tasks' steps are already-completed, concrete edits (this plan documents work done in a single implementation pass, not a handoff to a fresh implementer — this session's Agent/fork tooling was unavailable for further subagent dispatch).
- **Type consistency:** `$q`/`name`/`matchingName` naming is consistent across the Livewire property, the query parameter, and the model scope.
