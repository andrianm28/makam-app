# Brand Visual Refresh — Phase 2 (Homepage) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the Phase-1-built `<x-mk.hero>` primitive (real photography, no code yet exists to
use it) into the homepage's existing hero section, and verify — not rebuild — that the two other
Phase 2 deliverables the spec named (nav-as-cards, refreshed trust section) already landed as a
side effect of Phase 1's color re-anchoring plus a prior, separate 19 Aug 2026 homepage refresh.

**Architecture:** `resources/views/livewire/public/home-page.blade.php`'s "Section 2: Hero" block
(currently a plain `bg-primary-50` color band, no image) is replaced with `<x-mk.hero>`, passing a
real photo, the existing heading copy, and the existing dual-CTA pattern collapsed to `<x-mk.hero>`'s
single-CTA prop (design-system.md §2.3 already requires exactly one primary action; the current
band's secondary "Lihat TPU & TPS" button needs a new home — see Task 2). No other section's markup
changes. No new components, no new tokens, no schema changes.

**Tech Stack:** Laravel 13 Blade, the same pinned CI image and Playwright/PHPUnit suites already
used across this repo.

**Spec:** `docs/superpowers/specs/2026-08-21-brand-visual-refresh-design.md`

## Global Constraints

- Leaf (secondary) stays tint/accent-only — never a filled button, badge, or alert
  (design-system.md §1.2b).
- No hardcoded hex values anywhere outside `tokens.css` (`ci/verify-docs.sh` Gates 1-2).
- Exactly one primary action per view/section (design-system.md §2.3).
- Hero imagery: real cemeteries/gardens, daylight, no people in grief (design-system.md §2.2);
  decorative only, empty `alt` (already `<x-mk.hero>`'s own convention).
- `ci/verify-docs.sh`, `design:verify-filament-palette`, and the full `tests/browser/*.spec.ts`
  suite must stay green after this phase — a pure hero-swap must not touch any other page's
  accessible name, route, or focus order.

## Real starting state, verified 25 Aug 2026 (do not re-derive)

Read directly from `origin/docs/design-system-and-planning`'s current tip (post Phase 1 merge,
PR #141) before writing this plan — not assumed from the spec's own text, which was written before
Phase 1 existed and before the 19 Aug 2026 homepage refresh's actual scope was known:

- **`<x-mk.hero>` exists** (`resources/views/components/mk/hero.blade.php`, merged in PR #141) but
  is **wired into zero real pages**. Props: `image` (string path, optional — no `@required` enforced
  despite its own doc comment saying "required"; a null image just skips the `<img>` — do not "fix"
  this discrepancy in this plan, it is out of scope and not a bug that blocks anything here),
  `heading` (string, **enforced** required — throws `InvalidArgumentException` if null), `cta`
  (array `['label' => string, 'href' => string]`, optional, renders one `<x-mk.button variant="primary" size="lg">`).
  Default slot renders between heading and CTA. Root class: `relative overflow-hidden rounded-lg`;
  inner content wrapper: `flex flex-col gap-4 bg-primary-50 p-6 md:p-8`; image (when present):
  `h-64 w-full object-cover md:h-96`.
- **Section 3 (four service-nav cards) is ALREADY a `<x-mk.card as="a" interactive>` grid** —
  shipped by `docs/superpowers/plans/2026-08-19-homepage-visual-refresh.md`, a prior, unrelated
  plan, before this brand-refresh spec (21 Aug) was even written. The spec's own §4.2 "what's new"
  claim ("Primary navigation as a card grid... reusing `<x-mk.card interactive as='a'>`") describes
  work that was already done. **No code change needed for this deliverable.**
- **Section 6 (trust/safety) already uses the re-anchored tint tokens** —
  `<section class="bg-primary-50 ...">` with `<x-mk.icon-medallion tone="leaf">` per point. Since
  Phase 1 changed `--color-primary-*`/`--color-secondary-*`'s actual hex values in `tokens.css`
  (not the class names referencing them), this section's rendered color **already changed** the
  moment Phase 1 merged — no code change needed for this deliverable either. Verify only (Task 2).
- **Section 2 (hero) is the one real gap.** Currently: `<section class="bg-primary-50 py-8 lg:py-12">`
  with an eyebrow label, a decorative secondary-accent rule, an `<h1>` (`text-4xl font-semibold
  tracking-tight text-neutral-900 lg:text-5xl` — hand-written, duplicating what `<x-mk.hero>` now
  provides as a prop), body copy, and a **dual CTA**: `<x-mk.button variant="primary" size="lg"
  href="/pemesanan-makam">Pesan Makam</x-mk.button>` + `<x-mk.button variant="secondary" size="lg"
  :href="route('cemeteries.index')">Lihat TPU & Makam</x-mk.button>`. No image anywhere in this
  section today.
- **No real photograph of any specific named cemetery exists or is planned to exist** in this
  codebase, by deliberate design — read `database/migrations/2026_08_24_100000_backfill_photo_and_maps_url_for_real_cemeteries.php`'s
  own doc block: the 4 real, named DKI Jakarta cemeteries currently live use **generic abstract SVG
  illustrations** (`public/images/cemeteries/illustration-0{1,2,3,4}-*.svg`) specifically *because*
  a real photo of one specific real cemetery risks being misattributed to a different one — "a real
  accuracy problem on a live public site, not just a licensing question." This precedent directly
  informs the photo-sourcing options in Task 1 below: whatever image is chosen for the hero must not
  visually claim to depict one specific, identifiable real cemetery unless it verifiably is that
  cemetery.
- `HomePage::PRIMARY_MENUS` (referenced by Section 3) and `HomePageRouteTest` (asserts
  `assertSee('Pesan Makam')` / `assertSee('href="/pemesanan-makam"')` on the current hero markup)
  are the two things most likely to need a touch when Section 2's markup changes — read both before
  Task 1.

---

### Task 1: Apply `<x-mk.hero>` to the homepage — BLOCKED on a real decision

**This task cannot be implemented until the project owner picks a photo source.** The spec's own
§7 explicitly reserves this decision ("exact photo sourcing... is an implementation-time decision,
not a design-spec decision") and no one but the project owner can make a licensing/budget/brand-fit
call. Everything else in this plan (Task 2, Task 3) is fully executable without waiting on this.

**Options, weighed against the real misattribution precedent found above:**

1. **A genuinely generic, non-identifiable daylight photo — royalty-free stock, filtered to avoid
   any recognizable landmark.** E.g. a search on Unsplash/Pexels (both free for commercial use, no
   attribution required under their standard license) for terms like "cemetery pathway trees
   daylight" or "memorial garden greenery" — deliberately choosing a wide, landscape-style shot with
   no legible headstone names, no distinctive gate/monument architecture, and no country-specific
   iconography, so it reads as "a real cemetery/garden" per design-system.md §2.2 without claiming
   to BE any specific one (sidesteps the misattribution risk entirely, fastest and zero-cost option).
2. **Commissioned photography of one of the 4 real, already-named cemeteries** (TPU Karet Bivak,
   Petamburan, Pondok Kelapa, Semper/Budi Dharma) — the most "real" option and the one that most
   literally matches "real cemeteries/gardens," but requires an actual photographer, a site visit,
   and explicit confirmation that publishing a real photo of a real government TPU on a commercial
   platform's homepage needs no additional permission (this project's own established caution about
   real, named third parties suggests checking this, not assuming it). Slowest, real cost, but the
   most literal fulfillment of the spec's intent.
3. **Keep the existing abstract SVG illustration convention, applied to the hero instead of a
   photograph.** `<x-mk.hero>`'s `image` prop is optional/untyped as to format — an SVG illustration
   path would work exactly the same as a JPEG. This directly reuses the precedent already
   established as safe (no misattribution risk, matches the tone already used elsewhere on the same
   page — the TPU/TPS unggulan section's cards already show these same illustrations). Cheapest,
   zero new-decision risk, but arguably undercuts the spec's own stated goal of finally using real
   photography (§4.2: "No public page currently uses real cemetery/garden photography... it's
   simply unused so far") — this option keeps that true.

Once a decision is made, the remaining implementation is small and mechanical:

**Files:**
- Modify: `resources/views/livewire/public/home-page.blade.php` (Section 2 block, lines 131-160 as
  of this plan's writing — re-locate by the `{{-- Section 2: Hero` comment marker, not the literal
  line numbers, since Task 2's verification pass may shift them slightly)
- Modify: `tests/Feature/Livewire/Public/HomePageRouteTest.php` (or wherever
  `test_pemesanan_makam_is_the_primary_call_to_action` actually lives — confirm the real path, the
  spec-writing pass above found the assertion text but not its containing file's exact location)
- Test: same file, extend rather than replace

**Interfaces:**
- Consumes: `<x-mk.hero>` (`image`, `heading`, `cta` props — see "Real starting state" above for the
  exact signature).
- Produces: nothing new for later tasks to consume — this is the terminal task for the hero swap.

- [ ] **Step 1: Write/extend the failing test first**

Add an assertion to the existing homepage route test proving the new hero renders via the real
component (not just that the old text still appears) — e.g. assert the rendered HTML contains the
`<x-mk.hero>` root's distinguishing class (`relative overflow-hidden rounded-lg`) AND still contains
`Pesan Makam` / `href="/pemesanan-makam"` per the existing assertion. If an image path is chosen
(options 1 or 2), also assert an `<img>` tag is present; if option 3 (SVG, no real photo), assert
the SVG's path renders instead.

```php
public function test_hero_section_uses_the_mk_hero_component_with_a_real_image(): void
{
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('relative overflow-hidden rounded-lg', false);
    $response->assertSee('Pesan Makam');
    $response->assertSee('href="/pemesanan-makam"', false);
}
```

- [ ] **Step 2: Run it, confirm it fails** (the current Section 2 markup has no `<x-mk.hero>` root class)

Run: `vendor/bin/phpunit --filter test_hero_section_uses_the_mk_hero_component_with_a_real_image`
Expected: FAIL — assertion on `relative overflow-hidden rounded-lg` not found in response body.

- [ ] **Step 3: Replace Section 2's markup**

Replace the current hand-written `<section aria-labelledby="hero-heading" ...>` block with:

```blade
{{-- Section 2: Hero — <x-mk.hero> (brand visual refresh Phase 2,
     docs/superpowers/specs/2026-08-21-brand-visual-refresh-design.md §4.2).
     Single primary CTA per §2.3; the prior secondary "Lihat TPU & TPS"
     button moved into Section 3's card grid area as a plain text link
     below the cards (see Task 1 Step 4) rather than competing with the
     hero's one sanctioned primary action. --}}
<x-mk.hero
    image="{{ /* exact path from the chosen Task 1 option — asset('images/hero/...') or Storage::url(...) */ '' }}"
    heading="Urus Pemakaman dengan Tenang, dalam Satu Platform"
    :cta="['label' => 'Pesan Makam', 'href' => '/pemesanan-makam']"
>
    <p class="text-base text-neutral-600 md:text-lg">
        Pesan makam, jelajahi layanan pemakaman, dan urus perpanjangan masa sewa makam secara online. Setiap
        langkah tercatat jelas, dari pemesanan hingga konfirmasi.
    </p>
</x-mk.hero>
```

The eyebrow label (`Makam.co.id`) and secondary-accent rule (`bg-secondary-400` decorative bar) are
dropped, not relocated — `<x-mk.hero>` has no slot for them, and design-system.md's own hero
typography row does not call for an eyebrow; keep the plan honest that this is a deliberate small
copy simplification, not an oversight, and flag it for the task-scoped reviewer to confirm is
acceptable rather than silently deciding it is.

- [ ] **Step 4: Relocate the secondary "Lihat TPU & TPS" action**

`<x-mk.hero>` has no second-CTA slot (by design — §2.3's one-primary-action rule). Add it as a plain
text link immediately below Section 3's card grid (inside the same `<section aria-labelledby="services-heading">`,
after the closing `</ul>`):

```blade
<p class="mt-4 text-center text-sm text-neutral-600">
    <a href="{{ route('cemeteries.index') }}" class="font-medium text-primary-700 underline underline-offset-2">
        Lihat semua TPU &amp; TPS
    </a>
</p>
```

- [ ] **Step 5: Run the test, confirm it passes**

Run: `vendor/bin/phpunit --filter test_hero_section_uses_the_mk_hero_component_with_a_real_image`
Expected: PASS.

- [ ] **Step 6: Run the FULL existing homepage test file**, confirm nothing else broke (the dropped
  eyebrow/secondary-accent markup and relocated CTA are exactly the kind of change that could break
  an assertion elsewhere in the same file).

Run: `vendor/bin/phpunit --filter HomePageRouteTest` (confirm the real class name first)
Expected: PASS, all tests.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/public/home-page.blade.php tests/Feature/Livewire/Public/HomePageRouteTest.php
git commit -m "feat(design): apply <x-mk.hero> to the homepage (Phase 2 brand refresh)"
```

---

### Task 2: Verify (not rebuild) Sections 3 and 6 already reflect the Phase 1 palette

**Files:** none modified — this is a verification task with a real deliverable (evidence, not code).

- [ ] **Step 1: Real browser check against `dev.makam.co.id`** (per the spec's own §6 "Manual visual
  check" requirement — never a raw container port) once Task 1's build is deployed there. Confirm:
  Section 3's card icon-medallions (`tone="earth"`) and Section 6's (`tone="leaf"`) visibly render
  the NEW re-anchored colors (`#563B26`-family brown / `#336B3E`-family green), not the old
  provisional ones. Screenshot both sections.
- [ ] **Step 2: Run `bash ci/verify-docs.sh`** — confirms no hardcoded hex crept in anywhere during
  Task 1, and that the existing WCAG AA gate still passes for every pair Section 2's new markup
  introduces (the `<x-mk.hero>` component's own `bg-primary-50` + `text-neutral-900` pairing was
  already verified accessible when Phase 1 built the component — this step re-confirms it still
  holds against the specific heading text used here, not a generic string).
- [ ] **Step 3: Run `php artisan design:verify-filament-palette`** — confirms Task 1 introduced no
  drift (it shouldn't; Task 1 touches Blade markup only, not `tokens.css`).
- [ ] **Step 4: Record the result** in this plan's own ledger (when executed via
  `subagent-driven-development`) or directly in the PR description: "Sections 3 and 6 confirmed
  visually reflecting the re-anchored palette with zero code changes, per Phase 1's token-based
  architecture."

---

### Task 3: Full regression, E2E, and a11y re-verification

**Files:** none modified.

- [ ] **Step 1: Run the full `tests/Feature/` suite** relevant to the homepage and shared layout —
  at minimum `HomePageRouteTest` (full file) and anything asserting `layouts/app.blade.php`'s shared
  header/footer, against real Postgres 18 + Redis 8.2 via the pinned CI image (this repo's
  established test-execution convention — fresh disposable containers, never the live
  `makam-nonprod-*` ones).
- [ ] **Step 2: Run the full `tests/browser/*.spec.ts` E2E suite**, not just a homepage-scoped
  subset — the spec's own §6 requires this because a hover-state or focus-order change on one page
  can incidentally affect an unrelated flow that happens to share layout markup.
- [ ] **Step 3: Run an `AxeBuilder` a11y scan** against the real rendered homepage (Task 1's new
  hero image needs its own scan pass — an `<img>` with empty alt on a decorative image is correct
  per design-system.md §2.2, but must be verified, not assumed, the same discipline this session
  has applied to every other new page state).
- [ ] **Step 4: `vendor/bin/pint --test`** on every file this phase touched.
- [ ] **Step 5: Commit any fixes found, or confirm clean and move to PR.**

---

## Self-review (spec coverage, placeholder scan, consistency)

- **Spec coverage**: §4.2's three named Phase 2 deliverables (photo hero, nav-as-cards, trust
  refresh) are all accounted for — one real task (Task 1), two verification-only confirmations
  (Task 2) that the other two already shipped via Phase 1 + the prior 19 Aug refresh. §7's "photo
  sourcing" open item is the one genuine blocker, surfaced with three concrete, precedent-informed
  options rather than left as an unstated assumption.
- **Placeholder scan**: Task 1 Step 3's `image="{{ ... }}"` line intentionally contains a real PHP
  comment marking where the CHOSEN option's real path goes — this is not a stray TODO, it is the one
  value that cannot be filled in until the blocking decision above resolves; every other step has
  concrete, real code.
- **Type/signature consistency**: `<x-mk.hero>`'s three props (`image`, `heading`, `cta`) are used
  identically in Task 1 Step 3 to how "Real starting state" documents them, verified against the
  actual merged component source, not the spec's earlier description of it.

## Execution

Per this repo's established convention this session, execute via `superpowers:subagent-driven-development`
once Task 1's photo-sourcing decision is made — Task 2 and Task 3 can begin immediately after Task 1
lands, no further blocking. Human review is not specially required for this phase beyond the
project's normal PR review (no security/financial/authorization/DNS/destructive-migration content),
though the visual result itself still needs the project owner's own look before merging, per the
spec's own explicit framing of this whole effort as taste-driven, not just technically correct.
