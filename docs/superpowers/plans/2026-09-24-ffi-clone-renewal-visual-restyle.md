# FFI Clone — Renewal Flow Visual Restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the renewal flow's three screens (`/perpanjangan` search, `/perpanjangan/pembayaran` fee & payment, `/perpanjangan/konfirmasi` confirmation) fully onto this project's existing FFI-aligned `mk.*` Blade primitive stack, closing the two concrete visual-language gaps found in the current markup, with zero change to quoting, payment-session, or confirmation/notification behavior.

**Architecture:** This is a Blade-view-only change across two files. Investigation (recorded below) found the renewal flow already consumes `<x-mk.button>`, `<x-mk.card>`, `<x-mk.alert>`, `<x-mk.stepper>`, `<x-mk.skeleton>`, and `<x-mk.gate-closed-page>` from prior batches, so it already inherited the pixel-fidelity batch's corrected token colours automatically (those primitives read `tokens.css`, which that batch corrected — no page-level edit was needed for that part). Two genuine, narrow gaps remain, both purely presentational:
1. `start.blade.php`'s grave-search form hand-rolls three `<input>` elements with duplicated Tailwind classes instead of the `<x-mk.field>` primitive — a real primitive-consistency gap, now closeable because `<x-mk.field>`'s earlier "cannot forward `wire:model`" limitation (documented in `faq/index.blade.php`'s own header comment) has since been fixed (`$wireAttributes` extraction in `field.blade.php`), and is proven working elsewhere (`memorial/family-page.blade.php`, `visitation/page.blade.php`).
2. `payment.blade.php`'s fee-quote card renders two label/value detail blocks as non-semantic `<div class="grid grid-cols-2">` + `<span>` pairs with a plain divider, where the booking wizard's own already-shipped, pre-dating-this-initiative convention (`wizard.blade.php:1466`, the bank-transfer destination block) wraps an equivalent "detail panel within a bigger card" in a `<dl>` with `rounded-lg bg-neutral-50 p-4` — a real card/spacing-language gap.

Both fixes are markup/class-only; no Livewire component method, property, or validation rule is touched.

**Tech Stack:** Laravel 13, Livewire 4, Blade, Tailwind 4 (tokens.css `@theme`), PHPUnit (Livewire testing helpers), Playwright (`tests/browser/*.spec.ts`).

**Spec:** `.scratch/ffi-clone-whole-frontend/spec.md` (parent spec) and `.scratch/ffi-clone-whole-frontend/issues/06-renewal-visual-restyle.md` (this ticket).

## Global Constraints

- Foundation retained, not rebuilt. `tokens.css`, the `mk.*` Blade component library, `design-system.md`, and ADR-0043/ADR-0044/ADR-0045 stand as the correct foundation. This initiative is a fresh *design* pass — deciding what the rest of the site should look like — built on that existing architecture, not a rebuild of the architecture itself.
- Renewal flow. Same visual-language-only treatment as the booking wizard, applied to the renewal flow's existing step structure (start, payment, confirmation). Its existing payment handling and confirmation behavior are unchanged.
- No backend or domain logic changes anywhere in this initiative. This is a visual/presentation-layer redesign across the public site. Any real defect found incidentally during implementation is fixed and reported explicitly, not silently patched over and not left unfixed to preserve scope purity.
- Filament admin, vendor, and operator panels are explicitly untouched by this initiative — not part of the public storefront this initiative redesigns.
- Out of Scope: Any backend/domain logic, pricing, availability, payment, or notification behavior on any page — this initiative is visual/presentation-layer only.
- Out of Scope: Restructuring the booking wizard's or renewal's actual step sequence or domain logic to match FFI's donation-checkout flow — visual language only, per the confirmed domain mismatch between grave-plot booking and donation checkout.
- Out of Scope: The Filament admin, vendor, and operator panels — no visual changes.
- `bash ci/verify-docs.sh` must pass for every unit of work (GATE 1 WCAG contrast, GATE 2 no hardcoded design values, GATE 3 no arbitrary Tailwind values, GATE 11 no raw z-index, GATE 12 no unreplaced focus suppression).
- **Local test-execution constraint (this repo, not the spec):** PHPUnit cannot run on this host (worktrees ship without `vendor/`, host PHP is 8.3 while the app requires >= 8.5) and the pinned deployed app image (`ghcr.io/andrianm28/makam-app`) does not carry PHPUnit either — confirmed absent as a dev-only dependency (`docs/superpowers/plans/2026-09-23-ffi-clone-mk-skeleton.md`, `docs/agents/issue-tracker.md`'s "Baseline test route"). The authoritative PHP test run happens in CI's "PHP (validate, lint, analyse, test)" job after pushing. Never report a local PHPUnit PASS — report `NOT TESTED locally, verified via CI` explicitly, per `AGENTS.md`'s rule against reporting `PASS` for a check that was not executed. `bash ci/verify-docs.sh` DOES run directly on this host (pure Python/grep, no `vendor/` needed) and must be run for real before any commit.

## Review Focus

- A person tabbing through the grave-search form with a screen reader after a blank-submission or malformed-date error must still hear the error announced against the correct field — `<x-mk.field>`'s own `aria-describedby`/`aria-invalid` wiring must replace the old hand-written `aria-describedby="grave-search-name-error"` pairing exactly, not just relocate the error text somewhere visible.
- A person who fills only `block` or only `deathDate` (leaving `name` blank) and submits must see the exact same "(opsional)" marker text next to those two labels as before — `<x-mk.field :optional="true">`'s rendered marker must byte-for-byte match the hand-written `<span class="font-normal text-neutral-600">(opsional)</span>` it replaces, and must never accidentally render as `:required="true"`.
- The swapped `<input type="date" wire:model="deathDate">` must keep updating the same public Livewire property `RenewalStart::$deathDate` — `test_an_invalid_death_date_is_a_validation_error` and `test_a_valid_death_date_still_runs_the_search` depend on that exact binding target, and the field-primitive swap must change only the wrapping markup, never which property `wire:model` names.
- The fee-quote card's conditional late-fine block (`@if ($quote->hasLateFine())`) and the `$isSandboxPayment` alert in `payment.blade.php` must render identically before and after the two detail blocks above them are restructured into `<dl>`s — a person with no late fine must never see a stray leftover divider or an empty tinted panel where the old `border-t`/`border-b` wrapper used to be.
- The "Reset pencarian" button's visibility condition (`$name !== '' || $block !== '' || $deathDate !== ''`) lives in the Livewire component's Blade conditional, not in the field markup — a person who types into any field and clears it must still see that button appear/disappear exactly as before; this condition itself must not be touched by the field-primitive swap.

---

### Task 1: Replace the hand-rolled grave-search inputs with `<x-mk.field>` in `start.blade.php`

**Files:**
- Modify: `resources/views/livewire/public/renewal/start.blade.php:219-316` (the `<form wire:submit.prevent="search">` block — the three raw `<input>` elements and their wrapping `<div>`s/`<label>`s/`@error` blocks)
- Test: `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php`

**Interfaces:**
- Consumes: `<x-mk.field>` (`resources/views/components/mk/field.blade.php`) — props used: `type`, `id`, `name`, `label`, `hint`, `optional`, `autocomplete`, `wire:model` (forwarded to the real control via the component's own `$wireAttributes` extraction), `:error`, `class`. No change to this component.
- Produces: nothing new — `App\Livewire\Public\Renewal\RenewalStart`'s public properties `$name`, `$block`, `$deathDate` and its `search()`/`resetSearch()` methods are untouched and keep the exact same names other tasks/tests already depend on.

- [ ] **Step 1: Read the current form block in full to confirm line numbers before editing**

Run: `sed -n '219,316p' resources/views/livewire/public/renewal/start.blade.php`
Expected: the three raw `<input>` elements (`name`/`block`/`death_date`) exactly as investigated — confirms no other agent has touched this file since this plan was written (if the content differs, stop and re-read the file before proceeding, per `superpowers:subagent-driven-development`'s own file-collision guard).

- [ ] **Step 2: Add new assertions to `RenewalStartTest.php` for the field-primitive markers (written to fail against today's markup)**

Add a new test method near the existing search-form tests (after `test_opening_the_gate_replaces_the_explanatory_page_with_the_search_form`, around line 597-606):

```php
public function test_the_grave_search_form_uses_the_mk_field_primitive(): void
{
    $this->openTheDataGate();

    $html = Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
        ->assertSeeHtml('id="grave-search-name"')
        ->assertSeeHtml('id="grave-search-block"')
        ->assertSeeHtml('id="grave-search-death-date"')
        // <x-mk.field>'s own wrapper class (field.blade.php's `$attributes->merge(['class' => 'flex flex-col gap-1.5'])`)
        // — the marker that this is now rendered by the primitive, not hand-rolled markup.
        ->assertSeeHtml('flex flex-col gap-1.5')
        // <x-mk.field>'s own literal `(opsional)` marker recipe for `block`/`deathDate`.
        ->assertSeeHtml('<span class="font-normal text-neutral-600">(opsional)</span>')
        ->html();

    // `name` has no optional/required marker — only two, matching `block` and `deathDate`.
    $this->assertSame(2, substr_count($html, '<span class="font-normal text-neutral-600">(opsional)</span>'));
}

public function test_a_blank_submission_still_describes_the_name_error_to_the_field(): void
{
    $this->openTheDataGate();

    Livewire::test(RenewalStart::class, ['cemeteryId' => CemeteryFixture::id('package', 0)])
        ->call('search')
        ->assertSeeHtml('aria-describedby="grave-search-name-error"')
        ->assertSeeHtml('aria-invalid="true"');
}
```

- [ ] **Step 3: Confirm the new assertions fail against the current (unmodified) markup**

This cannot run locally (see Global Constraints — no PHPUnit on this host or in the deployed app image). Read the current raw-`<input>` markup by eye against the new assertions: `id="grave-search-name"` etc. already exist today (unchanged by this task), but `flex flex-col gap-1.5` and the exact `(opsional)` span do NOT exist in today's markup (today's labels write `Blok <span class="font-normal text-neutral-600">(opsional)</span>` inline inside the `<label>`, not as a sibling produced by `<x-mk.field>`'s own wrapper — confirm by grep):

Run: `grep -n 'flex flex-col gap-1.5\|font-normal text-neutral-600' resources/views/livewire/public/renewal/start.blade.php`
Expected (before Step 4): the `(opsional)` spans exist inline in the two `<label>` tags, but no `flex flex-col gap-1.5` wrapper exists anywhere in this file — confirming the new assertion is real, not vacuous.

- [ ] **Step 4: Replace the three raw inputs with `<x-mk.field>`**

Replace the entire `<form wire:submit.prevent="search" ...>...</form>` block (lines 219-316) with:

```blade
                <form wire:submit.prevent="search" role="search" aria-label="Cari data makam" class="mx-auto mb-8 max-w-form">
                    <div class="flex flex-col gap-4">
                        <x-mk.field
                            type="search"
                            id="grave-search-name"
                            name="name"
                            label="Nama almarhum"
                            hint="Pencarian memaklumi perbedaan ejaan dan tanda baca, jadi tidak harus persis sama."
                            autocomplete="off"
                            wire:model="name"
                            :error="$errors->first('name')"
                        />

                        <div class="flex flex-col gap-4 sm:flex-row">
                            <x-mk.field
                                type="text"
                                id="grave-search-block"
                                name="block"
                                label="Blok"
                                :optional="true"
                                autocomplete="off"
                                wire:model="block"
                                :error="$errors->first('block')"
                                class="flex-1"
                            />

                            <x-mk.field
                                type="date"
                                id="grave-search-death-date"
                                name="death_date"
                                label="Tanggal wafat"
                                :optional="true"
                                wire:model="deathDate"
                                :error="$errors->first('deathDate')"
                                class="flex-1"
                            />
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-mk.button
                                variant="primary"
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="search"
                            >
                                Cari Data Makam
                            </x-mk.button>

                            @if ($name !== '' || $block !== '' || $deathDate !== '')
                                <x-mk.button variant="secondary" wire:click="resetSearch">
                                    Reset pencarian
                                </x-mk.button>
                            @endif
                        </div>
                    </div>
                </form>
```

Notes for the implementer:
- The old per-field `placeholder="Contoh: Budi Santoso"` / `placeholder="Contoh: A-12"` text is intentionally dropped — `<x-mk.field>` has no `placeholder` prop (design-system.md §3.2: "Labels are always visible — placeholder-as-label is forbidden"), matching every other `<x-mk.field>` call site in this codebase (none of them use a placeholder).
- The hint for `name` now renders ABOVE the input (between label and control) instead of below it — this is `<x-mk.field>`'s own canonical §3.2 order, not a mistake; do not try to move it back below.
- `name="death_date"` (snake_case) is the plain HTML `name` attribute kept for parity with the original; `wire:model="deathDate"` (camelCase) is the unrelated Livewire binding — both must be passed, matching the original markup's own split.

- [ ] **Step 5: Verify the diff touches only this one block, and run the mechanical doc/design gates**

Run: `git diff -- resources/views/livewire/public/renewal/start.blade.php`
Expected: only the form block changes; the stepper, alert, step 1/2 city/cemetery sections, and everything below the closing `</form>` are byte-identical to before.

Run: `bash ci/verify-docs.sh`
Expected: all gates PASS (GATE 1-3, 11, 12 in particular — no hardcoded colour/spacing, no arbitrary Tailwind values were introduced).

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/public/renewal/start.blade.php tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php
git commit -m "feat(renewal): use <x-mk.field> for the grave-search form instead of hand-rolled inputs"
```

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `RenewalStartTest` dan `tests/browser/e2e-renewal.spec.ts`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk bentuk input yang tidak biasa, kondisi batas, dan jalur kegagalan. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

---

### Task 2: Wrap the fee-quote card's detail blocks in a tinted `<dl>` panel in `payment.blade.php`

**Files:**
- Modify: `resources/views/livewire/public/renewal/payment.blade.php:104-152` (the grave-info and tariff-info detail blocks inside the `$mode === 'fee'` branch's `<x-mk.card>`)
- Test: `tests/Feature/Livewire/Public/Renewal/RenewalPaymentTest.php`

**Interfaces:**
- Consumes: no new component — plain `<dl>`/`<dt>`/`<dd>` with Tailwind utility classes already used identically at `resources/views/livewire/public/booking/wizard.blade.php:1466` (`rounded-lg bg-neutral-50 p-4`, `grid grid-cols-[auto_1fr] gap-x-4 gap-y-2`), an already-shipped convention this task reuses verbatim, not invents.
- Produces: nothing new — `App\Livewire\Public\Renewal\RenewalPayment`'s public properties (`$graveView`, `$quote`, etc.) and `terimaDanLanjutkan()` method are untouched.

- [ ] **Step 1: Read the current fee-quote card block in full to confirm line numbers before editing**

Run: `sed -n '91,170p' resources/views/livewire/public/renewal/payment.blade.php`
Expected: the `@elseif ($graveView && $quote)` branch containing the grave-info `<div class="grid grid-cols-2 ...">` (label/value `<span>` pairs), the amount display, the tariff-info `<div class="grid grid-cols-2 ...">`, the optional late-fine block, and the CTA — exactly as investigated. If this differs, stop and re-read before proceeding.

- [ ] **Step 2: Add new assertions to `RenewalPaymentTest.php` for the tinted-panel markers (written to fail against today's markup)**

Add a new test method near the existing fee-mode assertions (after the test containing `->assertSee('Sumber tarif')` around line 482):

```php
public function test_the_fee_quote_cards_detail_blocks_use_the_tinted_panel_convention(): void
{
    $renewal = $this->openRenewalAtFeeStep();

    Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->reference])
        // Matches the already-shipped booking-wizard convention verbatim
        // (resources/views/livewire/public/booking/wizard.blade.php:1466).
        ->assertSeeHtml('rounded-lg bg-neutral-50 p-4')
        ->assertSeeHtml('<dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm rounded-lg bg-neutral-50 p-4">')
        ->assertSeeHtml('<dt class="text-neutral-600">Nama almarhum</dt>')
        ->assertSeeHtml('<dt class="text-neutral-600">Sumber tarif</dt>');
}
```

If this repository's existing `RenewalPaymentTest.php` does not already expose a helper that opens a renewal at the fee step with a real `$graveView`/`$quote` (check for a private method resembling `openRenewalAtFeeStep`, `feeStepRenewal`, or similar near the top of the file before writing this test) — reuse whichever existing helper the file's own `test_...` methods asserting `'Sumber tarif'` already use, rather than inventing a new one; call it by its real name instead of `openRenewalAtFeeStep`.

- [ ] **Step 3: Confirm the new assertions fail against the current (unmodified) markup**

Run: `grep -n 'grid grid-cols-2 gap-x-4 gap-y-1 text-sm\|<dt\|<span class="text-neutral-600">Nama almarhum' resources/views/livewire/public/renewal/payment.blade.php`
Expected (before Step 4): today's markup uses `<div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">` and `<span class="text-neutral-600">Nama almarhum</span>` (no `<dt>`, no `bg-neutral-50`) — confirming the new assertions are real, not vacuous.

- [ ] **Step 4: Restructure both detail blocks into tinted `<dl>`s**

Replace (inside the `@elseif ($graveView && $quote)` branch, the `<x-mk.card>`'s inner `<div class="flex flex-col gap-6">`):

```blade
                            <div class="flex flex-col gap-2 border-b border-neutral-200 pb-4">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                    <span class="text-neutral-600">Nama almarhum</span>
                                    <span class="font-medium text-neutral-900">{{ $graveView->deceasedName }}</span>

                                    <span class="text-neutral-600">Blok</span>
                                    <span class="font-medium text-neutral-900">{{ $graveView->block ?? '—' }}</span>

                                    <span class="text-neutral-600">Jatuh tempo saat ini</span>
                                    <span class="font-medium text-neutral-900">
                                        {{ $graveView->dueDate ?? '—' }}
                                    </span>
                                </div>
                            </div>
```

with:

```blade
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm rounded-lg bg-neutral-50 p-4">
                                <dt class="text-neutral-600">Nama almarhum</dt>
                                <dd class="font-medium text-neutral-900">{{ $graveView->deceasedName }}</dd>

                                <dt class="text-neutral-600">Blok</dt>
                                <dd class="font-medium text-neutral-900">{{ $graveView->block ?? '—' }}</dd>

                                <dt class="text-neutral-600">Jatuh tempo saat ini</dt>
                                <dd class="font-medium text-neutral-900">
                                    {{ $graveView->dueDate ?? '—' }}
                                </dd>
                            </dl>
```

And replace:

```blade
                            <div class="flex flex-col gap-1 border-t border-neutral-200 pt-4">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                    <span class="text-neutral-600">Sumber tarif</span>
                                    <span class="font-medium text-neutral-900">{{ $quote->tariffSource }}</span>

                                    <span class="text-neutral-600">Terakhir diperbarui</span>
                                    <span class="font-medium text-neutral-900">
                                        {{ $quote->tariffEffectiveAt?->format('d F Y') ?? '—' }}
                                    </span>
                                </div>
                            </div>
```

with:

```blade
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm rounded-lg bg-neutral-50 p-4">
                                <dt class="text-neutral-600">Sumber tarif</dt>
                                <dd class="font-medium text-neutral-900">{{ $quote->tariffSource }}</dd>

                                <dt class="text-neutral-600">Terakhir diperbarui</dt>
                                <dd class="font-medium text-neutral-900">
                                    {{ $quote->tariffEffectiveAt?->format('d F Y') ?? '—' }}
                                </dd>
                            </dl>
```

Leave every other line in this file (the amount display, the late-fine `@if` block, the sandbox alert, the CTA button, the `'not_found'`/`'payment'` mode branches) untouched — the `border-t border-neutral-200 pt-4` wrapper on the late-fine block and the CTA block stays exactly as-is; only the two blocks shown above lose their now-redundant `border-b`/`border-t` wrapper `<div>` because the new `<dl>`'s own `bg-neutral-50` tint already provides the visual separation (matching the booking-wizard precedent, which uses no border alongside its own tinted `<dl>`).

- [ ] **Step 5: Verify the diff and run the mechanical doc/design gates**

Run: `git diff -- resources/views/livewire/public/renewal/payment.blade.php`
Expected: only the two detail blocks change (div/span → dl/dt/dd, plus the added `rounded-lg bg-neutral-50 p-4` and the removed now-redundant wrapper `border-b`/`border-t` divs); the amount display, late-fine block, sandbox alert, CTA, and every other mode branch (`'not_found'`, `'payment'`) are byte-identical to before.

Run: `bash ci/verify-docs.sh`
Expected: all gates PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/public/renewal/payment.blade.php tests/Feature/Livewire/Public/Renewal/RenewalPaymentTest.php
git commit -m "feat(renewal): wrap the fee-quote card's detail blocks in the booking wizard's tinted-panel convention"
```

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `RenewalPaymentTest`, `tests/browser/e2e-renewal.spec.ts`, dan `tests/browser/e2e-renewal-external.spec.ts`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk bentuk input yang tidak biasa, kondisi batas, dan jalur kegagalan. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

---

### Task 3: Whole-branch verification — confirm behavior parity and push

**Files:**
- None modified — this task only runs verification across the two files Tasks 1-2 touched.

**Interfaces:**
- Consumes: the diffs produced by Task 1 and Task 2.
- Produces: nothing — this is the plan's own final gate before handoff to `superpowers:finishing-a-development-branch`.

- [ ] **Step 1: Full-repo diff review against the ticket's behavior-preservation AC**

Run: `git diff origin/docs/design-system-and-planning -- resources/views/livewire/public/renewal/`
Expected: changes are confined to `start.blade.php` and `payment.blade.php`, both entirely inside Blade markup (no `<?php` business logic beyond the pre-existing `$errors->first(...)` calls Task 1 introduces, which is a read-only Blade-layer call, not a Livewire component change). `RenewalStart.php`, `RenewalPayment.php`, `RenewalConfirmation.php` (the PHP Livewire component classes) and every non-Blade file are untouched. `confirmation.blade.php` is untouched (no visual gap was found there — see this plan's Architecture section).

- [ ] **Step 2: Run the mechanical doc/design gate one more time on the whole branch**

Run: `bash ci/verify-docs.sh`
Expected: all gates PASS.

- [ ] **Step 3: Push and let CI run the real PHP and Playwright jobs**

Run: `git push -u origin feat/ffi-clone-renewal`
Expected: CI's "PHP (validate, lint, analyse, test)" job and the Playwright job both run against the two new test methods added in Tasks 1-2 plus the full existing `RenewalStartTest`/`RenewalPaymentTest`/`RenewalConfirmationTest` suites and `e2e-renewal.spec.ts`/`e2e-renewal-external.spec.ts`. Report the CI run's real result — `PASS`, `FAIL`, or `NOT TESTED locally, verified via CI` — never a fabricated local PASS, per the Global Constraints' local test-execution constraint.

No commit in this task — it is verification-only, feeding into `superpowers:finishing-a-development-branch`'s own option-2 (push + open PR) flow.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `RenewalStartTest`, `RenewalPaymentTest`, `RenewalConfirmationTest`, `tests/browser/e2e-renewal.spec.ts`, dan `tests/browser/e2e-renewal-external.spec.ts`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk bentuk input yang tidak biasa, kondisi batas, dan jalur kegagalan. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.
