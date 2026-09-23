# FFI Clone Stage 3 Ticket 03 — Homepage secondary CTAs + new TPU/TPS sections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the three PRD-required secondary-CTA links and two new real-data homepage sections (urgent-availability TPU/TPS, newest-published TPU/TPS) without yet removing the old sections they replace.

**Architecture:** Two new try/catch-guarded queries added to `App\Livewire\Public\HomePage::render()`, following the exact pattern its existing `featuredCemeteries` query already uses. Two new `@unless(...Unavailable || ...->isEmpty())`-guarded sections added to `home-page.blade.php`, reusing the existing featured-cemeteries card partial verbatim (real card treatment, not new markup) and inserted between the services section and the untouched "how it works" section. Three secondary-CTA links added inside the services section, mirroring the existing "Lihat semua TPU & TPS" link's exact pattern.

**Tech Stack:** Laravel 13, Livewire, Blade, PostgreSQL (production)/SQLite is never used per this repo's own testing discipline — real CI runs against the real driver.

**Spec:** `.scratch/ffi-clone-stage3-homepage/issues/03-homepage-secondary-ctas-and-new-tpu-tps-sections.md` (ticket), parent spec `.scratch/ffi-clone-stage3-homepage/spec.md`

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah di bawah keluar dengan status 0.

    specflow/scripts/check-plan-headings.sh    <rencana ini> <task-brief>
    specflow/scripts/check-seam-constraints.sh <rencana ini> <task-brief>

## Global Constraints

- No new route to a "Wakaf Tanah" destination exists in this codebase — confirmed by a repo-wide search before implementation began. Rather than invent one, the CTA renders as an honest disabled control (`aria-disabled="true"`, no href), matching `header.blade.php`'s own precedent for an account area that isn't built yet.
- "Urgent-availability" has no existing per-cemetery flag in this domain. The honest, non-fabricated signal used is `CemeteryPackageAvailabilityStatus::LIMITED` on at least one of a cemetery's real `packages()` rows — a real, already-tracked business fact, not a new rule invented for this ticket.
- "Newest published" orders by the real `published_at` column, descending, with an `id` tie-breaker (the seeded example-data fixture sets `published_at` to the same instant for every row, which would otherwise leave ordering among ties to the database's unspecified tie behaviour).
- Both new sections reuse the existing featured-cemeteries card partial (badge/photo/price block) verbatim as their visual treatment — that partial is this codebase's own already-FFI-restyled (Stage 1 palette rebase + Stage 2 component library) card pattern, so reuse here is fidelity to the approved visual system, not a shortcut.
- The old "how it works", "featured cemeteries", and "trust safety" sections are explicitly NOT touched by this ticket — verified, not assumed. Their removal is ticket 04's job, blocked on this ticket landing first.
- Every one of the ten mandatory states this page's existing sections already honour (Empty, Provider-unavailable, Gated-fallback-banner) is preserved on both new sections via the same `@unless(...Unavailable || ...->isEmpty())` + try/catch pattern already established.

---

### Task 1: Add urgent-availability and newest-published queries to HomePage::render()

**Files:**
- Modify: `app/Livewire/Public/HomePage.php`

**Interfaces:**
- Consumes: `App\Domain\CemeteryDirectory\Models\Cemetery::published()` scope (existing), `App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus::LIMITED` (existing constant), `Cemetery::packages()` relation (existing).
- Produces: `$urgentAvailabilityCemeteries`/`$urgentAvailabilityCemeteriesUnavailable` and `$newestPublishedCemeteries`/`$newestPublishedCemeteriesUnavailable`, passed to the view — consumed by Task 2.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah HTTP-level: `$this->get('/')` against `Tests\Feature\Livewire\Public\HomePageRouteTest`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu: both sections' presence when qualifying data exists, absence when no cemetery qualifies (urgent-availability) or on query failure (both), and that a published cemetery with no LIMITED package is excluded from the urgent-availability section specifically. Nilai harapan dalam test harus literal yang diketahui atau diturunkan langsung dari `CemeteryExampleData`'s real fixture arrays, bukan dihitung ulang dengan cara yang sama seperti kode di HomePage.php.

- [x] Import `App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus`.
- [x] Add the urgent-availability query: `Cemetery::published()->whereHas('packages', fn ($q) => $q->where('availability_status', CemeteryPackageAvailabilityStatus::LIMITED))->orderBy('city')->orderBy('name')->take(6)->get()`, wrapped in the same try/catch + `report($e)` pattern as `featuredCemeteries`.
- [x] Add the newest-published query: `Cemetery::published()->orderBy('published_at', 'desc')->orderBy('id')->take(6)->get()`, same try/catch pattern.
- [x] Pass both results (plus their `*Unavailable` booleans) to the view array.
- [x] Commit.

### Task 2: Render the two new sections and the three secondary CTAs

**Files:**
- Modify: `resources/views/livewire/public/home-page.blade.php`
- Modify: `tests/Feature/Livewire/Public/HomePageRouteTest.php`

**Interfaces:**
- Consumes: Task 1's four new view variables.
- Produces: nothing consumed by a later task in this plan (last task). Ticket 04 (separate plan) consumes the fact that these sections now exist at their stated `id`s when it builds the featured/verified section and removes the old ones.

**Seam constraint (MENGIKAT task ini, dari spec):** Same seam as Task 1 — `$this->get('/')` against `HomePageRouteTest`. Cakup: secondary-CTA hrefs/labels/disabled-state, both new sections' heading text and position (after `services-heading`, before `how-it-works-heading`), both sections' real-data content and empty/failure degradation, and that `how-it-works-heading`/`featured-cemeteries-heading`/`trust-heading` remain present and unmodified.

- [x] Add three secondary-CTA links inside the services `<section>`, below the existing "Lihat semua TPU & TPS" link: Perpanjang Makam (working link), Layanan Pemakaman (working link), Wakaf Tanah (honest disabled `<span>`, matching `header.blade.php`'s `$akunAvailable` precedent).
- [x] Add the urgent-availability section (`id="urgent-availability-heading"`) directly after the services section, using the existing featured-cemeteries card partial verbatim against the new query's data.
- [x] Add the newest-published section (`id="newest-published-heading"`) directly after the urgent-availability section, same card partial.
- [x] Add `HomePageRouteTest` coverage: secondary-CTA rendering, urgent-availability presence/empty-state/content-correctness, newest-published presence/content, section ordering (services → urgent → newest → how-it-works), and an explicit assertion that the three old sections are untouched.
- [x] Run `bash ci/verify-docs.sh` — all gates pass.
- [x] Commit.

---

**Self-review:**

- **Spec coverage:** every ticket acceptance-criterion checkbox is addressed by Task 1 or Task 2 above, including the two named gaps (Wakaf Tanah route, urgent-availability rule) each resolved with a real, non-fabricated interpretation rather than left silent.
- **Placeholder scan:** none — both tasks' steps are already-completed, concrete edits (this plan was written to document work done in a single implementation pass, not to hand off to a fresh implementer).
- **Type consistency:** view variable names match exactly between `HomePage.php` and `home-page.blade.php` (`urgentAvailabilityCemeteries`/`Unavailable`, `newestPublishedCemeteries`/`Unavailable`).
