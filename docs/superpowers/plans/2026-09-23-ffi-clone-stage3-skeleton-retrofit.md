# FFI Clone Stage 3 — Skeleton Retrofit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the four existing hand-rolled loading-placeholder `div`s (renewal start ×2, cemetery directory ×1, FAQ index ×1) with the real `<x-mk.skeleton>` component, with no visible behaviour change beyond the placeholder's own implementation.

**Architecture:** Each hand-rolled `<div class="h-NN rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>` becomes one `<x-mk.skeleton shape="card" announce="" />` instance, as a direct sibling replacing it 1:1 inside its existing `wire:loading` wrapper — the wrapper's own grid/stack layout classes, `wire:target`, `aria-busy="true"`, and contextual `sr-only` announcement text stay unchanged, since the wrapper already owns the correct loading semantics for that region. `announce=""` on each skeleton instance suppresses the component's own default "Memuat…" text so the wrapper's one, more specific announcement is the only one a screen reader hears per region — using `count=N` instead (one skeleton instance owning all N placeholders) was considered and rejected because the component's own multi-instance layout (`space-y-2`, a vertical stack) would replace the original grid/row layouts (`md:grid-cols-2`, `xl:grid-cols-3`) at two of the four sites, a real layout change the ticket doesn't ask for.

**Tech Stack:** Laravel 13, Blade, Livewire, PHPUnit (via real CI only — this host is PHP 8.3, the app needs 8.5).

**Spec:** .scratch/ffi-clone-stage3-homepage/issues/02-skeleton-retrofit.md (ticket), .scratch/ffi-clone-stage3-homepage/spec.md (parent spec, source of Testing Decisions/seam below)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah
di bawah keluar dengan status 0.

    /home/ubuntu/.claude/skills/specflow/scripts/check-plan-headings.sh    docs/superpowers/plans/2026-09-23-ffi-clone-stage3-skeleton-retrofit.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.4.1/skills/subagent-driven-development/scripts/task-brief
    /home/ubuntu/.claude/skills/specflow/scripts/check-seam-constraints.sh docs/superpowers/plans/2026-09-23-ffi-clone-stage3-skeleton-retrofit.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.4.1/skills/subagent-driven-development/scripts/task-brief

## Global Constraints

- Behaviour (what shows while loading, when it stops showing) is unchanged — only the placeholder's implementation changes (ticket's own Solution statement).
- Exact pixel-height parity between each hand-rolled placeholder's height and the component's fixed preset heights is NOT required (ticket's own acceptance criteria) — `shape="card"` is used uniformly across all four sites (see Architecture).
- `prefers-reduced-motion` behaviour is already built into `<x-mk.skeleton>` itself (Stage 2) — nothing new to build here, only verify it still applies once wired in.
- No hand-rolled `bg-[var(--mk-skeleton-base)] animate-pulse` markup may remain anywhere in the codebase after this plan (ticket's own acceptance criteria) — verified by a repo-wide search, not assumed from the four sites named here.
- No page's step structure, field rules, validation, or copy changes as a side effect (parent spec's Out of Scope, applies to every Stage 3 ticket touching these pages).
- Out of scope: any `tokens.css` value or new token (parent spec's Out of Scope) — this plan consumes `--mk-skeleton-base`/`--mk-skeleton-sheen` exactly as Stage 2 already defined them, adds nothing.

---

### Task 1: Replace all four hand-rolled placeholders and verify no stragglers remain

**Files:**
- Modify: `resources/views/livewire/public/renewal/start.blade.php` (two sites: the Step 2 TPU/TPS grid loading region, and the search-results loading region)
- Modify: `resources/views/livewire/public/directory/index.blade.php` (one site: the filtered-list loading region)
- Modify: `resources/views/livewire/public/faq/index.blade.php` (one site: the search-results loading region)
- Test: `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php`
- Test: `tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php`
- Test: `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php`

**Interfaces:**
- Consumes: `<x-mk.skeleton>` (Stage 2, already shipped) — props used here: `shape="card"` (string, one of `text`/`card`/`media`/`section`, this task always passes `card`), `announce=""` (string, empty to suppress the component's own default "Memuat…" text since the wrapping region already announces).
- Produces: nothing consumed by a later task — this plan has one task.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk
task ini adalah HTTP-level route feature tests per page — real requests via
`$this->get('<route>')` against `RenewalStartTest`,
`CemeteryDirectoryIndexRouteTest`, and `FaqIndexRouteTest` (each page's
existing, established seam, per the parent spec's Testing Decisions).
Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu: the real
Livewire loading state actually triggered (via the same `wire:loading`
mechanism already in place, not a mocked/forced state), the real component
markup present in place of the old `div`s, the wrapper's own aria-busy and
contextual sr-only text still present and unchanged, and a repo-wide
confirmation that no hand-rolled `bg-[var(--mk-skeleton-base)]
animate-pulse` string remains anywhere after this task. Nilai harapan
dalam test harus literal yang diketahui (exact class strings, exact
`shape`/`announce` prop usage), bukan dihitung ulang dengan cara yang sama
seperti kode.

- [ ] **Step 1: Replace the renewal-start Step 2 TPU/TPS loading region**

In `resources/views/livewire/public/renewal/start.blade.php`, inside the
`wire:loading.delay wire:target="selectCity,resetCity"` wrapper (the
`grid gap-4 md:grid-cols-2` region around line 116-120), replace each of
the two `<div class="h-28 rounded-lg bg-[var(--mk-skeleton-base)]
animate-pulse"></div>` lines with:

```blade
<x-mk.skeleton shape="card" announce="" />
```

Leave the wrapper `<div>`'s own attributes (`wire:loading.delay`,
`wire:target`, `class="grid gap-4 md:grid-cols-2"`, `aria-busy="true"`)
and its existing `<span class="sr-only">Memuat daftar TPU/TPS&hellip;</span>`
completely unchanged.

- [ ] **Step 2: Replace the renewal-start search-results loading region**

Same file, the `wire:loading.delay wire:target="search"` wrapper around
line 318-323 (`space-y-3`, three placeholder divs at `h-16`). Replace all
three `<div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)]
animate-pulse"></div>` lines with three `<x-mk.skeleton shape="card"
announce="" />` lines, same pattern as Step 1. Leave the wrapper and its
`<span class="sr-only">Mencari data makam&hellip;</span>` unchanged.

- [ ] **Step 3: Replace the cemetery-directory loading region**

In `resources/views/livewire/public/directory/index.blade.php`, the
`wire:loading.delay wire:target="city,type,resetFilters"` wrapper (around
line 271-280, a `@for ($i = 0; $i < 3; $i++)` loop rendering three `h-72`
placeholder divs). Replace the loop body's single
`<div class="h-72 rounded-lg bg-[var(--mk-skeleton-base)]
animate-pulse"></div>` with `<x-mk.skeleton shape="card" announce="" />` —
the surrounding `@for` loop itself stays, so this still produces three
instances, one per iteration. Leave the wrapper and its
`<span class="sr-only">Memuat daftar lokasi…</span>` unchanged.

- [ ] **Step 4: Replace the FAQ-index loading region**

In `resources/views/livewire/public/faq/index.blade.php`, the
`wire:loading.delay wire:target="search"` wrapper (around line 220-224,
three placeholder divs at `h-20`). Replace all three
`<div class="h-20 rounded-lg bg-[var(--mk-skeleton-base)]
animate-pulse"></div>` lines with three `<x-mk.skeleton shape="card"
announce="" />` lines. Leave the wrapper and its
`<span class="sr-only">Memuat hasil pencarian…</span>` unchanged.

- [ ] **Step 5: Repo-wide sweep for remaining hand-rolled skeleton markup**

Run:

```bash
grep -rn "bg-\[var(--mk-skeleton-base)\]" resources/ app/ 2>/dev/null
```

Expected: no output. If anything remains, replace it the same way as
Steps 1-4 before continuing — the ticket's own acceptance criteria
requires zero hand-rolled instances left anywhere, not just the four
sites named above.

- [ ] **Step 6: Add/extend the loading-state test per page**

In each of the three test files, add (or extend an existing loading-state
test, if one already exists) an assertion that triggers the real
Livewire loading state via the same seam the page's other tests already
use (`Livewire::test(...)->set(...)` or the HTTP-level equivalent already
established in that file — read the file's own existing tests first and
match its pattern exactly, do not introduce a new testing pattern into
these files), and asserts the rendered output contains the real
`<x-mk.skeleton>` component's distinguishing output (its `mk-skeleton-shimmer`
class string, which is unique to the component and was not present in the
old hand-rolled markup) rather than the old `bg-[var(--mk-skeleton-base)]
animate-pulse` string. Write real, literal expected strings — do not
recompute them from the component's own source in the test.

- [ ] **Step 7: Run the host-runnable check**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`

- [ ] **Step 8: Commit**

```bash
git add resources/views/livewire/public/renewal/start.blade.php \
        resources/views/livewire/public/directory/index.blade.php \
        resources/views/livewire/public/faq/index.blade.php \
        tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php \
        tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php \
        tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php
git commit -m "feat(design): replace hand-rolled skeleton placeholders with <x-mk.skeleton> (Stage 3 ticket 02)"
```

- [ ] **Step 9: Push and verify real CI**

```bash
git push -u origin feat/ffi-clone-stage3-skeleton-retrofit
gh run watch <run-id> --exit-status
```

This host cannot run PHPUnit directly (PHP 8.3, app needs 8.5) — CI's
"PHP (validate, lint, analyse, test)" job is the authoritative test
result. Read the real run log to confirm the specific new/extended test
methods actually ran and passed, not just the job's overall conclusion.
