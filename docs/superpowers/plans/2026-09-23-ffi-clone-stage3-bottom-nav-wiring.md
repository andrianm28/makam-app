# FFI Clone Stage 3 — Site-Wide Bottom Navigation Wiring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the already-built, already-approved `<x-mk.bottom-nav>` component into the shared public layout so every public page shows it, with the correct tab marked active per page.

**Architecture:** One addition to `resources/views/layouts/app.blade.php` (the single shared layout every public Livewire page already renders through), extending the exact pattern `<x-mk.header>`'s own `active` prop already establishes there. Each of the 8 public page controllers passes a new `bottomNavActive` value through its existing `->layout('layouts.app', [...])` call, the same mechanism it already uses for `active`.

**Tech Stack:** Laravel 13, Livewire, Blade components, Tailwind v4 (`@theme`/`@utility` tokens in `resources/css/tokens.css`/`app.css`).

**Spec:** `.scratch/ffi-clone-stage3-homepage/spec.md` (parent spec) + `.scratch/ffi-clone-stage3-homepage/issues/01-site-wide-bottom-navigation.md` (this ticket)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah di bawah keluar dengan status 0. Controller yang membaca header ini: kalau salah satu belum dijalankan, jalankan dulu; kalau ada yang gagal, perbaiki rencananya, jangan melewati gerbangnya.

    ~/.claude/skills/specflow/scripts/check-plan-headings.sh    docs/superpowers/plans/2026-09-23-ffi-clone-stage3-bottom-nav-wiring.md <task-brief>
    ~/.claude/skills/specflow/scripts/check-seam-constraints.sh docs/superpowers/plans/2026-09-23-ffi-clone-stage3-bottom-nav-wiring.md <task-brief>

## Global Constraints

- `<x-mk.bottom-nav>` renders in the shared public layout alongside (not replacing) `<x-mk.header>` — no edit to `<x-mk.header>` itself, the existing hamburger menu's markup and behaviour are unchanged.
- Every public page passes its own `bottomNavActive` value through the same `->layout('layouts.app', [...])` mechanism `<x-mk.header>`'s `active` prop already uses. The two components' active-tab vocabularies are disjoint: the header's four keys are `pemesanan`/`layanan`/`perpanjangan`/`faq`; the bottom nav's five keys are `beranda`/`pemesanan`/`perpanjangan`/`akun`/`bantuan`. A page maps onto whichever vocabulary actually names it and passes `null` for the other — matching this codebase's own existing convention of writing `'active' => null` explicitly with a comment, not omitting the key.
- The bar must be `lg:hidden` on every wired page (confirmed per page, not assumed from the component's own existing test).
- No page's real content may be visually overlapped by the now-fixed bottom bar on mobile.
- Any sticky element that coexists with the bar on a wired page must respect `z-sticky-cta` < `z-bottomnav`. Verified during planning: the only real `z-sticky-cta` consumer in this codebase (`stepper.blade.php`) is a **top**-sticky progress header (`sticky top-[var(--mk-header-h)]`), not a bottom-fixed CTA, and no page in scope has any `fixed`/`sticky` bottom element today. There is currently nothing that can collide — this constraint has no real case to satisfy yet, note that plainly rather than inventing one.
- Out of scope (per parent spec): the homepage restructure itself (tickets 03/04), the skeleton retrofit (ticket 02), the 7-page visual-consistency audit (ticket 05), any `tokens.css` value change, Filament panels, step structure/copy of the wizard/renewal/marketplace.

---

### Task 1: Wire the component into the shared layout, prove it end-to-end on the homepage

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `app/Livewire/Public/HomePage.php`
- Modify: `tests/Feature/Livewire/Public/HomePageRouteTest.php`

**Interfaces:**
- Consumes: `<x-mk.bottom-nav :active="...">` (Stage 2, already built — accepts `active` as one of `'beranda'|'pemesanan'|'perpanjangan'|'akun'|'bantuan'|null`, renders `<nav aria-label="Navigasi utama">` with `lg:hidden fixed inset-x-0 bottom-0` positioning). `--mk-bottomnav-total` (already defined in `tokens.css`, `calc(var(--mk-bottomnav-h) + var(--mk-safe-bottom))`).
- Produces: the layout's own new `$bottomNavActive` blade variable (mirrors the existing `$active` variable exactly — both default to `null` via `??`, both are set per-page through the second array argument of `->layout('layouts.app', [...])`). Every later task in this plan (Task 2) relies on this variable name and default existing exactly as built here.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `tests/Feature/Livewire/Public/HomePageRouteTest.php` melalui `$this->get('/')`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu: bar hadir, tab Beranda aktif (shape + colour + `aria-current="page"`), bar `lg:hidden`, tidak menimpa konten nyata, header/hamburger tidak berubah. Helper internal (kelas Tailwind, variabel Blade `$bottomNavActive`) diuji secara tidak langsung lewat HTML yang dirender, tidak pernah langsung. Nilai harapan dalam test harus literal yang diketahui (string kelas nyata, bukan dihitung ulang).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Livewire/Public/HomePageRouteTest.php` (new test method, following the file's own `$response->assertSee('...', false)` convention already used throughout):

```php
public function test_bottom_nav_renders_with_beranda_active_and_is_hidden_above_lg(): void
{
    $response = $this->get('/');

    $response->assertSee('aria-label="Navigasi utama"', false);
    $response->assertSee('lg:hidden', false);

    // Beranda's own anchor carries aria-current + the active classes;
    // isolate it the same way MkBottomNavTest's own active-tab tests do,
    // so this doesn't just prove SOME tab is active, but that Beranda
    // specifically is.
    $html = $response->getContent();
    $berandaStart = strpos($html, 'href="/"><svg') !== false
        ? strpos($html, 'href="/"')
        : strpos($html, 'href="/"', strpos($html, 'Navigasi utama'));
    $this->assertNotFalse($berandaStart, 'Beranda tab anchor not found');
    $berandaEnd = strpos($html, '</a>', $berandaStart);
    $berandaAnchor = substr($html, $berandaStart, $berandaEnd - $berandaStart);

    $this->assertStringContainsString('aria-current="page"', $berandaAnchor);
    $this->assertStringContainsString('text-primary-700', $berandaAnchor);
    $this->assertStringContainsString('border-primary-600', $berandaAnchor);

    // The header's own hamburger/active state is untouched by this change.
    $response->assertSee('Menu utama (seluler)', false);
}

public function test_bottom_nav_does_not_visually_overlap_the_footer(): void
{
    $response = $this->get('/');

    // Footer carries the mobile-only bottom-nav clearance margin; lg:mb-0
    // cancels it where the bar is hidden.
    $response->assertSee('mb-[var(--mk-bottomnav-total)] lg:mb-0', false);
}
```

- [ ] **Step 2: Run tests to verify they fail**

This host cannot run PHPUnit directly (PHP 8.3 host, app needs 8.5 — `docs/agents/issue-tracker.md`). Confirm the failure by reading the current, unmodified `layouts/app.blade.php` and `home-page.blade.php` output: neither `aria-label="Navigasi utama"` nor `mb-[var(--mk-bottomnav-total)]` exists anywhere in the rendered output yet, so both new assertions would fail against the current code. Record this reasoning in the task report; push and confirm the real failure via CI is not required for a RED step (only for the final GREEN one), but do not claim GREEN without the real CI run named below.

- [ ] **Step 3: Wire the component into the layout**

In `resources/views/layouts/app.blade.php`, change:

```blade
    <x-mk.header
        :active="$active ?? null"
        :authenticated="$akunAuthenticated"
        :akunHref="$akunAuthenticated ? route('akun.index') : route('login')"
    />

    <main id="main">
        {{ $slot }}
    </main>
```

to:

```blade
    <x-mk.header
        :active="$active ?? null"
        :authenticated="$akunAuthenticated"
        :akunHref="$akunAuthenticated ? route('akun.index') : route('login')"
    />

    <main id="main">
        {{ $slot }}
    </main>

    <x-mk.bottom-nav :active="$bottomNavActive ?? null" />
```

And change the footer's opening tag from:

```blade
    <footer class="bg-primary-900 px-4 py-section text-neutral-0 md:px-6 lg:px-8 lg:py-section-lg">
```

to:

```blade
    <footer class="bg-primary-900 px-4 py-section text-neutral-0 md:px-6 lg:px-8 lg:py-section-lg mb-[var(--mk-bottomnav-total)] lg:mb-0">
```

Use `margin-bottom`, not `padding-bottom`, deliberately: the footer already carries `py-section` (padding on both top and bottom); adding a second bottom-padding utility for the same property risks an unpredictable cascade win between two same-specificity Tailwind utilities. A margin is a different property — it cannot collide with the footer's own padding, and since the footer is the last element in `<body>`'s normal flow, pushing it down by `--mk-bottomnav-total` on mobile (cancelled on `lg+`, where the bar is hidden) is exactly enough clearance for the fixed bar to never cover any real content, on any page, without needing a matching change on `<main>` or on any individual page view.

Also update this file's own file-header doc comment: it should now note that `$bottomNavActive` follows the identical `?? null` default pattern the comment already documents for `$active`, and that every public page must pass its own value the same way — the existing paragraph explaining `$active`'s convention is the model to extend, not replace.

- [ ] **Step 4: Wire the homepage as the first real consumer**

In `app/Livewire/Public/HomePage.php`, change the `render()` method's `->layout('layouts.app', [...])` call from:

```php
        ])->layout('layouts.app', [
            // No unsubstantiated superlative ("terpercaya"/"terbaik") in the
            // title — same honesty discipline this codebase already applies
            // to Urgent/hotline/cemetery-fixture copy, extended to page
            // metadata that is easy to overlook as "just marketing copy".
            'title' => 'Makam.co.id - Pemesanan dan Layanan Pemakaman',
            'active' => null,
        ]);
```

to:

```php
        ])->layout('layouts.app', [
            // No unsubstantiated superlative ("terpercaya"/"terbaik") in the
            // title — same honesty discipline this codebase already applies
            // to Urgent/hotline/cemetery-fixture copy, extended to page
            // metadata that is easy to overlook as "just marketing copy".
            'title' => 'Makam.co.id - Pemesanan dan Layanan Pemakaman',
            'active' => null,
            // <x-mk.bottom-nav>'s own five keys are 'beranda' | 'pemesanan'
            // | 'perpanjangan' | 'akun' | 'bantuan' — a distinct vocabulary
            // from <x-mk.header>'s 'active' above. The homepage is Beranda.
            'bottomNavActive' => 'beranda',
        ]);
```

- [ ] **Step 5: Run tests to verify they pass**

Push to a throwaway branch or run within this task's own branch and watch real CI (`gh run watch`) for the `PHP (validate, lint, analyse, test)` job — this host cannot run PHPUnit locally. Do not report GREEN without reading the real job conclusion and, for the two new test methods specifically, confirming them in the raw log (not just the job's overall pass/fail).

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php app/Livewire/Public/HomePage.php tests/Feature/Livewire/Public/HomePageRouteTest.php
git commit -m "feat(design): wire <x-mk.bottom-nav> into the shared public layout, homepage first"
```

---

### Task 2: Wire the remaining 7 public pages

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php`
- Modify: `app/Livewire/Public/Marketplace/MarketplaceIndex.php`
- Modify: `app/Livewire/Public/Renewal/RenewalStart.php`
- Modify: `app/Livewire/Public/Faq/FaqIndex.php`
- Modify: `app/Livewire/Public/Akun/AkunIndex.php`
- Modify: `app/Livewire/Public/Directory/CemeteryDirectoryIndex.php`
- Modify: `app/Livewire/Public/Support/HelpCentre.php`
- Modify: `tests/Feature/Livewire/Public/Booking/BookingWizardRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/Marketplace/MarketplaceIndexRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php`
- Modify: `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/Akun/AkunIndexRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/Support/HelpCentreRouteTest.php`

**Interfaces:**
- Consumes: Task 1's `$bottomNavActive ?? null` layout variable — already wired, this task only adds the array key to 7 more `->layout()` calls, no further layout-file change.
- Produces: nothing consumed by a later task in this plan (last task).

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah masing-masing file route-test HTTP-level milik ketujuh halaman ini sendiri (`$this->get('<route>')`), satu seam per halaman, persis seperti yang sudah ada. Cakup SETIAP halaman MELALUI seam-nya sendiri: kehadiran bar, tab yang benar aktif (atau tidak ada tab aktif bila memang `null`), `aria-current` tepat satu kali saat ada tab aktif. Jangan menguji ulang internal komponen `<x-mk.bottom-nav>` sendiri (`MkBottomNavTest` Stage 2 sudah mencakupnya) — hanya kehadiran dan kebenaran tab aktif per halaman.

The real, verified-during-planning mapping every page in this task must use (do not re-derive — these are the actual current `active` values already in each controller today, confirmed by reading each file; only the new `bottomNavActive` column is added by this task):

| Page | Route | `active` (header, unchanged) | `bottomNavActive` (new) |
|---|---|---|---|
| BookingWizard | `/pemesanan-makam` | `null` | `'pemesanan'` |
| MarketplaceIndex | `/marketplace` | `'layanan'` | `null` |
| RenewalStart | `/perpanjangan` | `'perpanjangan'` | `'perpanjangan'` |
| FaqIndex | `/faq` | `'faq'` | `null` |
| AkunIndex | `/akun` | `null` | `'akun'` |
| CemeteryDirectoryIndex | `/pemakaman` | `null` | `null` |
| HelpCentre | `/bantuan` | `null` | `'bantuan'` |

Note `RenewalStart` is the one page where BOTH vocabularies happen to share the same key (`perpanjangan` is both one of the header's four items and one of the bottom nav's five tabs) — pass the same string to both, this is correct, not a mistake to "fix" into different values.

- [ ] **Step 1: Write the failing tests**

Add one test method per page to its own route test file, following that file's own existing assertion style (`assertSee`, or the anchor-isolation pattern from Task 1 if the file already isolates anchors elsewhere — match each file's own established convention, don't impose a new one). Example for `BookingWizardRouteTest.php`:

```php
public function test_bottom_nav_renders_with_pemesanan_active(): void
{
    $response = $this->get('/pemesanan-makam');

    $response->assertSee('aria-label="Navigasi utama"', false);

    $html = $response->getContent();
    $start = strpos($html, 'href="/pemesanan-makam"', strpos($html, 'Navigasi utama'));
    $this->assertNotFalse($start, 'Pemesanan tab anchor not found in bottom nav');
    $end = strpos($html, '</a>', $start);
    $anchor = substr($html, $start, $end - $start);

    $this->assertStringContainsString('aria-current="page"', $anchor);
}
```

Write the equivalent for each of the other 6 pages using the mapping table above — for a page whose `bottomNavActive` is `null` (MarketplaceIndex, FaqIndex, CemeteryDirectoryIndex), assert the bottom nav renders (`aria-label="Navigasi utama"`) but that `aria-current="page"` appears exactly zero times inside any bottom-nav tab anchor, not that the whole response lacks the string entirely (the header's own active item on that page may legitimately carry no `aria-current` conflict, but be precise — scope the assertion to the bottom nav's own markup the same way Task 1's test isolates a single anchor).

- [ ] **Step 2: Run tests to verify they fail**

Same host constraint as Task 1 — confirm by reading the current (Task-1-complete, Task-2-not-yet-applied) state: none of these 7 controllers pass `bottomNavActive` yet, so each new active-tab assertion fails against the current code (the bar itself already renders from Task 1, with no tab active anywhere, since none of these pages set the value yet).

- [ ] **Step 3: Add `bottomNavActive` to each controller's layout call**

For each of the 7 files, add one line to the existing `->layout('layouts.app', [...])` array, using the mapping table above. Example, `BookingWizard.php`:

```php
        ])->layout('layouts.app', [
            'title' => 'Pemesanan Makam - Makam.co.id',
            'active' => null,
            'bottomNavActive' => 'pemesanan',
        ]);
```

`MarketplaceIndex.php` (explicit `null`, matching this codebase's existing convention of writing null out with a one-line reason rather than omitting the key):

```php
            // <x-mk.header>'s nav key for /marketplace (see that component's
            // own $items map) — not 'pemesanan'.
            'active' => 'layanan',
            // Not one of <x-mk.bottom-nav>'s five tabs — Layanan Pemakaman
            // is reachable only via the header's hamburger on mobile
            // (ADR-0044 Amendment 1).
            'bottomNavActive' => null,
```

`RenewalStart.php`:

```php
            'active' => 'perpanjangan',
            'bottomNavActive' => 'perpanjangan',
```

`FaqIndex.php`:

```php
            'active' => 'faq',
            // Not one of <x-mk.bottom-nav>'s five tabs — same reasoning as
            // MarketplaceIndex's own 'layanan' page.
            'bottomNavActive' => null,
```

`AkunIndex.php`:

```php
            'active' => null,
            'bottomNavActive' => 'akun',
```

`CemeteryDirectoryIndex.php`:

```php
            'active' => null,
            // Not one of <x-mk.header>'s four keys NOR <x-mk.bottom-nav>'s
            // five tabs — the cemetery directory has no active-state
            // treatment in either navigation surface, same honest-null
            // reasoning this file's own 'active' comment already gives.
            'bottomNavActive' => null,
```

`HelpCentre.php`:

```php
            'active' => null,
            'bottomNavActive' => 'bantuan',
```

- [ ] **Step 4: Run tests to verify they pass**

Push and watch real CI (`gh run watch`) for the `PHP (validate, lint, analyse, test)` job. Confirm all 7 new test methods individually in the raw log, not just the job's overall conclusion.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Public/Booking/BookingWizard.php \
        app/Livewire/Public/Marketplace/MarketplaceIndex.php \
        app/Livewire/Public/Renewal/RenewalStart.php \
        app/Livewire/Public/Faq/FaqIndex.php \
        app/Livewire/Public/Akun/AkunIndex.php \
        app/Livewire/Public/Directory/CemeteryDirectoryIndex.php \
        app/Livewire/Public/Support/HelpCentre.php \
        tests/Feature/Livewire/Public/Booking/BookingWizardRouteTest.php \
        tests/Feature/Livewire/Public/Marketplace/MarketplaceIndexRouteTest.php \
        tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php \
        tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php \
        tests/Feature/Livewire/Public/Akun/AkunIndexRouteTest.php \
        tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php \
        tests/Feature/Livewire/Public/Support/HelpCentreRouteTest.php
git commit -m "feat(design): wire <x-mk.bottom-nav> active state into the remaining 7 public pages"
```
