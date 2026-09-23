# FFI Clone Stage 2 — `<x-mk.bottom-nav>` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `<x-mk.bottom-nav>`, the five-tab mobile persistent navigation primitive [ADR-0044](../adr/0044-approve-ffi-five-tab-bottom-navigation.md) approved, and bring `design-system.md`/`information-architecture.md` into sync with that approval — both were left with a stale "proposed, not approved" / no-bottom-nav status that ADR-0044 explicitly deferred to this plan to resolve.

**Architecture:** One new Blade component following the established `<x-mk.*>` convention, plus two new `icon.*` primitives it needs that don't exist yet (`home`, `user` — real, verified Heroicons v2.2.0 outline glyphs, fetched directly from the upstream package, matching `icon/clock-x.blade.php`'s own documented provenance discipline: never invented path data). The component itself is standalone and unwired — no real screen renders it yet, matching the ticket's own scope (wiring is Stage 3). `design-system.md` §3.11 and `information-architecture.md` §2 are updated to describe the now-approved end state (5-tab bottom nav on mobile, existing hamburger kept alongside it for the two desktop nav items — Layanan Pemakaman, FAQ — the 5 tabs don't cover), using this document's own established additive-supersession convention, not a silent rewrite.

**Tech Stack:** Laravel Blade components, Tailwind CSS 4, PHPUnit (`Illuminate\Support\Facades\Blade::render()` seam).

**Spec:** `.scratch/ffi-clone-stage2-components/issues/02-mk-bottom-nav.md` (ticket; seam and testing rationale drawn from the parent spec, `.scratch/ffi-clone-stage2-components/spec.md`); product-scope authority: [ADR-0044](../adr/0044-approve-ffi-five-tab-bottom-navigation.md)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah
di bawah keluar dengan status 0. Controller yang membaca header ini: kalau
salah satu belum dijalankan, jalankan dulu; kalau ada yang gagal, perbaiki
rencananya, jangan melewati gerbangnya.

    /home/ubuntu/.claude/skills/specflow/scripts/check-plan-headings.sh    docs/superpowers/plans/2026-09-23-ffi-clone-bottom-nav.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief
    /home/ubuntu/.claude/skills/specflow/scripts/check-seam-constraints.sh docs/superpowers/plans/2026-09-23-ffi-clone-bottom-nav.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief

## Global Constraints

- `<x-mk.bottom-nav>` renders exactly five tabs, in order, fixed set (not slot-configurable): Beranda (`/`), Pemesanan (`/pemesanan-makam`), Perpanjangan (`/perpanjangan`), Akun (`/akun`), Bantuan (`/bantuan`).
- Visible only below the `lg` breakpoint (`--breakpoint-lg: 64rem`) — hidden at `lg` and above.
- **Active tab is an explicit `active` prop, not request-path auto-detection.** The ticket's own wording said "matched against the current request path," but `header.blade.php` — the one directly comparable sibling nav component this codebase already has — does not auto-detect from the request at all: it takes an explicit `active` prop the caller supplies (`@props(['active' => null, // 'pemesanan' | 'layanan' | 'perpanjangan' | 'faq' | null])`). Matching that established, already-shipped convention over the ticket's own auto-detection wording is a deliberate deviation, decided during this plan's own preparation: `<x-mk.bottom-nav>` takes `'active' => null, // 'beranda' | 'pemesanan' | 'perpanjangan' | 'akun' | 'bantuan' | null` with the identical pattern. Since this component is unwired in this ticket, no real page passes a value yet — Stage 3's wiring work is responsible for passing the correct `active` value from each page, exactly as pages must already do for `<x-mk.header>` today.
- Active tab marked by colour **and** shape — never colour alone. Concretely: `text-primary-700` (colour) plus `border-t-2 border-primary-600` (shape — a top indicator bar, mirroring `header.blade.php`'s own `border-b-2` active-marking pattern, flipped to the top edge since a bottom-tab-bar's indicator conventionally sits above the tab content, not below it). Inactive tabs carry `border-t-2 border-transparent` — same technique `header.blade.php` uses to hold the border's layout space so switching tabs never shifts anything.
- Active-tab transition is colour/opacity only, through `--mk-duration-fast` (120ms) — no animation library.
- Active tab's anchor carries `aria-current="page"`.
- Whole component wrapped in `<nav aria-label="Navigasi utama">`.
- Uses the existing `--mk-z-bottomnav: 1100` token (already defined, confirmed unconsumed anywhere in the codebase before this plan). A short comment documents that it sits above `--mk-z-sticky-cta` (900) — whose own comment says "wizard sticky footer" even though its one real consumer today (`stepper.blade.php`) uses it for a top-sticky progress header, not a bottom CTA bar — so a future bottom-CTA-bar ticket knows the intended stacking order without re-deriving it.
- Uses the existing `--mk-bottomnav-h` (3.5rem/56px), `--mk-safe-bottom` (`env(safe-area-inset-bottom, 0px)`), and `--mk-bottomnav-total` (`calc(var(--mk-bottomnav-h) + var(--mk-safe-bottom))`) tokens — all already defined, none invented by this plan.
- No literal hex/px/arbitrary-Tailwind-value anywhere in the new component file.
- **Icon provenance is real or nothing** — matching `icon/clock-x.blade.php`'s own documented discipline. `home` and `user` icons (needed for Beranda and Akun tabs; `document-text`, `clock-x`, and `question-mark-circle` already exist and cover the other three) are the real, unmodified Heroicons v2.2.0 outline `HomeIcon`/`UserIcon` path data, fetched directly from `github.com/tailwindlabs/heroicons` at tag `v2.2.0` — not hand-drawn, not approximated.
- **[ADR-0044](../adr/0044-approve-ffi-five-tab-bottom-navigation.md) resolves OQ-04: approved**, with a real, decided answer (made during this plan's own preparation, not left open) to the one question ADR-0044 itself left unresolved — whether mobile keeps its hamburger menu alongside the new bottom nav. **Decided: yes, keep it.** The 5 bottom-nav tabs don't cover two real desktop nav items (`Layanan Pemakaman`, `FAQ` — `information-architecture.md` §2's desktop row has 7 items total), so mobile still needs a path to them; the existing hamburger (`resources/views/components/mk/header.blade.php`, already implemented, already covers all 7 items) keeps that job. This is a documentation update, not a `header.blade.php` code change — wiring bottom-nav next to the real header is Stage 3's job, out of scope here.
- `design-system.md` §3.11's "⚠️ PROPOSED, NOT APPROVED" banner and its 4-item draft are superseded by ADR-0044's approved 5-item form — update via this document's own established additive-supersession convention (append, don't silently rewrite — see ADR-0041/ADR-0043's own precedent there).
- `design-system.md` §11's OQ-04 row updates from "IA-compliant header only; bottom nav not shipped" to "Resolved, approved (ADR-0044)."
- `information-architecture.md` §2's mobile-nav bullet list updates to describe the approved end state: bottom nav for the 5 canonical tabs, hamburger kept for the remaining desktop items — not a silent removal of the hamburger bullet.
- Out of scope, do not touch: `<x-mk.skeleton>` (separate ticket, already shipped), `<x-mk.icon-medallion>`'s `tone` prop (separate ticket, already shipped), any other `<x-mk.*>` primitive, any `tokens.css` value, `header.blade.php` or any other real screen/page's actual code — this plan only builds the standalone component and updates the two named docs.

## File Structure

- **Create:** `resources/views/components/icon/home.blade.php` — real Heroicons v2.2.0 outline glyph, needed for the Beranda tab.
- **Create:** `resources/views/components/icon/user.blade.php` — real Heroicons v2.2.0 outline glyph, needed for the Akun tab.
- **Create:** `resources/views/components/mk/bottom-nav.blade.php` — the component itself.
- **Create:** `tests/Feature/View/Components/MkBottomNavTest.php` — its test file.
- **Modify:** `docs/design/design-system.md` — §3.11 (supersession note) and §11 (OQ-04 row).
- **Modify:** `docs/product/information-architecture.md` — §2 (mobile-nav bullet list).

---

### Task 1: Add the two missing icons and build `<x-mk.bottom-nav>` with its test suite

**Files:**
- Create: `resources/views/components/icon/home.blade.php`
- Create: `resources/views/components/icon/user.blade.php`
- Create: `resources/views/components/mk/bottom-nav.blade.php`
- Test: `tests/Feature/View/Components/MkBottomNavTest.php`

**Interfaces:**
- Consumes: nothing from another task in this plan.
- Produces: `<x-mk.bottom-nav>` — a self-contained, unwired component. Nothing in this plan is consumed by a later task, but Task 2 (documentation) describes this component's real, final shape, so Task 2 should be dispatched after this task's diff is available to reference.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk
task ini adalah `Illuminate\Support\Facades\Blade::render('<x-mk.bottom-
nav :active="..." />')`, mengikuti pola yang sudah ada di
`MkCardTest.php`/`MkIconMedallionTest.php`/`MkSkeletonTest.php` —
merender komponen langsung dan mengassert pada string HTML yang
dihasilkan, dengan `active` sebagai prop eksplisit (lihat Global
Constraints — bukan deteksi dari request path). Cakup SETIAP perilaku
task ini MELALUI seam itu: kelima tab hadir dengan href yang benar dan
urutan yang benar, kelas `lg:hidden` hadir, tab aktif untuk PALING SEDIKIT
dua nilai `active` berbeda (bukan hanya satu) benar-benar berbeda penanda
warna DAN bentuknya dari tab tidak aktif, `aria-current="page"` hadir
tepat pada satu tab yang benar dan tidak ada di keempat lainnya,
`active=null` (default) tidak menghasilkan `aria-current="page"` sama
sekali, `<nav aria-label="Navigasi utama">` membungkus semuanya. Nilai
harapan dalam test adalah literal kelas Tailwind/token yang sudah
diketahui dari rencana ini, bukan dihitung ulang.

- [ ] **Step 1: Create the two missing icon components**

`resources/views/components/icon/home.blade.php`:

```blade
{{--
    resources/views/components/icon/home.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    for <x-mk.bottom-nav>'s Beranda tab (FFI full-visual-clone design
    doc §3, Stage 2 ticket 02).

    Provenance: real, unmodified Heroicons v2.2.0 outline "HomeIcon"
    (24/outline/home.svg, fetched directly from
    github.com/tailwindlabs/heroicons at tag v2.2.0, MIT-licensed, 24x24
    viewBox, stroke-width 1.5) -- not a custom drawing, matching the
    provenance discipline icon/clock-x.blade.php's own file-header
    documents: a real glyph or nothing, never invented path data.

    No default classes -- every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
</svg>
```

`resources/views/components/icon/user.blade.php`:

```blade
{{--
    resources/views/components/icon/user.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    for <x-mk.bottom-nav>'s Akun tab (FFI full-visual-clone design doc
    §3, Stage 2 ticket 02).

    Provenance: real, unmodified Heroicons v2.2.0 outline "UserIcon"
    (24/outline/user.svg, fetched directly from
    github.com/tailwindlabs/heroicons at tag v2.2.0, MIT-licensed, 24x24
    viewBox, stroke-width 1.5) -- not a custom drawing, matching the
    provenance discipline icon/clock-x.blade.php's own file-header
    documents: a real glyph or nothing, never invented path data.

    No default classes -- every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
</svg>
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/View/Components/MkBottomNavTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-mk.bottom-nav> — the five-tab mobile persistent navigation
 * primitive, FFI full-visual-clone design doc §3, Stage 2 ticket 02,
 * approved by ADR-0044. Unwired: no real screen renders this yet
 * (Stage 3's job) -- these tests render the component in isolation via
 * Blade::render(), matching MkCardTest/MkIconMedallionTest/
 * MkSkeletonTest's established seam.
 *
 * `active` is an explicit prop (matching header.blade.php's own
 * established convention), not request-path auto-detection -- see this
 * plan's Global Constraints for why that deviates from the ticket's own
 * wording. Tests pass it directly; no request-faking needed.
 */
final class MkBottomNavTest extends TestCase
{
    public function test_all_five_tabs_render_with_correct_hrefs_and_order(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $expectedOrder = [
            'href="/"',
            'href="/pemesanan-makam"',
            'href="/perpanjangan"',
            'href="/akun"',
            'href="/bantuan"',
        ];

        $lastPosition = -1;
        foreach ($expectedOrder as $href) {
            $this->assertStringContainsString($href, $html);
            $position = strpos($html, $href);
            $this->assertGreaterThan($lastPosition, $position, "$href out of order");
            $lastPosition = $position;
        }
    }

    public function test_hidden_above_lg_breakpoint(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $this->assertStringContainsString('lg:hidden', $html);
    }

    public function test_nav_landmark_wraps_the_whole_component(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('aria-label="Navigasi utama"', $html);
    }

    public function test_default_active_is_null_and_renders_no_aria_current(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $this->assertStringNotContainsString('aria-current="page"', $html);
    }

    public function test_active_akun_gets_aria_current_and_shape_and_colour_marking(): void
    {
        $html = Blade::render('<x-mk.bottom-nav active="akun" />');

        // aria-current="page" appears exactly once, on the Akun anchor.
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));

        $akunAnchorStart = strpos($html, 'href="/akun"');
        $akunAnchorEnd = strpos($html, '</a>', $akunAnchorStart);
        $akunAnchor = substr($html, $akunAnchorStart, $akunAnchorEnd - $akunAnchorStart);

        $this->assertStringContainsString('aria-current="page"', $akunAnchor);
        $this->assertStringContainsString('text-primary-700', $akunAnchor);
        $this->assertStringContainsString('border-t-2', $akunAnchor);
        $this->assertStringContainsString('border-primary-600', $akunAnchor);
        $this->assertStringNotContainsString('border-transparent', $akunAnchor);
    }

    public function test_active_bantuan_gets_aria_current_and_shape_and_colour_marking(): void
    {
        $html = Blade::render('<x-mk.bottom-nav active="bantuan" />');

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));

        $bantuanAnchorStart = strpos($html, 'href="/bantuan"');
        $bantuanAnchorEnd = strpos($html, '</a>', $bantuanAnchorStart);
        $bantuanAnchor = substr($html, $bantuanAnchorStart, $bantuanAnchorEnd - $bantuanAnchorStart);

        $this->assertStringContainsString('aria-current="page"', $bantuanAnchor);
        $this->assertStringContainsString('text-primary-700', $bantuanAnchor);
        $this->assertStringContainsString('border-t-2', $bantuanAnchor);
        $this->assertStringContainsString('border-primary-600', $bantuanAnchor);
    }

    public function test_inactive_tabs_carry_transparent_border_not_no_border(): void
    {
        // Same layout-stability technique header.blade.php already uses:
        // inactive tabs hold the border's space with a transparent one,
        // so activating a different tab never shifts anything.
        $html = Blade::render('<x-mk.bottom-nav active="akun" />');

        $berandaAnchorStart = strpos($html, 'href="/"');
        $berandaAnchorEnd = strpos($html, '</a>', $berandaAnchorStart);
        $berandaAnchor = substr($html, $berandaAnchorStart, $berandaAnchorEnd - $berandaAnchorStart);

        $this->assertStringContainsString('border-t-2', $berandaAnchor);
        $this->assertStringContainsString('border-transparent', $berandaAnchor);
        $this->assertStringNotContainsString('aria-current="page"', $berandaAnchor);
    }

    public function test_uses_the_bottomnav_zindex_and_height_tokens(): void
    {
        $html = Blade::render('<x-mk.bottom-nav />');

        $this->assertStringContainsString('z-bottomnav', $html);
        $this->assertStringContainsString('mk-bottomnav-total', $html);
    }
}
```

- [ ] **Step 3: Implement `<x-mk.bottom-nav>`**

Create `resources/views/components/mk/bottom-nav.blade.php`:

```blade
{{--
    resources/views/components/mk/bottom-nav.blade.php

    <x-mk.bottom-nav> — the five-tab mobile persistent navigation
    primitive, FFI full-visual-clone design doc §3, Stage 2 ticket 02,
    approved by ADR-0044 (resolves design-system.md OQ-04). Follows
    button.blade.php's convention: @props with defaults, classes composed
    once in a single @php block, one $attributes->merge() on the root.

    `active` is an explicit prop, matching header.blade.php's own
    established convention exactly (same pattern: 'active' => null, a
    closed set of string keys) -- NOT request-path auto-detection. This
    component is unwired here; whichever Stage 3 ticket wires it into a
    real page passes `active` explicitly, the same way every page that
    renders <x-mk.header> already must.

    z-bottomnav (1100) sits above z-sticky-cta (900) -- the token whose
    own comment names it "wizard sticky footer", even though its one real
    consumer today (stepper.blade.php) uses it for a top-sticky progress
    header, not a bottom CTA bar. Documented here so whoever next builds
    a real bottom CTA bar knows the intended stacking order without
    re-deriving it.
--}}
@props([
    'active' => null, // 'beranda' | 'pemesanan' | 'perpanjangan' | 'akun' | 'bantuan' | null
])

@php
    $tabs = [
        ['key' => 'beranda', 'label' => 'Beranda', 'href' => '/', 'icon' => 'home'],
        ['key' => 'pemesanan', 'label' => 'Pemesanan', 'href' => '/pemesanan-makam', 'icon' => 'document-text'],
        ['key' => 'perpanjangan', 'label' => 'Perpanjangan', 'href' => '/perpanjangan', 'icon' => 'clock-x'],
        ['key' => 'akun', 'label' => 'Akun', 'href' => '/akun', 'icon' => 'user'],
        ['key' => 'bantuan', 'label' => 'Bantuan', 'href' => '/bantuan', 'icon' => 'question-mark-circle'],
    ];

    $activeClasses = 'text-primary-700 border-t-2 border-primary-600';
    $inactiveClasses = 'text-neutral-700 border-t-2 border-transparent';
@endphp

<nav {{ $attributes->merge(['class' => 'lg:hidden fixed inset-x-0 bottom-0 z-bottomnav h-[var(--mk-bottomnav-total)] bg-neutral-0 border-t border-neutral-200 pb-[var(--mk-safe-bottom)]']) }} aria-label="Navigasi utama">
    <ul class="grid h-[var(--mk-bottomnav-h)] grid-cols-5">
        @foreach ($tabs as $tab)
            <li>
                <a href="{{ $tab['href'] }}"
                    @if ($active === $tab['key']) aria-current="page" @endif
                    class="touch-target flex h-full w-full flex-col items-center justify-center gap-0.5 text-2xs transition-colors duration-fast ease-standard {{ $active === $tab['key'] ? $activeClasses : $inactiveClasses }}"
                >
                    <x-dynamic-component :component="'icon.' . $tab['icon']" class="size-5 shrink-0" aria-hidden="true" />
                    <span>{{ $tab['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
```

- [ ] **Step 4: Run the host-runnable check**

Run: `bash ci/verify-docs.sh`

Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/icon/home.blade.php resources/views/components/icon/user.blade.php resources/views/components/mk/bottom-nav.blade.php tests/Feature/View/Components/MkBottomNavTest.php
git commit -m "feat(design): add <x-mk.bottom-nav>, the five-tab mobile navigation primitive

Approved by ADR-0044. Five canonical tabs (Beranda/Pemesanan/
Perpanjangan/Akun/Bantuan), active tab marked by colour AND shape,
z-bottomnav stacking documented against the wizard sticky-footer token.
Adds two real Heroicons v2.2.0 outline glyphs (home, user) the tab set
needed and didn't have -- fetched directly from the upstream package,
matching icon/clock-x.blade.php's own provenance discipline. Unwired --
no real screen renders this yet, that's Stage 3's job. PHPUnit not
runnable on this host -- verified via CI's PHP job after push, per
docs/agents/issue-tracker.md's baseline route."
```

- [ ] **Step 6: Push and confirm via CI**

```bash
git push -u origin feat/ffi-clone-bottom-nav
```

Then read the resulting branch's real CI run for the "PHP (validate,
lint, analyse, test)" job — `gh run list --branch
feat/ffi-clone-bottom-nav --limit 1 --json databaseId,status,conclusion`,
then `gh run watch <id> --exit-status` if still running, and confirm
individual `MkBottomNavTest` cases in the raw log, not just the job's
overall conclusion. Do not mark this task complete until confirmed green
by reading the actual output.

---

### Task 2: Sync `design-system.md` §3.11/§11 and `information-architecture.md` §2 to the approved bottom nav

**Files:**
- Modify: `docs/design/design-system.md`
- Modify: `docs/product/information-architecture.md`

**Interfaces:**
- Consumes: Task 1's final, real component shape (the exact five tabs, routes, icons, `active` prop, and `border-t-2`/`text-primary-700` active-marking classes it ships). Read Task 1's actual committed diff before writing this task's prose rather than restating this plan's own Step 3 code as if a fix round couldn't have changed it.
- Produces: nothing consumed by a later task in this plan (last task).

**Seam constraint (MENGIKAT task ini, dari spec):** Task ini adalah
dokumentasi murni; tidak ada seam eksekusi otomatis. Cakupnya adalah
kebenaran tekstual dan kelengkapan: SETIAP klaim tentang status
persetujuan (proposed vs approved), jumlah tab, dan keberadaan hamburger
yang disajikan sebagai fakta SAAT INI di kedua berkas ini harus cocok
persis dengan ADR-0044 dan implementasi nyata Task 1 — bukan draft lama
yang ditinggalkan, dan bukan ditulis ulang diam-diam tanpa jejak
supersession sesuai konvensi dokumen ini sendiri.

- [ ] **Step 1: Read `design-system.md` §3.11 and §11's OQ-04 row exactly as they stand**

Run: `grep -n "OQ-04\|^### 3.11" docs/design/design-system.md`

Confirm the exact current line numbers and text before editing — this
plan's own quotes of them may have shifted if another change landed on
this branch's base since this plan was written.

- [ ] **Step 2: Add a supersession note to §3.11**

Immediately after §3.11's existing `>` blockquote (the "This component is
not in the approved IA... tracked as OQ-04" note), add a new paragraph —
do not delete or edit the existing blockquote, this document's convention
is additive supersession (see ADR-0041/ADR-0043's own precedent in this
same file):

```
**Approved 23 Sep 2026 ([ADR-0044](../adr/0044-approve-ffi-five-tab-bottom-navigation.md)), resolving OQ-04.** The blockquote above is historical — bottom navigation IS now approved, in the 5-item form below, not the 4-item draft that follows this note. §3.10's IA-compliant header stays the DEFAULT for desktop and is joined, not replaced, by this component on mobile; the existing hamburger (`<x-mk.header>`, §3.10) is KEPT alongside the bottom nav — the 5 canonical tabs (Beranda, Pemesanan, Perpanjangan, Akun, Bantuan) don't cover two real desktop nav items (Layanan Pemakaman, FAQ), so mobile still needs the hamburger's overflow to reach them.
```

- [ ] **Step 3: Replace §3.11's 4-item draft spec with the real, shipped 5-item form**

Below the note added in Step 2, replace the old 4-item spec paragraph
(the one starting "If approved, it must use the four canonical labels
unchanged and follow:") with the real, as-shipped description — read
Task 1's actual committed `bottom-nav.blade.php` to write this precisely
(the exact classes, the exact shape-marking mechanism chosen, the exact
touch-target sizing), not a re-statement of this plan's own Step 3
instructions. Keep the historical 4-item text above it untouched, per the
same additive convention.

- [ ] **Step 4: Update §11's OQ-04 row**

Find the OQ-04 row in §11's open-questions table (currently: `| **OQ-04**
| **Mobile bottom navigation**... | IA-compliant header only; bottom nav
**not shipped** | §3.11 |`). Update the middle column to: `**Resolved,
approved 23 Sep 2026 ([ADR-0044](../adr/0044-approve-ffi-five-tab-bottom-navigation.md)).** 5-tab bottom nav ships on mobile,
existing hamburger kept alongside it for the 2 desktop items the 5 tabs
don't cover (Layanan Pemakaman, FAQ).` Keep the row's first column
(the original question text) unchanged — only the resolution column
updates, matching how OQ-01/OQ-12's rows in the same table were resolved
without rewriting their original question text.

- [ ] **Step 5: Update `information-architecture.md` §2's mobile-nav bullets**

Read the current bullets first — `sed -n '83,96p' docs/product/
information-architecture.md` — before editing, since another change may
have touched this section since this plan was written. Update the mobile
bullet list to describe the approved end state: a persistent bottom nav
(5 tabs) plus the existing hamburger (kept, for the 2 items the 5 tabs
don't cover) — not a silent deletion of the hamburger bullet, and not
left describing the old hamburger-only pattern as current. Cite
ADR-0044.

- [ ] **Step 6: Run the host-runnable check**

Run: `bash ci/verify-docs.sh`

Expected: `RESULT: ALL DOC GATES PASS` — confirms the new ADR-0044 link
and any other relative links added in this task resolve correctly (GATE
4).

- [ ] **Step 7: Commit**

```bash
git add docs/design/design-system.md docs/product/information-architecture.md
git commit -m "docs(design): sync design-system.md and information-architecture.md to the approved bottom nav (ADR-0044)

§3.11 supersession note + real 5-item shipped spec (additive, historical
4-item draft kept per this doc's own convention), §11's OQ-04 row marked
resolved/approved, IA §2's mobile-nav bullets updated to describe the
bottom-nav-plus-kept-hamburger end state -- the hamburger stays because
the 5 canonical tabs don't cover Layanan Pemakaman/FAQ."
```

- [ ] **Step 8: Push**

```bash
git push
```

No CI job specific to this task beyond `ci/verify-docs.sh` (already run
in Step 6) and whatever ran on Task 1's push — this task is docs-only.
