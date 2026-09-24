# FFI Clone Whole-Frontend — Marketplace Restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the marketplace vendor listing grid and vendor detail page to match the site's FFI-aligned visual language, and restructure the detail page onto the same hero/gallery-image + structured-info-blocks + call-to-action pattern already used by the cemetery detail page — a visual pass only, with the marketplace's existing add-to-cart/booking mechanics, pricing display, and vendor data completely unchanged.

**Architecture:** Two Blade view edits, no PHP domain/Livewire-component changes. `resources/views/livewire/public/marketplace/index.blade.php` keeps its existing `<x-mk.card interactive as="a">` grid (already the canonical reference implementation of design-system.md §3.3b's card-grid pattern — cited there by name) and gets a larger photo scale for a more gallery-like catalog feel. `resources/views/livewire/public/marketplace/product-detail.blade.php` is restructured from its current two-column (photo-left/info-right) layout into a single-column vertical `<article>` — header (badge + name + vendor line), full-width hero photo, then a sequence of `<section aria-labelledby>` blocks (price/availability + call-to-action, description, variants, ordering status, support) — mirroring `resources/views/livewire/public/directory/detail.blade.php`'s real, already-shipped structural shape line-for-line where the content genuinely maps across. No route, no Livewire action, no domain-query change; every existing conditional branch, wire:click binding, and rendered string is preserved verbatim, only regrouped under the new structure.

**Tech Stack:** Laravel Livewire, Blade, Tailwind (via `tokens.css`/`mk.*` primitives).

**Spec:** `.scratch/ffi-clone-whole-frontend/spec.md` (ticket 03: `.scratch/ffi-clone-whole-frontend/issues/03-marketplace-restyle.md`)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah di bawah keluar dengan status 0.

    $HOME/.claude/skills/specflow/scripts/check-plan-headings.sh    <rencana ini> <task-brief>
    $HOME/.claude/skills/specflow/scripts/check-seam-constraints.sh <rencana ini> <task-brief>

## Global Constraints

- **Foundation retained, not rebuilt.** `tokens.css`, the `mk.*` Blade component library, `design-system.md`, and ADR-0043/ADR-0044/ADR-0045 stand as the correct foundation; this is a fresh design pass on top of that architecture, not a rebuild of it. (Parent spec, Implementation Decisions.)
- **Marketplace (vendor listing + vendor detail).** Restyled to the same grid → detail structural pattern as the cemetery directory, since both map to the same FFI analog (a filterable card grid leading to a detail page). The marketplace's existing booking/checkout mechanics are unchanged — this is a visual pass only. (Parent spec, Implementation Decisions.)
- **Marketplace product-catalog icons are unaffected.** The gravestone-shaped SVG icons used as catalog imagery are a distinct case from marketing imagery and are out of scope for the photography rule; this plan does not touch `resources/views/components/icon/*gravestone*` or any catalog-icon markup. (Parent spec, Implementation Decisions.)
- **Photography rule.** The marketing/emotional register never shows closeup grave/tombstone imagery; functional product photography (the marketplace's own catalog photos) is unaffected by that rule and stays exactly as rendered today — this plan changes only the photo's layout box (size/position), never which photo renders or whether it renders. (Parent spec, Implementation Decisions / Photography rule.)
- **No backend or domain logic changes anywhere in this initiative.** Any real defect found incidentally is fixed and reported explicitly, not silently patched over and not left unfixed to preserve scope purity. (Parent spec, Implementation Decisions.)
- **Out of scope:** the Filament admin/vendor/operator panels (not touched); any backend/domain logic, pricing, availability, payment, or notification behavior (not touched — every `wire:click`, Action call, and domain read in `MarketplaceIndex`/`ProductDetail` is byte-identical after this plan); sourcing or licensing new photography (none sourced); the pre-existing `storage:link` wiring gap for admin-uploaded marketplace product photos (known, unrelated, not fixed here); restructuring the marketplace's actual step sequence or domain logic (visual language only). (Parent spec, Out of Scope.)
- **RULING (this plan's own real finding, not assumed by the ticket): the listing grid is not restructured.** `design-system.md` §3.3b names `MarketplaceIndex`'s own product grid, by file path, as the canonical **existing** reference implementation of the card-as-navigation pattern ("`<ul aria-label="Daftar produk">` — each product card links to its detail page via `as="a" :href="route('marketplace.product', ...)"`, using the real photo-`media` shape above"). Rebuilding a pattern the design system cites as already-correct would fork a canonical example, which §9.2 forbids. Task 1 below is therefore a scale/proportion refinement (larger photo box, matching a catalog's gallery feel) on top of the existing, unforked structure — not a rebuild.
- **Ticket 02 (cemetery directory restyle) is running in a separate, parallel, file-disjoint worktree and has not merged as of this plan.** `directory/detail.blade.php`'s current, in-repo shape (header → full-width photo → `<section aria-labelledby>` blocks → support hatch) is the STRUCTURAL pattern this plan clones for the marketplace detail page, independent of whatever colour/spacing polish ticket 02 lands later — this plan does not depend on, wait for, or read ticket 02's unmerged branch.
- **Test seams for this ticket** (parent spec, Testing Decisions, scoped to marketplace): `MarketplaceIndexRouteTest`, `ProductDetailRouteTest` (HTTP-route level, this repository's established convention for "does this page now look right" claims), plus `tests/browser/e2e-marketplace.spec.ts` and `tests/browser/e2e-marketplace-mobile.spec.ts` (real rendered/accessibility verification the route tests cannot do). The existing verified axe-core false-positive exclusion in `e2e-marketplace.spec.ts` (`AxeBuilder().exclude('button[wire\\:click="addToCart"]')`, the modal-backdrop contrast case) is not removed or widened — this plan keeps the `wire:click="addToCart"` attribute on the literal `<button>` element the exclusion selector targets.
- **`bash ci/verify-docs.sh` must pass after every task** (GATE 1 WCAG contrast, GATE 2 no hardcoded design values, GATE 3 no arbitrary Tailwind values, GATE 11 no raw z-index, GATE 12 no unreplaced focus suppression).
- PHP tests cannot run directly on this host (worktrees ship without `vendor/`; the host's PHP is 8.3, the project needs >= 8.5) — every task's PHP test run happens inside the project's PHP 8.5 app container, or is verified via CI on the pushed branch; never reported PASS from an unexecuted run.

---

### Task 1: Restyle the vendor listing grid's photo scale

**Files:**
- Modify: `resources/views/livewire/public/marketplace/index.blade.php`
- Modify: `tests/Feature/Livewire/Public/Marketplace/MarketplaceIndexRouteTest.php`

**Interfaces:**
- Consumes: nothing new — `MarketplacePresenter::photoUrl()`/`priceAttribution()`/`vendorLabel()`, `MarketplaceCatalogQuery`, `MarketplaceProductCategory` all unchanged, called exactly as today.
- Produces: nothing consumed by Task 2 (the two views are independent files with no shared partial).

**Changes:**
- In the product grid's `<x-slot:media>` block, change both the real `<img>` and the no-photo placeholder `<div>` from `h-40` to `h-48 md:h-56` (a larger, more gallery-like catalog photo scale — still a plain Tailwind height utility already used elsewhere in this codebase, not an arbitrary bracket value). Keep `w-full object-cover` on the `<img>` and the placeholder's centered-text layout unchanged.
- Do not change the grid's column/gap classes (`grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3 xl:grid-cols-4`) — this is the exact recipe `design-system.md` §4.3 documents as canonical; changing it would fork the documented pattern per this plan's own Global Constraints ruling above.
- Do not change any conditional, any rendered string, any route/href, or the empty/degraded/unknown-category states — content is byte-identical, only the photo box's height classes move.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `MarketplaceIndexRouteTest` (HTTP-level, `$this->get('/marketplace')` dan variannya), mengikuti pola pengujian yang sudah ada di file yang sama. Cakup SETIAP perilaku task ini MELALUI seam itu: kelas foto baru (`h-48 md:h-56`) benar-benar dirender pada kartu produk dengan foto DAN pada placeholder "Foto belum tersedia" (dua cabang berbeda), dan bahwa `test_the_landing_page_offers_no_cart_or_checkout_affordance` (pemisahan di `</header>`) serta seluruh assertion string yang sudah ada (kategori, chip aktif, empty state, provider-unavailable, estimasi harga, marker vendor contoh) tetap lolos tanpa perubahan. Helper internal (`MarketplacePresenter`) diuji secara tidak langsung lewat seam ini, tidak pernah langsung. Nilai harapan test adalah literal string yang diketahui (`'h-48 md:h-56'`, `'Foto belum tersedia'`), bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] `index.blade.php`'s product-card media block updated to `h-48 md:h-56` on both the real-photo `<img>` and the no-photo placeholder `<div>`; grid column/gap classes left untouched.
- [ ] Test added: `test_the_product_grid_photo_box_uses_the_restyled_gallery_scale` — asserts the response body contains `h-48 md:h-56` at least once with a real photo present (default seeded state), and asserts it also appears in the legacy-no-photo branch (reuse the existing `DB::table('products')->where(...)->update(['photo_path' => null])` fixture from `test_a_legacy_product_with_no_photo_shows_an_honest_placeholder_card`).
- [ ] Every pre-existing `MarketplaceIndexRouteTest` test method re-read against the new markup and confirmed still structurally correct (no method body changes needed — this task's diff is additive-only).
- [ ] `bash ci/verify-docs.sh` run from the worktree root; all gates pass.
- [ ] PHP test run inside the project's PHP 8.5 app container (or CI on the pushed branch) — `MarketplaceIndexRouteTest` green; report the real result, not an assumption.
- [ ] Commit.

---

### Task 2: Restructure the vendor detail page onto the hero/info-blocks/CTA pattern

**Files:**
- Modify: `resources/views/livewire/public/marketplace/product-detail.blade.php`
- Modify: `tests/Feature/Livewire/Public/Marketplace/ProductDetailRouteTest.php`

**Interfaces:**
- Consumes: nothing new — `$product`, `$listing`, `$variants`, `$variantsUnavailable`, `$conflictOpen`, `$conflict`, `MarketplacePresenter`, `MarketplaceProductCategory`, `AvailabilityMode`, `EvidenceRequirement` are the exact same view variables `App\Livewire\Public\Marketplace\ProductDetail` already passes today — this task does not touch that class.
- Produces: nothing consumed elsewhere (no shared partial with Task 1's file).

**Changes — restructure the view's body (leave the back-nav link and the conflict modal exactly as they are, verbatim) into this order, replacing the current two-column `grid grid-cols-1 ... lg:grid-cols-2` layout:**

1. `<article class="space-y-8">` wrapping everything below.
2. `<header class="space-y-3">`: the category `<x-mk.badge intent="neutral">{{ $product->categoryLabel() }}</x-mk.badge>`, then `<h1 class="text-3xl font-semibold tracking-tight text-neutral-900">{{ $product->name }}</h1>` (same classes as today), then the vendor/attribution line directly under it — `Ditawarkan oleh {{ $listing->vendor->name }}` when `$listing !== null`, else the existing `Vendor: {{ MarketplacePresenter::vendorLabel($product) }}` line when `$listing === null && $product->vendor_name` — same conditions and same text as today, just moved out of the info column into the header.
3. Full-width hero photo, styled exactly like `directory/detail.blade.php`'s photo block: `<img src="{{ $photoUrl }}" alt="" class="h-56 w-full rounded-lg object-cover md:h-72">` when `$photoUrl` is set, else the matching placeholder `<div class="flex h-56 w-full items-center justify-center rounded-lg bg-neutral-100 md:h-72"><span class="text-sm text-neutral-600">Foto belum tersedia</span></div>`.
4. `<section aria-labelledby="harga-ketersediaan-heading" class="space-y-3">`: `<h2 id="harga-ketersediaan-heading" class="text-lg font-semibold text-neutral-900">Harga dan Ketersediaan</h2>`, then — verbatim, unchanged conditions and text — the existing `$listing !== null` branch (price via `$listing->priceMoney()->format()`, the availability/stock/lead-time/evidence/cancellation `<dl>`) or the existing dummy-price/no-offer branch (`MarketplacePresenter::priceAttribution()`), followed immediately by the existing call-to-action block (`<x-mk.button wire:click="addToCart" ...>Tambah ke Keranjang</x-mk.button>` plus its one-vendor note paragraph) when `$listing !== null` — same `wire:loading.attr`, same `full`/`class="md:w-auto"`, same note text.
5. `<section aria-labelledby="deskripsi-heading" class="space-y-2">`: `<h2 id="deskripsi-heading" class="text-lg font-semibold text-neutral-900">Deskripsi</h2>` then `<p class="max-w-prose text-base text-neutral-700">{{ $product->description }}</p>` (moved verbatim from the current info column).
6. The existing variants `<section aria-labelledby="product-variants-heading">` block — unchanged in full (heading, all three states, the variant card grid, `wire:key`).
7. The existing ordering-status `<section aria-labelledby="ordering-status-heading">` block — unchanged in full (both alert states, both action slots).
8. The existing support-escape-hatch `<section>` block — unchanged in full.
9. Close `</article>`, then the existing conflict modal — unchanged in full, still a sibling of `<article>`, still gated on `$conflictOpen && $conflict !== null`.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `ProductDetailRouteTest` (HTTP-level, `$this->get('/marketplace/produk/{productCode}')` dan `Livewire::test(ProductDetail::class, ...)` mengikuti pola yang sudah ada di file yang sama). Cakup SETIAP perilaku task ini MELALUI seam itu, termasuk setiap kondisi batas dan jalur kegagalan yang sudah diuji hari ini: cabang `$listing !== null` vs `$listing === null` (harga real vs harga dummy + marker "data contoh"/"vendor contoh"), keberadaan/ketidakberadaan tombol "Tambah ke Keranjang" dan atribut `wire:click="addToCart"`-nya yang literal pada elemen `<button>`, ketiga status panel varian (tidak ada axis, axis ada tapi kosong, axis terisi), status pemesanan (alert pending vs alert real-offer), modal konflik satu-vendor, kegagalan baca tabel `product_variants` yang mendegradasi panel tanpa menjatuhkan halaman, dan produk yang dinonaktifkan/tidak dikenal yang tetap 404. Helper internal (`MarketplacePresenter`) diuji secara tidak langsung lewat seam ini, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan test adalah literal string yang sudah ada di test file ini hari ini (mis. `'Ditawarkan oleh '`, `'Tambah ke Keranjang'`, `'harga-ketersediaan-heading'`, `'deskripsi-heading'`), bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] `product-detail.blade.php` restructured exactly as above; every existing conditional, every existing rendered string, every `wire:click`/`wire:loading` binding, and the modal are byte-identical to today, only regrouped.
- [ ] Confirm directly against the diff that no PHP-side variable, route helper, or domain call was added, removed, or renamed.
- [ ] Test added: `test_the_detail_page_renders_the_hero_gallery_info_block_call_to_action_structure` — asserts the response contains a `<section` whose contents include `id="harga-ketersediaan-heading"` and one whose contents include `id="deskripsi-heading"`, and (reusing the existing `test_a_gravestone_product_shows_its_seeded_variants` fixture) that the `Pilihan Varian` heading still sits inside a `<section` tag (mirroring what `e2e-marketplace.spec.ts`'s `page.locator('section', { has: page.getByRole('heading', { name: 'Pilihan Varian' }) })` already checks in the browser).
- [ ] Every pre-existing `ProductDetailRouteTest` test method re-read against the new markup: confirm `wire:click="addToCart"` stays a literal attribute on the `<button>` element itself (not moved onto a wrapper), confirm `Ditawarkan oleh {vendor}` still renders unconditionally with the response (no lazy/Livewire-only reveal), confirm the "no listing" branch still omits the add button and still shows `Pemesanan online belum tersedia` / `Hubungi Customer Service` / the single-vendor note.
- [ ] `bash ci/verify-docs.sh` run from the worktree root; all gates pass.
- [ ] PHP test run inside the project's PHP 8.5 app container (or CI on the pushed branch) — `ProductDetailRouteTest` green; report the real result, not an assumption.
- [ ] Commit.

---

### Task 3: Browser-suite and whole-ticket verification

**Files:**
- No source changes expected (read/verify only) unless a real defect is found, per this plan's own Global Constraints ("fixed and reported explicitly, not silently patched over").
- Read: `tests/browser/e2e-marketplace.spec.ts`, `tests/browser/e2e-marketplace-mobile.spec.ts`, `tests/browser/e2e-marketplace-helpers.ts`.

**Interfaces:**
- Consumes: the final markup from Task 1 and Task 2.
- Produces: nothing — last task.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `tests/browser/e2e-marketplace.spec.ts` dan `tests/browser/e2e-marketplace-mobile.spec.ts` — verifikasi rendering nyata dan aksesibilitas yang tidak bisa dibuktikan lewat assertion HTTP saja. Cakup SETIAP locator di kedua file itu terhadap markup baru dari Task 1 dan Task 2: `getByRole('heading', level 1)`, `getByRole('navigation', {name: 'Filter kategori produk'})`, `getByRole('list', {name: 'Daftar produk'})`, `getByText('Ditawarkan oleh ...')`, `getByRole('button', {name: 'Tambah ke Keranjang'})`, `page.locator('section', {has: getByRole('heading', {name: 'Pilihan Varian'})})`, dan `AxeBuilder().exclude('button[wire\\:click="addToCart"]')`. Jangan menghapus atau memperluas exclusion axe yang sudah ada. Nilai harapan adalah teks/atribut literal yang sudah ada di kedua spec file itu hari ini, bukan dihitung ulang.

- [ ] Every locator in `e2e-marketplace.spec.ts` and `e2e-marketplace-mobile.spec.ts` walked against the final Task 1/Task 2 markup by hand (role, accessible name, text, and attribute selectors) — confirmed each still resolves to exactly the element it did before the restyle. Any mismatch found is fixed in the view (never in the spec file, unless the spec file was asserting an incidental implementation detail rather than real external behavior — and if so, that judgment is stated explicitly here, not silently applied).
- [ ] Confirm `button[wire\\:click="addToCart"]` (the axe-exclude selector) still matches the literal `<button>` the CTA renders, and that the exclusion itself is untouched in the spec file.
- [ ] `bash ci/verify-docs.sh` run one final time from the worktree root; all gates pass.
- [ ] Full `MarketplaceIndexRouteTest` + `ProductDetailRouteTest` suites run inside the project's PHP 8.5 app container (or confirmed green on CI for the pushed branch) — report the real result.
- [ ] State plainly whether the real Playwright browser suite itself was run anywhere in this task, or whether it is deferred to CI on the pushed branch — never reported PASS for a browser run that did not happen.
- [ ] Commit (if any fix was needed); otherwise confirm nothing to commit.

---

**Self-review:**

- **Spec coverage:** every ticket acceptance-criterion checkbox in `.scratch/ffi-clone-whole-frontend/issues/03-marketplace-restyle.md` is addressed — listing grid restyle (Task 1), detail page hero/info-blocks/CTA restructure matching the cemetery detail pattern (Task 2), unchanged booking/cart mechanics and untouched catalog icons (Global Constraints + Task 2's byte-identical-conditions requirement), `MarketplaceIndexRouteTest`/`ProductDetailRouteTest` real new assertions (Task 1/2), the existing "no cart affordance" landing-page scoped assertion left intact (Task 1), the e2e specs and their axe-exclude preserved (Task 3), `bash ci/verify-docs.sh` passing (every task).
- **Placeholder scan:** none — every task names the exact file, the exact class strings, and the exact existing conditionals being moved, not a TODO or an unspecified "restyle it."
- **Type consistency:** no new PHP types introduced; every Blade variable referenced in Task 2's restructure is one `ProductDetail::render()` already passes today, verified against the file read during planning.
