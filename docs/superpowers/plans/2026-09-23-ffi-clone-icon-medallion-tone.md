# FFI Clone Stage 2 — `<x-mk.icon-medallion>` Tone Rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename `<x-mk.icon-medallion>`'s `tone` prop values from the superseded brand names `earth`/`leaf` to palette-honest names, so a caller reading a call site can tell what colour they're choosing without cross-referencing `tokens.css`.

**Architecture:** A pure rename at the component's public API boundary — `earth` becomes `primary` and `leaf` becomes `secondary`, matching the token family names those values have already resolved to since Stage 1's palette rebase (`bg-primary-100 text-primary-800` and `bg-secondary-100 text-secondary-800` respectively; the underlying classes do not change, only the string a caller passes). `brand` is untouched — it already names what it does, not a colour identity. Every real call site (enumerated below, verified by direct search, not assumed) is updated in the same change; no deprecation shim, no dual-accepting period — this is a small enough, fully-enumerated blast radius (3 consumer files) that expand-contract sequencing would be pure overhead.

**Tech Stack:** Laravel Blade components, PHPUnit (`Illuminate\Support\Facades\Blade::render()` seam).

**Spec:** `.scratch/ffi-clone-stage2-components/issues/03-icon-medallion-tone-rename.md` (ticket; seam and testing rationale drawn from the parent spec, `.scratch/ffi-clone-stage2-components/spec.md`)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah
di bawah keluar dengan status 0. Controller yang membaca header ini: kalau
salah satu belum dijalankan, jalankan dulu; kalau ada yang gagal, perbaiki
rencananya, jangan melewati gerbangnya.

    /home/ubuntu/.claude/skills/specflow/scripts/check-plan-headings.sh    docs/superpowers/plans/2026-09-23-ffi-clone-icon-medallion-tone.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief
    /home/ubuntu/.claude/skills/specflow/scripts/check-seam-constraints.sh docs/superpowers/plans/2026-09-23-ffi-clone-icon-medallion-tone.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief

## Global Constraints

- `tone` accepts `primary` (was `earth`), `secondary` (was `leaf`), `brand` (unchanged). No other value is added or removed.
- `brand` stays exactly as-is (`'brand' => 'bg-primary-600 text-neutral-0'`) — it names what it does, not a colour, and needs no rename.
- **Real, verified call sites** — this is the complete list, not a sample:
  - `resources/views/livewire/public/home-page.blade.php` line ~280: `tone="earth"` → `tone="primary"` (plus its own preceding comment, which currently reads "`tone=\"earth\"` is unchanged from Tahap 2" — update or remove that comment since the value *is* changing here, even though the rendered colour is not).
  - `resources/views/livewire/public/home-page.blade.php` line ~457: `tone="leaf"` → `tone="secondary"`.
  - `resources/views/livewire/public/akun/akun-index.blade.php`: four `<x-mk.icon-medallion>` usages (icons `clock`, `inbox`, `clock-x`, `document-text`) currently pass no `tone` at all, relying on the component's implicit default (`earth`, soon `primary`). **Decision made for this plan** (not the implementer's to re-litigate): all four get an explicit `tone="primary"` — they are four equal-weight, homogeneous account-navigation cards (Draft Pemesanan, Pesanan, Perpanjangan, Dokumen), none individually more "secondary-toned" than another, so making the current default explicit is the only change that doesn't alter what a real, live page renders today. Do not pick a different tone for any of the four without a documented reason — the point of this ticket is a rename, not a redesign.
  - `tests/Feature/View/Components/MkIconMedallionTest.php`: four `tone="earth"` assertions (all in usages, not in comments) → `tone="primary"`.
  - `resources/views/components/mk/card.blade.php` was checked and does **not** actually render an `<x-mk.icon-medallion>` — it only mentions the component's name once in a comment about its own naming convention. No change needed there.
  - `resources/views/components/mk/icon-medallion.blade.php` itself: the `@props` default (`'tone' => 'earth'` → `'tone' => 'primary'`), the `$tones` map keys (`'earth'`/`'leaf'` → `'primary'`/`'secondary'`, values unchanged), and the component's own doc comment (currently explains "why `tone=\"leaf\"` is inside the Leaf cage, not an exception to it" — update to stop naming a colour identity that no longer exists under that name, while keeping whatever of that explanation still substantively applies to the `secondary` family's own cage rule).
- `php artisan design:verify-filament-palette` and `bash ci/verify-docs.sh` must both still pass — this changes prop *values* only, no `tokens.css` entry, no colour value.
- **PHPUnit cannot run on this host.** Same constraint as the `<x-mk.skeleton>` plan: worktrees ship without `vendor/`, a full `composer install` is forbidden on this host per `CLAUDE.md`, and the pinned deployed app image doesn't carry PHPUnit (confirmed absent from its `vendor/bin/`). The real, authoritative test run happens in CI's "PHP (validate, lint, analyse, test)" job after pushing — report `NOT TESTED locally, verified via CI`, never a fabricated local pass.
- Out of scope, do not touch: `<x-mk.skeleton>`, `<x-mk.bottom-nav>` (separate tickets), any other `<x-mk.*>` primitive, any `tokens.css` value, any page's actual layout/structure beyond the one-line `tone=` attribute change per call site.

## File Structure

- **Modify:** `resources/views/components/mk/icon-medallion.blade.php` — the `@props` default, the `$tones` map keys, the doc comment.
- **Modify:** `resources/views/livewire/public/home-page.blade.php` — two call sites.
- **Modify:** `resources/views/livewire/public/akun/akun-index.blade.php` — four call sites (adding an explicit `tone="primary"` each).
- **Modify:** `tests/Feature/View/Components/MkIconMedallionTest.php` — four assertions.
- No file is created; no file outside this list is touched.

---

### Task 1: Rename `tone`'s `earth`/`leaf` values to `primary`/`secondary` across the component and every real call site

**Files:**
- Modify: `resources/views/components/mk/icon-medallion.blade.php`
- Modify: `resources/views/livewire/public/home-page.blade.php`
- Modify: `resources/views/livewire/public/akun/akun-index.blade.php`
- Test: `tests/Feature/View/Components/MkIconMedallionTest.php`

**Interfaces:**
- Consumes: nothing from another task (only task in this plan).
- Produces: `<x-mk.icon-medallion tone="primary|secondary|brand">` — the renamed public API. Nothing in this plan is consumed by a later task.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk
task ini adalah `Illuminate\Support\Facades\Blade::render('<x-mk.icon-
medallion ... />')`, mengikuti pola yang sudah ada di
`tests/Feature/View/Components/MkIconMedallionTest.php` sendiri —
merender komponen langsung dan mengassert pada string HTML yang
dihasilkan. Cakup SETIAP perilaku task ini MELALUI seam itu: `tone="primary"`
merender kelas yang sama persis dengan `tone="earth"` sebelumnya
(`bg-primary-100 text-primary-800`), `tone="secondary"` merender kelas yang
sama persis dengan `tone="leaf"` sebelumnya (`bg-secondary-100
text-secondary-800`), `tone="brand"` tidak berubah sama sekali, default
prop (tanpa `tone` sama sekali) sekarang merender `primary`'s classes, dan
nilai lama (`tone="earth"`, `tone="leaf"`) TIDAK LAGI diterima — komponen
ini SUDAH throw `InvalidArgumentException` untuk tone yang tidak dikenal
(pola defensif yang sama dengan badge.blade.php, sudah ada sebelum
perubahan ini), jadi merename key `$tones` membuat kedua nama lama
otomatis throw tanpa perubahan logic lain; assert exception itu benar-benar
terjadi, bukan sekadar "bukan kelas primary/secondary". Nilai harapan dalam
test adalah literal kelas Tailwind yang sudah diketahui (`bg-primary-100
text-primary-800`, dll.) atau exception class yang sudah diketahui
(`InvalidArgumentException`), bukan dihitung ulang.

- [ ] **Step 1: Read the current test file to establish the exact old assertions**

Run: `cat tests/Feature/View/Components/MkIconMedallionTest.php`

Confirm all four `tone="earth"` occurrences are inside `Blade::render()`
calls (not comments) before editing — this plan's Global Constraints
already verified this by direct search, but confirm on the actual file
you're about to edit in case it has drifted since this plan was written.

- [ ] **Step 2: Update the test file's four assertions**

Replace every `tone="earth"` with `tone="primary"` in
`tests/Feature/View/Components/MkIconMedallionTest.php` — these are the
four call sites inside `Blade::render()` strings, not any other text in
the file. Do not change any other assertion, method name, or the file's
existing structure.

Then add two new tests to the same file, appended after the existing
tests, inside the same `final class MkIconMedallionTest extends TestCase`
body:

```php
    public function test_the_old_earth_and_leaf_tone_names_now_throw(): void
    {
        // A rename, not a dual-accepting alias. icon-medallion.blade.php
        // already throws InvalidArgumentException for any tone not in its
        // $tones map (the same defensive pattern badge.blade.php uses for
        // $intent -- see the component's own file-header comment). Once
        // the map keys are renamed from earth/leaf to primary/secondary,
        // this throw fires for the old names automatically -- no logic
        // change, just a map-key rename -- and that's exactly what this
        // test locks in.
        $this->expectException(InvalidArgumentException::class);

        Blade::render('<x-mk.icon-medallion icon="document-text" tone="earth" />');
    }

    public function test_leaf_also_throws_after_the_rename(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Blade::render('<x-mk.icon-medallion icon="document-text" tone="leaf" />');
    }
```

(Two separate test methods, not one, since PHPUnit's `expectException`
only lets one exception assertion apply per test method — a single test
calling both `Blade::render()` lines would never reach the second line.)

- [ ] **Step 3: Rename in `icon-medallion.blade.php`**

Read `resources/views/components/mk/icon-medallion.blade.php` in full
first (it has real prior context this plan doesn't repeat — the file's
own comment on lines ~14-26 explains the `rounded-xl` choice and the
"why `tone=\"leaf\"` is inside the cage" rationale you're about to edit).

Make exactly these changes:
- `@props(['tone' => 'earth', ...])` → `@props(['tone' => 'primary', ...])` (keep every other prop and its default unchanged).
- The `$tones` array: `'earth' => 'bg-primary-100 text-primary-800'` → `'primary' => 'bg-primary-100 text-primary-800'` (same value, new key). `'leaf' => 'bg-secondary-100 text-secondary-800'` → `'secondary' => 'bg-secondary-100 text-secondary-800'` (same value, new key). `'brand' => 'bg-primary-600 text-neutral-0'` unchanged, key and value both.
- The doc comment discussing "why `tone=\"leaf\"` is inside the Leaf cage, not an exception to it": update every mention of `tone="leaf"` to `tone="secondary"`, and every mention of the "Leaf" brand name to describe the `secondary` family instead (Sage, per this repo's current palette) — but keep the substantive cage rule itself (secondary is surface/accent only, never a filled status chip) exactly as documented, since that rule is unchanged by this rename.

- [ ] **Step 4: Update `home-page.blade.php`'s two call sites**

In `resources/views/livewire/public/home-page.blade.php`:
- Find the comment near line ~279 reading something like "`tone=\"earth\"` is unchanged from Tahap 2" and the `tone="earth"` usage on the following line. Change the usage to `tone="primary"`. Update the comment to reflect that the *value* changed (rename) even though the *rendered colour* did not — don't leave a comment claiming something is "unchanged" when the literal string it names just changed.
- Find the `tone="leaf"` usage near line ~457 and change it to `tone="secondary"`.

- [ ] **Step 5: Update `akun-index.blade.php`'s four call sites**

In `resources/views/livewire/public/akun/akun-index.blade.php`, each of
the four `<x-mk.icon-medallion icon="...">` usages (icons `clock`,
`inbox`, `clock-x`, `document-text`) currently passes no `tone` at all.
Add `tone="primary"` explicitly to each of the four — per this plan's
Global Constraints, this preserves exactly what the page renders today
(the implicit default was already `earth`/soon-`primary`); it does not
introduce any visual change. Do not pick a different tone for any of the
four.

- [ ] **Step 6: Run the host-runnable check**

Run: `bash ci/verify-docs.sh`

Expected: `RESULT: ALL DOC GATES PASS` — confirms no hardcoded value or
arbitrary Tailwind value was introduced by this rename (it shouldn't be,
since every changed line only changes a string literal prop value, but
confirm rather than assume).

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/mk/icon-medallion.blade.php resources/views/livewire/public/home-page.blade.php resources/views/livewire/public/akun/akun-index.blade.php tests/Feature/View/Components/MkIconMedallionTest.php
git commit -m "feat(design): rename <x-mk.icon-medallion> tone earth/leaf to primary/secondary

Palette-honest prop values matching the token family names they already
resolve to since Stage 1's rebase -- earth/leaf named a brand identity
two rebases gone. brand unchanged (names what it does, not a colour).
All three real call sites updated (home-page.blade.php x2,
akun-index.blade.php x4 -- the latter previously relied on the implicit
default, now explicit, preserving today's rendered output exactly) plus
MkIconMedallionTest.php's four assertions. Old names no longer accepted
as a silent alias. PHPUnit not runnable on this host -- verified via
CI's PHP job after push, per docs/agents/issue-tracker.md's baseline
route."
```

- [ ] **Step 8: Push and confirm via CI**

```bash
git push -u origin feat/ffi-clone-icon-medallion-tone
```

Then read the resulting branch's real CI run for the "PHP (validate,
lint, analyse, test)" job — `gh run list --branch
feat/ffi-clone-icon-medallion-tone --limit 1 --json
databaseId,status,conclusion`, then `gh run watch <id> --exit-status` if
still running. This is the real, authoritative test result. Do not mark
this task complete until that job is confirmed green by reading its
actual output.
