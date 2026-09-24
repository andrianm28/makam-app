# FFI Clone — Memorial Pages Restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the site's existing FFI-aligned visual language (container gutters, heading type scale, via the already-adopted `mk.*` primitives) to the two memorial Livewire views — `MemorialPublicPage` (`/m/{token}`) and `MemorialFamilyPage` (`/kenangan/{profileId}`) — without touching their existing behavior, moderation rules, QR/visit-checkin mechanics, or the public page's existing grave/memorial-identifying content.

**Architecture:** Two Blade view edits only (`resources/views/livewire/public/memorial/public-page.blade.php`, `.../family-page.blade.php`). No PHP/domain changes, no new routes, no new components. Both views already use `<x-mk.card>`/`<x-mk.button>`/`<x-mk.field>`/`<x-mk.alert>`/`<x-mk.badge>` — the gap is (a) an incomplete container-gutter class (missing `md:px-6 lg:px-8`, confirmed present on already-restyled pages like `marketplace/index.blade.php` and `akun/akun-index.blade.php`, absent on not-yet-restyled `faq/index.blade.php`) and (b) heading-scale/hierarchy inconsistent with the established convention (`text-3xl font-semibold tracking-tight text-neutral-900` for the one real page-identity `<h1>`; `text-lg font-semibold text-neutral-900` for `<h2>` section headings).

**Tech Stack:** Laravel Blade, Livewire 4, Tailwind utilities backed by `tokens.css` `@theme` primitives.

**Spec:** `.scratch/ffi-clone-whole-frontend/spec.md` (ticket: `.scratch/ffi-clone-whole-frontend/issues/08-memorial-pages-restyle.md`)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah
di bawah keluar dengan status 0.

    <akar specflow>/scripts/check-plan-headings.sh    <rencana ini> <task-brief>
    <akar specflow>/scripts/check-seam-constraints.sh <rencana ini> <task-brief>

## Global Constraints

- Both `MemorialFamilyPage` and `MemorialPublicPage` must use the site's FFI-aligned visual language (cards, spacing, typography, buttons) via this project's own existing `mk.*` primitives — no new primitive is introduced, no existing primitive is forked.
- The public memorial page's existing grave/memorial-identifying content is NOT removed or replaced under the marketing-imagery rule — this page is a deliberate exception (functional identification need for a QR-scan visitor, not marketing), matching the cemetery-detail carve-out already recorded in the parent spec. (There is no `<img>`/photo currently rendered on this page — media refs render as plain accepted-attachment list items by design, per `MemorialPublicProjection`'s own doc block; nothing to preserve there beyond not introducing a photo that doesn't already exist.)
- The existing visit check-in behavior, QR generation/scanning, and moderation rules are completely unchanged — verified by a real diff review (no PHP/domain files touched by this plan at all).
- The family-managed view's existing edit/manage capabilities (display-name edit, content submission, media upload, QR rotation, privacy change, visit history) are completely unchanged — same copy, same form fields, same Livewire actions, only presentation classes and heading levels change.
- No new copy is invented. The one new heading text this plan adds (`Halaman Kenangan`, the family page's missing real page-identity `<h1>`) is a verbatim reuse (title-cased) of the phrase "halaman kenangan" that already appears twice in this same view's own existing copy — not a new phrase.
- `MemorialPublicPageTest` gains real assertions for the new visual markers where they change; all existing behavioral assertions continue to pass unchanged.
- `MemorialFamilyPage` gains a new dedicated route test (none exists today), matching this repository's own existing route-test convention for public Livewire pages (`AkunIndexRouteTest`'s pattern: real `$this->get(...)` HTTP round-trips, not only `Livewire::test()` component calls), covering the real visual change and that existing manage/edit behavior still works.
- `bash ci/verify-docs.sh` must pass.
- No backend/domain logic changes anywhere in this plan — visual/presentation-layer only.

---

### Task 1: Restyle `MemorialPublicPage` (`/m/{token}`)

**Files:**
- Modify: `resources/views/livewire/public/memorial/public-page.blade.php`
- Modify: `tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php`

**Interfaces:**
- Consumes: nothing new — `$visible`, `$projection` (`App\Domain\Memorial\MemorialPublicProjection`), `$checkedIn`, `$checkInNotice`, `$checkInError` are all already provided by `App\Livewire\Public\Memorial\MemorialPublicPage::render()`, unchanged by this task.
- Produces: nothing consumed by Task 2 (the two views are independent files).

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `MemorialPublicPageTest`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk bentuk input yang tidak biasa, kondisi batas, dan jalur kegagalan. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] **Step 1: Confirm the real current markup and the real "already-restyled" precedent**

Read `resources/views/livewire/public/memorial/public-page.blade.php` in full (already read during planning: container is `mx-auto max-w-content px-4`, the `<h1>` is `text-2xl font-semibold text-neutral-900`). Re-confirm the target pattern against `resources/views/livewire/public/marketplace/index.blade.php` line 75 (`mx-auto max-w-content px-4 md:px-6 lg:px-8`) and `resources/views/livewire/public/marketplace/product-detail.blade.php` line 118 (`<h1 class="text-3xl font-semibold tracking-tight text-neutral-900">`) — both already-shipped precedents, not invented values.

- [ ] **Step 2: Write the failing test assertions first**

In `tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php`, add (near the "PUBLIC PAGE — the allowlist projection" group):

```php
/**
 * The FFI-aligned visual language: the container carries the full
 * gutter scale (matching marketplace/akun's already-restyled pages,
 * not the older bare `px-4` FAQ still carries), and the page's one
 * real heading (the deceased's display name) uses the established
 * content-page-title scale, not the smaller pre-restyle size. A real
 * HTTP round-trip through the real route, per the parent spec's own
 * "what makes a good test here" guidance.
 */
public function test_the_public_page_uses_the_ffi_container_gutters_and_heading_scale(): void
{
    $this->openMemorialGate();
    $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
    app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
    $token = $this->tokenFor($profile);

    $response = $this->withoutVite()->get("/m/{$token->token}");
    $response->assertOk();

    $html = $response->getContent();
    $this->assertNotFalse($html);

    $this->assertStringContainsString('mx-auto max-w-content px-4 md:px-6 lg:px-8', $html);
    $this->assertStringContainsString(
        '<h1 class="text-3xl font-semibold tracking-tight text-neutral-900">',
        $html,
    );
    // The existing content is unchanged by the restyle.
    $this->assertStringContainsString('Almarhum Ahmad Uji', $html);
}
```

- [ ] **Step 3: Run the test to verify it fails against the current markup**

This host cannot run PHPUnit locally (PHP 8.3 vs the app's required 8.5) — verify the failure by reading the current markup directly instead: confirm the container div is currently `mx-auto max-w-content px-4` (no `md:px-6 lg:px-8`) and the `<h1>` is currently `text-2xl font-semibold text-neutral-900` (no `text-3xl`, no `tracking-tight`). Real CI (push + `gh run watch`) is the authoritative pass/fail signal once implemented.

- [ ] **Step 4: Apply the two class changes**

In `resources/views/livewire/public/memorial/public-page.blade.php`:
- Change `<div class="mx-auto max-w-content px-4">` to `<div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">`.
- Change `<h1 class="text-2xl font-semibold text-neutral-900">` to `<h1 class="text-3xl font-semibold tracking-tight text-neutral-900">`.

Do not touch anything else in this file: the uniform not-visible `<x-mk.card>` state, the empty-content notice, the approved-content loop, the accepted-media list, the "Catat kunjungan" form, and the footer disclaimer paragraph all stay byte-identical apart from inheriting the container's new gutter class.

- [ ] **Step 5: Run `bash ci/verify-docs.sh`**

Expected: `RESULT: ALL DOC GATES PASS`. Both changed classes are existing Tailwind utilities backed by already-registered tokens (no new hex value, no arbitrary Tailwind value), so GATE 2/GATE 3 are not implicated — this step confirms it rather than assumes it.

- [ ] **Step 6: Run the test to verify it passes**

Push and watch real CI (`gh run watch`) — the authoritative signal on this host. Confirm the new test method appears as a real `✓` PASS entry in the raw log, and that every pre-existing method in `MemorialPublicPageTest` still passes unmodified (the uniform-not-visible tests, the allowlist tests, the visit-checkin tests, the family-page tests already in this same file).

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/public/memorial/public-page.blade.php tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php
git commit -m "feat(design): restyle the public memorial page to the FFI container and heading scale"
```

---

### Task 2: Restyle `MemorialFamilyPage` (`/kenangan/{profileId}`) and add its missing route test

**Files:**
- Modify: `resources/views/livewire/public/memorial/family-page.blade.php`
- Create: `tests/Feature/Livewire/Public/Memorial/MemorialFamilyPageRouteTest.php`

**Interfaces:**
- Consumes: nothing new — `$visible`, `$privacyMode`, `$displayName`, `$notice`, `$contents`, `$pendingUploads`, `$media`, `$visitCheckIns`, `$visitCheckInCount`, `$qrSvg`, `$activeToken` are all already provided by `App\Livewire\Public\Memorial\MemorialFamilyPage`, unchanged by this task. Route: `Route::get('/kenangan/{profileId}', MemorialFamilyPage::class)->name('memorial.family')` (`routes/web.php`), guest-accessible at the route level, gated at the component level (uniform not-visible state for a non-editor).
- Produces: nothing consumed by Task 1.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah seam baru `tests/Feature/Livewire/Public/Memorial/MemorialFamilyPageRouteTest.php` (route-level, mengikuti konvensi `AkunIndexRouteTest`: `$this->get(...)` HTTP round-trip sungguhan, bukan hanya `Livewire::test()`). Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk bentuk input yang tidak biasa, kondisi batas, dan jalur kegagalan — minimal: guest akses (uniform not-visible via HTTP), editor akses (200 + marker visual baru), dan bahwa kapabilitas edit/manage yang sudah ada (nama tampilan, kirim kenangan, unggah foto, rotasi QR, ubah privasi) tetap terlihat pada respons HTTP yang sama. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] **Step 1: Confirm the real current markup**

Read `resources/views/livewire/public/memorial/family-page.blade.php` in full (already read during planning). Confirm: the container is `mx-auto max-w-content px-4`; the page's first heading is `<h1 class="text-xl font-semibold text-neutral-900">Nama yang ditampilkan</h1>` — a card's own section title incorrectly promoted to the page's only `<h1>` — while every real section below it (`Tulis kenangan`, `Foto kenangan`, `Riwayat kunjungan`, `Kode QR kunjungan`, `Privasi`) correctly uses `<h2 class="text-lg font-semibold text-neutral-900">`. Confirm the exact existing copy "Nama ini tampil pada halaman kenangan yang dibuka lewat kode QR." and "...membuka halaman kenangan sesuai pengaturan privasi." — both already contain the substring "halaman kenangan" verbatim, which is what Step 3 below reuses title-cased as the page's real `<h1>`.

- [ ] **Step 2: Write the failing test file first**

Create `tests/Feature/Livewire/Public/Memorial/MemorialFamilyPageRouteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Memorial;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\Actions\GrantMemorialEditor;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/kenangan/{profileId}` — `MemorialFamilyPage`'s dedicated route-level
 * test, matching this repository's own existing route-test convention
 * (`AkunIndexRouteTest`'s pattern: real `$this->get(...)` HTTP round-trips).
 * `MemorialPublicPageTest` already covers this component's own behavior
 * (content submission, media upload, QR rotation, privacy change) via
 * `Livewire::test()`; this file adds the missing real-route seam the FFI
 * restyle ticket (`.scratch/ffi-clone-whole-frontend/issues/
 * 08-memorial-pages-restyle.md`) requires, rather than shipping a visual
 * change with no route-level seam covering it.
 */
final class MemorialFamilyPageRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function profile(): MemorialProfile
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba Rute',
            'slug' => 'tpu-uji-coba-rute-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 2',
        ]);
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->getKey()]);
        $profile = app(CreateMemorialProfile::class)($grave, 'user:1', 'operator', MemorialPrivacyMode::DEFAULT);
        $profile->forceFill(['display_name' => 'Almarhumah Siti Uji'])->save();

        return $profile;
    }

    public function test_a_guest_gets_the_uniform_not_visible_state_via_the_real_route(): void
    {
        $profile = $this->profile();

        $response = $this->get("/kenangan/{$profile->getKey()}");

        $response->assertOk();
        $response->assertSee('Memorial tidak tersedia');
        $response->assertDontSee('Almarhumah Siti Uji');
    }

    public function test_an_editor_sees_the_ffi_visual_markers_and_existing_manage_capabilities(): void
    {
        $profile = $this->profile();
        $editor = User::factory()->create();
        app(GrantMemorialEditor::class)($profile, $editor->id, (string) Str::uuid(), 'admin:1', 'admin');

        $response = $this->actingAs($editor)->get("/kenangan/{$profile->getKey()}");

        $response->assertOk();
        $html = $response->getContent();
        $this->assertNotFalse($html);

        // FFI container gutters (matches marketplace/akun's already-restyled
        // pages, not the older bare `px-4`).
        $this->assertStringContainsString('mx-auto max-w-content px-4 md:px-6 lg:px-8', $html);

        // A real page-identity <h1>, reusing this page's own existing
        // "halaman kenangan" copy rather than inventing new text.
        $this->assertStringContainsString('<h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Halaman Kenangan</h1>', $html);

        // The former mis-promoted <h1> is now the section-level <h2> its
        // siblings already use.
        $this->assertStringContainsString('<h2 class="text-lg font-semibold text-neutral-900">Nama yang ditampilkan</h2>', $html);

        // Existing manage/edit capabilities are unchanged and still present:
        // display-name field, content form, media upload, QR section,
        // privacy selector.
        $response->assertSee('Simpan nama');
        $response->assertSee('Tulis kenangan');
        $response->assertSee('Kirim catatan');
        $response->assertSee('Unggah foto');
        $response->assertSee('Kode QR kunjungan');
        $response->assertSee('Privasi');
    }
}
```

- [ ] **Step 3: Run the test to verify it fails against the current markup**

This host cannot run PHPUnit locally (PHP 8.3 vs the app's required 8.5) — verify by reading the current markup directly: confirm the container div is currently `mx-auto max-w-content px-4`, confirm no `<h1>Halaman Kenangan</h1>` exists anywhere, and confirm the current `<h1>` is `<h1 class="text-xl font-semibold text-neutral-900">Nama yang ditampilkan</h1>` (not `<h2>`). Real CI (push + `gh run watch`) is the authoritative pass/fail signal once implemented.

- [ ] **Step 4: Apply the markup changes**

In `resources/views/livewire/public/memorial/family-page.blade.php`:
- Change `<div class="mx-auto max-w-content px-4">` to `<div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">`.
- Immediately before the `@if (! $visible)` / `@else` branch's privacy-badge `<div class="mb-6">`, inside the `@else` block, add:
  ```blade
  <h1 class="text-3xl font-semibold tracking-tight text-neutral-900 mb-6">Halaman Kenangan</h1>
  ```
  (Adjust the existing badge `<div class="mb-6">` spacing only if visually doubled — keep the badge's own `mb-6` as-is unless review finds redundant spacing; do not remove the badge's persistent-visibility requirement, see the file's own doc comment on why the privacy badge must always render here.)
- Change the profile-identity section's `<h1 class="text-xl font-semibold text-neutral-900">Nama yang ditampilkan</h1>` to `<h2 class="text-lg font-semibold text-neutral-900">Nama yang ditampilkan</h2>` — matching every sibling section heading's own existing class exactly.

Do not touch anything else: the not-visible `<x-mk.card>` state, the privacy badge itself, the content/media/visit-history/QR/privacy sections' own copy, forms, and Livewire bindings all stay byte-identical apart from the one heading-level change and inheriting the container's new gutter class.

- [ ] **Step 5: Run `bash ci/verify-docs.sh`**

Expected: `RESULT: ALL DOC GATES PASS`. All classes used are existing Tailwind utilities backed by already-registered tokens — no new hex value, no arbitrary Tailwind value.

- [ ] **Step 6: Run the test to verify it passes**

Push and watch real CI (`gh run watch`) — the authoritative signal on this host. Confirm both new test methods in `MemorialFamilyPageRouteTest` appear as real `✓` PASS entries, and that every pre-existing method in `MemorialPublicPageTest` (including its "FAMILY PAGE" group, which still exercises `MemorialFamilyPage` via `Livewire::test()`) still passes unmodified.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/public/memorial/family-page.blade.php tests/Feature/Livewire/Public/Memorial/MemorialFamilyPageRouteTest.php
git commit -m "feat(design): restyle the family memorial page to the FFI container and heading scale, add its missing route test"
```

---

Both tasks are independent (disjoint files, no shared interfaces) and can be dispatched to `superpowers:subagent-driven-development` together. After both tasks are done and its final whole-branch review is clean: run `/specflow:code-review` if available, then `superpowers:finishing-a-development-branch` — choose **option 2 (push and create a Pull Request)** with base branch `docs/design-system-and-planning`. Do not merge.
