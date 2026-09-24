# FFI Pixel Fidelity — Footer Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the shared footer's dark inverse surface with a light surface and FFI-matching grouped-column structure, using only Makam's own real content.

**Architecture:** One Blade template change (`layouts/app.blade.php`'s `<footer>` block), no new components, no new routes. Two existing test files updated to assert the new structure and the absence of the old dark-surface classes.

**Tech Stack:** Laravel Blade, Tailwind utilities backed by `tokens.css` `@theme` primitives.

**Spec:** `.scratch/ffi-clone-pixel-fidelity/spec.md` (ticket: `.scratch/ffi-clone-pixel-fidelity/issues/03-footer-redesign.md`)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah
di bawah keluar dengan status 0.

    <akar specflow>/scripts/check-plan-headings.sh    <rencana ini> <task-brief>
    <akar specflow>/scripts/check-seam-constraints.sh <rencana ini> <task-brief>

## Global Constraints

- Surface changes from `bg-primary-900`/inverse text to a light surface already registered in `tokens.css` (`--color-neutral-100`, i.e. Tailwind's `bg-neutral-100`) with `text-neutral-900`/`text-neutral-600` for body/secondary text.
- Content restructures into grouped columns under real heading elements: a "Bantuan" group (FAQ, Bantuan/Kontak) and a "Legal" group (Kebijakan Privasi, Syarat & Ketentuan) at minimum — no invented "Informasi"/About/Careers/Press column.
- No social-media icons — confirmed via repo-wide search that Makam has no established real social account anywhere in this codebase (only a mention of FFI/kitabisa's own social links in `docs/product/catatan-pemilik-2026-09-22-tampilan-ffi.md`, an unrelated comparison doc).
- Company name/address (`CompanyInfo::name()`/`::address()`) stays present.
- The bottom-nav clearance margin (`mb-[var(--mk-bottomnav-total)] lg:mb-0`) on the `<footer>` element is UNCHANGED — `HomePageRouteTest::test_bottom_nav_does_not_visually_overlap_the_footer` asserts this exact string and is unrelated to this ticket's color/structure change.
- Old dark-surface classes (`bg-primary-900`, inverse text/logo variant) are genuinely removed, not left alongside new ones.
- No placeholder `href="#"` links.

---

### Task 1: Redesign the shared footer's surface and column structure

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (the `<footer>` block)
- Modify: `resources/css/tokens.css` (correct the now-stale `--mk-surface-inverse` "footer" comment)
- Modify: `docs/design/design-system.md` (additive note on the page-shell diagram and OQ tracking — this doc's own convention is additive supersession, never silently rewriting historical text)
- Modify: `tests/Feature/Livewire/Public/Legal/FooterLegalLinksRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/HomePageRouteTest.php` (only if a new footer-specific assertion is added there; the existing bottom-nav-clearance test must keep passing unmodified)

**Interfaces:**
- Consumes: `App\Support\CompanyInfo::name()` / `::address()` (unchanged, already injected via the layout's existing PHP), `route('legal.privacy')`, `route('legal.terms')`, the plain `/bantuan` href (unchanged href style, matching the existing convention this same footer already uses for that one link).
- Produces: nothing consumed by a later task in this plan (single-task plan).

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah HTTP-level: `$this->get('/privasi')` against `Tests\Feature\Livewire\Public\Legal\FooterLegalLinksRouteTest`, and `$this->get('/')` against `Tests\Feature\Livewire\Public\HomePageRouteTest` for the one pre-existing bottom-nav-clearance assertion that must keep passing unmodified. Cakup SETIAP perilaku task ini MELALUI seam itu: the new light-surface classes are present, the old dark-surface classes are genuinely absent (assert the negative, not just the positive presence of new classes), the new column headings render as real heading elements with the correct grouped links, the company name/address line survives, and the bottom-nav clearance margin string on the `<footer>` element is byte-identical to before. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] **Step 1: Read the real current footer markup and its own doc-comment history**

Read `resources/views/layouts/app.blade.php`'s `<footer>` block and the three dated comment blocks above it (17 Aug 2026 brand row, 26 Jul 2026 inverse-surface upgrade, 14 Sep 2026 padding rhythm) — this is the exact decision being reversed; understand it before reversing it.

- [ ] **Step 2: Write the failing test assertions first**

In `tests/Feature/Livewire/Public/Legal/FooterLegalLinksRouteTest.php`, add:

```php
public function test_footer_uses_a_light_surface_not_the_old_dark_inverse_panel(): void
{
    $response = $this->get('/privasi');
    $response->assertOk();

    $html = $response->getContent();
    $this->assertNotFalse($html);

    // Assert the negative: the old dark-surface classes are genuinely
    // gone, not left alongside new light-surface ones.
    $this->assertStringNotContainsString('bg-primary-900', $html);
    $this->assertStringNotContainsString('variant="inverse"', $html);

    // Assert the positive: the new light surface is real.
    $footerStart = strpos($html, '<footer');
    $this->assertNotFalse($footerStart, 'Expected a <footer> element.');
    $footerEnd = strpos($html, '</footer>', $footerStart);
    $this->assertNotFalse($footerEnd);
    $footer = substr($html, $footerStart, $footerEnd - $footerStart);

    $this->assertStringContainsString('bg-neutral-100', $footer);
}

public function test_footer_renders_grouped_columns_with_real_headings(): void
{
    $response = $this->get('/privasi');
    $response->assertOk();

    $html = $response->getContent();
    $this->assertNotFalse($html);

    $footerStart = strpos($html, '<footer');
    $footerEnd = strpos($html, '</footer>', $footerStart);
    $footer = substr($html, $footerStart, $footerEnd - $footerStart);

    // Two real heading elements, one per group -- not just bold text.
    $this->assertMatchesRegularExpression('#<h[2-4][^>]*>\s*Bantuan\s*</h[2-4]>#', $footer);
    $this->assertMatchesRegularExpression('#<h[2-4][^>]*>\s*Legal\s*</h[2-4]>#', $footer);

    // No invented "Informasi"/About/Careers/Press column.
    $this->assertStringNotContainsString('Tentang Kami', $footer);
    $this->assertStringNotContainsString('Karir', $footer);

    // No social-media icons/links -- Makam has no established real
    // account for any of these.
    $this->assertStringNotContainsString('instagram.com', $footer);
    $this->assertStringNotContainsString('facebook.com', $footer);
    $this->assertStringNotContainsString('twitter.com', $footer);
}
```

- [ ] **Step 3: Run the tests to verify they fail against the current markup**

This host cannot run PHPUnit locally (PHP 8.3 vs the app's required 8.5) — verify the failure by reading the current markup directly instead: confirm `bg-primary-900` and `variant="inverse"` are currently present, and that no `<h2-4>Bantuan</h2-4>`/`<h2-4>Legal</h2-4>` currently exists. Real CI (push + `gh run watch`) is the authoritative pass/fail signal once implemented.

- [ ] **Step 4: Rewrite the footer's markup**

Replace the `<footer>` block in `resources/views/layouts/app.blade.php` with a light-surface, grouped-column structure. Keep the exact bottom-nav clearance classes (`mb-[var(--mk-bottomnav-total)] lg:mb-0`) on the `<footer>` element itself. Example shape (exact Tailwind spacing/grid values are this task's own call, matching FFI's real `Footer.tsx` grid pattern — `grid grid-cols-2 gap-4` mobile, more columns at `sm`+ — adapted to Makam's 2 real groups rather than FFI's 4):

```blade
<footer class="bg-neutral-100 px-4 py-section text-neutral-900 md:px-6 lg:px-8 lg:py-section-lg mb-[var(--mk-bottomnav-total)] lg:mb-0">
    <div class="mx-auto max-w-content">
        <div class="flex flex-col items-center gap-2 text-center md:items-start md:text-left">
            <a href="/" class="inline-flex items-center gap-2" aria-label="makam.co.id — beranda">
                <x-mk.logo :size="28" />
            </a>
        </div>
        <div class="mt-6 grid grid-cols-2 gap-6 text-center md:text-left">
            <div>
                <h2 class="text-sm font-semibold text-neutral-900">Bantuan</h2>
                <ul class="mt-2 space-y-1 text-sm text-neutral-600">
                    <li><a href="/bantuan" class="underline underline-offset-2 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2">Bantuan / Kontak</a></li>
                </ul>
            </div>
            <div>
                <h2 class="text-sm font-semibold text-neutral-900">Legal</h2>
                <ul class="mt-2 space-y-1 text-sm text-neutral-600">
                    <li><a href="{{ route('legal.privacy') }}" class="underline underline-offset-2 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2">Kebijakan Privasi</a></li>
                    <li><a href="{{ route('legal.terms') }}" class="underline underline-offset-2 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2">Syarat &amp; Ketentuan</a></li>
                </ul>
            </div>
        </div>
        <div class="mt-6 border-t border-neutral-200 pt-4 text-center text-sm text-neutral-600 md:text-left">
            <p>&copy; {{ date('Y') }} Makam.co.id</p>
            <p class="text-xs">{{ \App\Support\CompanyInfo::name() }} &middot; {{ \App\Support\CompanyInfo::address() }}</p>
        </div>
    </div>
</footer>
```

Note: `<x-mk.logo>` drops its `variant="inverse"` prop (default variant, since the surface is no longer dark) — confirm the default variant renders correctly on a light background by reading `logo.blade.php` before finalizing.

- [ ] **Step 5: Run `bash ci/verify-docs.sh`**

Expected: `RESULT: ALL DOC GATES PASS`. GATE 1 (WCAG contrast) is real here — `text-neutral-900`/`text-neutral-600` on `bg-neutral-100` must be verified against `docs/design/verify-contrast.py`'s real pairs list or computed directly; if either pair isn't already asserted there, compute the real contrast ratio before committing (do not assume it passes).

- [ ] **Step 6: Correct the now-stale token comment**

In `resources/css/tokens.css`, `--mk-surface-inverse: var(--color-primary-900);` currently carries the trailing comment `/* footer */`. That's now stale — the token itself stays (still real, still potentially useful for a future inverse-surface element), but update the comment to note the footer no longer uses it, per this ticket's reversal.

- [ ] **Step 7: Additive note in `design-system.md`**

Add a dated note beside the existing page-shell diagram line (`├─ Footer (surface-inverse: primary-900) ────────────────────┤`) and the `white on surface-inverse (footer)` contrast-pairs table row, recording that the footer changed to a light surface per the owner's 1:1 pixel-fidelity direction — do not delete or rewrite the historical text, this doc's own established convention is additive supersession only.

- [ ] **Step 8: Run the tests to verify they pass**

Push and watch real CI (`gh run watch`) — the authoritative signal on this host. Confirm both new test methods appear as real individual `✓` PASS entries in the raw log, and that the pre-existing `test_bottom_nav_does_not_visually_overlap_the_footer` and `FooterLegalLinksRouteTest`'s other existing methods still pass unmodified.

- [ ] **Step 9: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/css/tokens.css docs/design/design-system.md tests/Feature/Livewire/Public/Legal/FooterLegalLinksRouteTest.php
git commit -m "feat(design): redesign the shared footer to FFI's light, grouped-column layout"
```
