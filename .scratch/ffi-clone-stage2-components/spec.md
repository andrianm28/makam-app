## Problem Statement

Stage 1 of the FFI visual clone rebased every colour token to FFI's values,
but the component library that renders those tokens hasn't caught up in
two ways. First, two pieces of FFI's own visual language have no Makam
equivalent at all: FFI shows a skeleton-loading placeholder while content
streams in, and a persistent bottom tab bar on mobile — Makam has neither
today, so those two patterns of the reference site are simply absent from
Makam's mobile experience. Second, one existing component's public API
still names its options after the *superseded* brand ("earth", "leaf")
even though those options now resolve to entirely different colours after
Stage 1 — a caller reading the component's own prop values can no longer
tell what colour they're actually going to get.

## Solution

Build the two missing components — `<x-mk.skeleton>` and
`<x-mk.bottom-nav>` — as new primitives in the existing `<x-mk.*>` library,
following the exact conventions the other 18 primitives already establish
(prop-driven class composition, `$attributes->merge()`, token-only
colour/spacing references, no invented values). Confirm that the existing
primitives which already reference `tokens.css`'s primitive-family
Tailwind classes (`<x-mk.button>`, `<x-mk.card>`, `<x-mk.icon-medallion>`,
and the other 15) need no code change — Stage 1's rebase already reached
them automatically, since none of them hardcode a colour value outside a
token reference. Resolve `<x-mk.icon-medallion>`'s stale `tone` prop
naming so its public API no longer describes a colour that isn't there
anymore.

## User Stories

1. As a mobile visitor on a slow connection, I want to see a skeleton
   placeholder while a section of the page streams in, so that the layout
   doesn't jump and I know something is loading rather than broken.
2. As a screen-reader user, I want a skeleton placeholder to announce that
   content is loading (not silently render nothing meaningful), so that I
   know to wait rather than assume the page failed.
3. As a visitor who has `prefers-reduced-motion` set, I want the skeleton
   to show its base colour without a pulsing animation, so that the
   loading state doesn't trigger motion sensitivity.
4. As a mobile visitor browsing on a small screen, I want a persistent
   bottom tab bar for the five main sections (Beranda, Pemesanan,
   Perpanjangan, Akun, Bantuan), so that I can jump between them without
   scrolling back to a header nav that isn't in view.
5. As a mobile visitor using a screen reader, I want the bottom nav
   wrapped in a labelled landmark with the current section marked
   `aria-current="page"`, so that assistive technology announces where I
   am and what else is reachable.
6. As a visitor with a colour-vision deficiency, I want the bottom nav's
   active tab marked by shape as well as colour, so that I can tell which
   tab is active without relying on colour alone.
7. As a desktop visitor, I want the bottom nav to not appear at all above
   the `lg` breakpoint, so that it doesn't duplicate the header nav on a
   screen where there's no scrolling-back problem to solve.
8. As a visitor partway through the booking wizard on mobile, I want the
   bottom nav to never sit on top of the wizard's own primary action
   button, so that I can always reach "Lanjutkan"/"Bayar" without the nav
   blocking it.
9. As an engineer calling `<x-mk.icon-medallion tone="...">`, I want the
   prop's accepted values to describe what colour I'm choosing (not a
   brand name two rebases out of date), so that I can read a call site and
   know what it renders without cross-referencing `tokens.css`.
10. As an engineer maintaining `MkIconMedallionTest`, I want the existing
    test file's assertions to keep passing (or be deliberately, visibly
    updated) if the `tone` prop's accepted values change, so that a rename
    doesn't silently break coverage.
11. As the codebase's provenance-comment convention, I want every new
    component's colour/spacing reference to trace to a named `tokens.css`
    token in a comment, the same way `button.blade.php`'s own file-header
    comment already mandates for the primitives that follow it, so that
    nothing added in this stage becomes the exception nobody can explain.
12. As `ci/verify-docs.sh`'s GATE 2/3, I want every new component file to
    contain zero hardcoded hex/px/arbitrary-Tailwind values, so that this
    stage doesn't need a special exemption from a rule every other
    component already follows.
13. As the reviewer of this stage's PR, I want it scoped to exactly
    "Stage 2: Components" per the approved design doc's three-stage
    sequencing, so that homepage restructure (Stage 3) isn't bundled in.
14. As a future engineer reaching for `<x-mk.skeleton>` on a new page, I
    want its `shape` prop's four options (`text`, `card`, `media`,
    `section`) to cover the loading shapes this codebase's screens
    actually need, so that I'm not inventing a fifth ad hoc pattern.
15. As `AGENTS.md`'s "ten mandatory screen states" rule
    (`design-system.md` §6), I want `<x-mk.skeleton>` to be the structural
    building block every screen's "loading" state can reach for, so that
    this stage moves the codebase toward, not away from, that requirement
    (wiring it into specific screens is Stage 3/later work, not this
    stage's).

## Implementation Decisions

- **`<x-mk.skeleton>` (new).** Props: `shape` (`text`\|`card`\|`media`\|
  `section`, default `text`), `lines` (int, default 3, `text` shape
  only), `count` (int, default 1), `section-rhythm` (bool, `section`
  shape only, mandatory `true` for that shape), `announce` (string,
  default `"Memuat…"`). Always emits `aria-busy="true"` on its root
  element and exactly one `sr-only` node carrying `announce`. Colours are
  always `--mk-skeleton-base`/`--mk-skeleton-sheen` (both already defined
  in `tokens.css`, resolving to `neutral-200`/`neutral-100`) — no external
  colour prop, matching how no other `<x-mk.*>` primitive accepts a raw
  colour override. Respects `prefers-reduced-motion`: when set, no pulse
  animation runs, the component renders its base colour statically — the
  same reduced-motion convention `tokens.css`'s existing shadow rules
  already use (confirmed precedent, not a new pattern for this component
  to invent).
- **`<x-mk.bottom-nav>` (new).** Exactly five tabs, fixed set, not
  slot-driven or prop-configurable: Beranda (`/`), Pemesanan
  (`/pemesanan-makam`), Perpanjangan (`/perpanjangan`), Akun (`/akun`),
  Bantuan (`/bantuan`). Visible only below the `lg` breakpoint (matches
  `tokens.css`'s existing `--breakpoint-lg: 64rem` — desktop nav replaces
  the pattern this component exists for). Active tab marked by colour
  **and** shape — never colour alone, per `design-system.md` §7 (colour
  cannot be the only signal). No animation library; the active-state
  transition uses colour/opacity only, through the existing
  `--mk-duration-fast` (120ms) token already used elsewhere.
  `aria-current="page"` on the active tab's anchor; the whole component
  wrapped in `<nav aria-label="Navigasi utama">`. Stacks via the existing
  `--mk-z-bottomnav: 1100` token (already defined, currently unconsumed),
  which sits above `--mk-z-sticky-cta: 900` (also already defined, its own
  comment names it "wizard sticky footer") and below `--mk-z-header:
  1200`.
- **Coexistence with the wizard's sticky footer is an open layout
  question, not a solved one.** `--mk-z-sticky-cta` exists and is
  commented as backing a wizard sticky footer, but no current Blade file
  under `resources/views/livewire/public/booking/` renders a
  bottom-fixed/sticky CTA bar today — repo-wide search found no literal
  usage of that token outside its own definition. Two possibilities the
  implementation plan must settle, not this spec: either that sticky
  footer doesn't exist yet (in which case `<x-mk.bottom-nav>` is the
  first consumer of `--mk-z-sticky-cta`'s stacking context and there is
  no real collision today, only a future one to design for), or it exists
  under a name/pattern this search didn't match. The plan must verify
  which is true before deciding whether "which yields" is a real
  right-now layout problem or a documented constraint for whichever
  component builds the wizard's sticky footer next.
- **`<x-mk.card>`'s `emphasis` prop and `<x-mk.button>`'s `variant` prop
  need no code change.** Confirmed by reading both files: every colour
  class either resolves through a Tailwind class generated from a
  `tokens.css` primitive (`bg-primary-600`, `border-neutral-450`,
  `shadow-sm`/`shadow-md`) or a semantic `--mk-*` alias. Stage 1's value
  rebase already reached both components the moment it merged — there is
  no Stage 2 task for either file. The same holds for every other
  existing `<x-mk.*>` primitive checked (`icon-medallion.blade.php`
  itself is the one exception below).
- **`<x-mk.icon-medallion>`'s `tone` prop currently accepts `earth` and
  `leaf`** (`'earth' => 'bg-primary-100 text-primary-800'`, `'leaf' =>
  'bg-secondary-100 text-secondary-800'`), names inherited from the
  brand identity two rebases ago (Earth brown, Leaf green — both gone
  since ADR-0034 was superseded, and `primary`/`secondary` mean FFI blue
  and Sage respectively today). This is a real, unresolved naming
  decision for this stage's own grilling, not something this spec settles
  by itself: whether to rename the accepted values to something
  palette-agnostic (e.g. `primary`/`secondary`, matching the token family
  names they already resolve to), keep them and accept the mismatch, or
  something else. Whatever is decided, `tests/Feature/View/Components/
  MkIconMedallionTest.php` (existing, passing, uses `tone="earth"` in
  every one of its assertions) is the test that must be updated in
  lock-step — a silent rename would leave it calling a value that no
  longer means what its own test name says.
- Token and semantic-alias **names** are unchanged throughout, same
  discipline Stage 1 held — this stage only adds two new components and
  possibly renames one existing prop's *accepted values*, never a
  `tokens.css` custom property name.
- Every new component file's provenance/rationale is documented in a
  file-header comment, matching `button.blade.php`'s own stated
  convention ("read this file... before building another one").

## Testing Decisions

- **Seam: `Illuminate\Support\Facades\Blade::render('<x-mk.foo ... />')`**,
  rendering each new component directly and asserting against the
  returned HTML string. This is not a new pattern — it is the exact,
  already-established seam `tests/Feature/View/Components/MkCardTest.php`
  and `tests/Feature/View/Components/MkIconMedallionTest.php` already use
  for the existing primitives, confirmed by reading both files directly.
  `<x-mk.skeleton>` and `<x-mk.bottom-nav>` get their own new test files
  in the same directory, following the same naming convention
  (`MkSkeletonTest.php`, `MkBottomNavTest.php`).
- **What makes a good test here**: assert on the rendered class strings
  and attributes that encode the actual contract (a Tailwind utility
  class present/absent, `aria-busy`/`aria-current`/`aria-label` present
  with the right value, the `sr-only` announce text present) — not on
  incidental whitespace or element nesting that isn't part of the
  contract. `MkIconMedallionTest`'s own tests are the direct prior art:
  they assert specific size classes present and specific ones absent
  (`assertStringContainsString`/`assertStringNotContainsString`), which
  is the same shape this stage's new tests should take.
- **If `<x-mk.icon-medallion>`'s `tone` values are renamed**: the seam is
  the same `Blade::render()` call — update `MkIconMedallionTest.php`'s
  existing assertions to the new accepted value(s) rather than leaving
  the old ones passing by coincidence.
- **No new Livewire/page-level Feature test seam** — confirmed with the
  project owner: the sticky-CTA/bottom-nav coexistence question (open
  above) is a layout/design decision for the implementation plan to work
  through, not a reason to add a page-level rendering test in this spec.
  If the plan's own investigation finds a real, already-built sticky
  footer this search missed, the plan should reconsider this seam
  decision explicitly rather than silently proceeding on this spec's
  assumption.
- **Prior art**: `MkCardTest.php` and `MkIconMedallionTest.php`, both
  read in full during this spec's preparation, are the templates for
  every new test file this stage adds.

## Out of Scope

- **Stage 3 — Homepage restructure**: section reorder, secondary-CTA
  placement, trust-element repositioning, and applying this stage's
  components to actual pages. Own spec/plan. `<x-mk.bottom-nav>` and
  `<x-mk.skeleton>` are built here but not wired into any real screen by
  this stage — that wiring is Stage 3's job, per the design doc's own
  sequencing (§5: "Components... to the values Stage 1 established" is
  this stage; screens that consume them are the next one).
- **Any colour/typography token value** — Stage 1 is closed and
  already merged; this stage touches zero entries in `tokens.css` beyond,
  possibly, consuming the two already-defined but currently-unconsumed
  `--mk-z-bottomnav`/`--mk-skeleton-*` tokens (no new token, no value
  change).
- **Filament admin/operator/vendor panels** — untouched, same boundary
  Stage 1 held.
- **The wizard's own sticky-footer component**, if it doesn't already
  exist — building it is not this stage's job even if the investigation
  above finds it's missing; this stage only has to make
  `<x-mk.bottom-nav>` not collide with it if and when it exists.
- **The other 17 existing `<x-mk.*>` primitives beyond `icon-medallion`**
  — confirmed to need no code change; not touched by this stage's plan.
- **Booking wizard / renewal / marketplace step structure** — unchanged,
  same boundary Stage 1 held.
- **Copy and voice** — unchanged.
- **The four-primary-service-card rule** — not touched.

## Further Notes

- **Upstream authority chain**: the approved
  `docs/superpowers/specs/2026-09-22-ffi-full-visual-clone-design.md` §3
  (Component library) and §5 (three-stage sequencing, this spec covers
  Stage 2). ADR-0043 remains the authority for the underlying rebase this
  stage builds on.
- This spec was produced via `/specflow:to-spec`, synthesizing the
  design doc's already-approved §3 content plus fresh exploration of the
  actual repo state (not re-litigating the design doc's own decisions).
  The exploration's two most consequential findings — that 17 of 18
  existing primitives need zero code change, and that
  `icon-medallion`'s `tone` prop naming is now stale — were not visible
  from the design doc alone and are the reason this spec's scope differs
  slightly from a literal reading of §3's three bullet points.
- The seam for this stage (`Blade::render()`, component-isolated) was
  confirmed with the project owner before this spec was written, per
  `/specflow:to-spec`'s own required step.
- **Genuinely open, deliberately left for the implementation plan**:
  (1) whether the wizard sticky footer this spec searched for and
  didn't find actually exists elsewhere or doesn't exist yet — the plan
  must verify this before finalizing `<x-mk.bottom-nav>`'s layout
  behaviour; (2) the exact new `tone` prop values for
  `<x-mk.icon-medallion>`, if renamed — this spec identifies the problem
  and names the one test file that must move with it, but the specific
  replacement vocabulary is a naming decision for the plan's own grilling,
  not settled here.
