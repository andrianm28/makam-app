## Problem Statement

The project owner wants Makam.co.id's visual system to look exactly like
`fundforindonesia.org` ("FFI") — confirmed twice: once before learning FFI's
codebase is an unrebranded clone of the real company Kitabisa, and again
after being told that directly (ADR-0043). Right now Makam runs a completely
different colour system and typeface (Forest brown / Sage green / Sand
orange, Plus Jakarta Sans — ADR-0034/0041, the 2026 Brand Guideline). Nothing
in today's palette or type is what the owner sees when they compare the two
sites side by side. Every later piece of FFI-alike visual work — components,
the homepage restructure — inherits its colours and type from the design
token file, so none of it can look right until the foundation itself is
rebased.

## Solution

Rebase every value in the design token file's `primary` / `secondary` /
`accent` / `neutral` / `success` / `warning` / `danger` / `info` colour
families to FFI's actual palette, read directly from FFI's
`tailwind.config.ts` — not a naive single-hex swap, but the same pivot-slot
ramp-generation methodology the current brand system already uses (Sage
anchored at 300, Sand anchored at 200; each FFI anchor lands at whichever
ramp slot its luminance actually matches, and the rest of the 11-stop ramp
is computed on the same lightness-position curve). Swap the self-hosted
typeface from Plus Jakarta Sans to Inter. Re-verify the whole palette against
WCAG AA with the project's existing contrast-verification tool
(`docs/design/verify-contrast.py`), and fix every regression it reports with
real, tool-computed values. Token and semantic-alias *names* stay exactly as
they are, so this stage touches zero Blade/Livewire/Filament consumer code.

## User Stories

1. As the project owner, I want Makam's colour system to read as FFI's when
   placed side by side, so that the site matches the visual reference I
   asked for even after learning where FFI's own code came from.
2. As the project owner, I want to be told plainly when a literal FFI value
   can't be used as-is (e.g. it would fail contrast or collide with another
   family's hue), so that I'm not shown a "match" that silently breaks
   accessibility.
3. As a low-vision or screen-reader-adjacent visitor, I want every
   text/surface and non-text/surface pair on the rebased palette to still
   clear WCAG 2.1 AA, so that the FFI-alike look doesn't cost me legibility.
4. As a visitor comparing Makam and FFI, I want the same colour family used
   consistently for the same semantic role (primary action, danger, success,
   informational banner), so that the sites feel like the same visual
   system, not a partial reskin.
5. As an engineer implementing Stage 2 (component library:
   `<x-mk.skeleton>`, `<x-mk.bottom-nav>`, card/button/icon-medallion
   values), I want the rebased token *values* to already be correct and
   verified, so that Stage 2's own plan can reference the token file
   directly instead of re-deriving colour math.
6. As an engineer implementing Stage 3 (homepage restructure), I want the
   same guarantee — the palette is settled before any structural work
   begins.
7. As a future agent re-running the contrast-verification tool, I want its
   asserted-pairs list to reference the token names that are actually true
   after this rebase, so that the tool's own self-consistency check doesn't
   report a stale "mispointed pair" false alarm — or worse, silently test
   the wrong hex.
8. As an operator viewing a printed invoice/kwitansi/agreement/certificate,
   I want the print-media override block to keep resolving to real, working
   values after the rebase, so that print output isn't silently broken by a
   token this stage renamed or removed (it doesn't rename or remove any —
   this story exists to keep that constraint explicit).
9. As the Filament admin/operator/vendor panel, I want to be completely
   unaffected by this stage, so that its own palette-verification command
   stays green without anyone touching it on purpose or by accident.
10. As the codebase's MVP-scope rule (`AGENTS.md`, four-card services
    section), I want this purely-visual rebase to not quietly change how
    many service cards exist or what they mean, so that a colour-system PR
    never becomes a scope-creep vector for product decisions.
11. As a component that currently sits on the "warm surface" semantic
    alias (e.g. a border or focus ring), I want that surface's new
    orange-family value to still give me enough contrast to render
    visibly, so that a colour swap doesn't make an already-shipped UI
    element disappear.
12. As the `info` colour family (no direct FFI equivalent — FFI has no
    "info" role in its own palette), I want a resolution that keeps me
    visually distinct from the new `primary` blue, so that a gated-fallback
    banner isn't mistaken for a primary action or vice versa.
13. As the neutral family's "100" shade (not an FFI-literal value, unlike
    its "0"/"50"/"800" siblings), I want to be free to move by a small
    amount if a sibling family's own fix needs the headroom, so that the
    rebase isn't forced into a worse compromise on a token that genuinely
    is FFI-literal.
14. As the reviewer of this PR, I want it scoped to exactly "Stage 1:
    Foundation" per the approved design doc's three-stage sequencing, so
    that I'm reviewing one coherent, mechanically-verifiable change and not
    a bundle that also includes component or homepage work.
15. As a developer six months from now reading the token file's provenance
    comments, I want every changed value's comment to cite ADR-0043 (not
    the superseded ADR-0034/0041 language) and to explain *why* a value
    isn't FFI's literal number where that's the case, so that the "why"
    trail this repo's convention protects doesn't go stale the moment this
    PR merges.

## Implementation Decisions

- **Ramp-generation methodology.** Pivot-slot generalisation of the
  existing `docs/design/brand/generate-ramp.php`: each FFI anchor hex is
  placed at whichever ramp slot its luminance actually matches (not always
  600 — same precedent as Sage at 300 and Sand at 200), and the remaining
  10 stops of that family are computed on the same lightness-position curve
  `generate-ramp.php` already uses. This is a scratch-tool generalisation
  (arbitrary pivot slot instead of a hardcoded 600) produced and used
  during this spec's preparation; it is not yet a committed repo file — the
  plan should decide whether to commit it under `docs/design/brand/`
  alongside the original, since it is now the documented methodology for
  this and any future rebase.
- **`primary`** rebased to FFI's blue family (hue 210°): anchor `#0073E6`
  lands at slot 500, hover/dark variant `#005BB5` at slot 600 (the
  white-label fill slot). Replaces "Forest" brown (ADR-0034/0041,
  superseded by ADR-0043).
- **`accent`** rebased to FFI's orange family, anchor `#FF6B35`. Replaces
  "Sand".
- **`success`** rebased to FFI's green, anchor `#00C853`.
- **`warning`** rebased to FFI's amber, anchor `#FFB300`.
- **`danger`** rebased to FFI's red, anchor `#D50000`.
- **`neutral-0` (`#FFFFFF`) and `neutral-50` (`#F5F5F5`, "Ivory")** are kept
  as FFI's literal background/background-secondary values, unmodified,
  verbatim — same treatment ADR-0041 already gave Sage/Sand. The rest of
  the neutral ramp (100–950) is regenerated on the same curve;
  `neutral-800` remains the literal FFI text colour.
- **`info`** has no direct FFI anchor — FFI's own palette has no "info"
  role. Resolution: keep Makam's existing info hue family, but **rotate its
  hue 227.6° → 246°**, holding saturation and lightness constant per shade.
  This clears the new `primary` (210°) by 36°, `success` (145°) by 101°,
  and `danger` (0°) by 114° — all comfortably past the 30° minimum
  hue-separation rule the verification tool enforces. Final ramp
  (50→950): `#F2F1FC, #E2E0F8, #C8C3F1, #A39CE5, #7B71D5, #594DC0,
  #443A9B, #39317F, #2F2968, #292455, #15122C`.
- **`secondary`** ("Sage" green) is kept as Makam's own family — FFI has no
  third surface-tint colour to map it to at this stage. `secondary-50` is
  nudged `#F4F6F5` → `#F0F2F1` (hue/sat held, lightness only) to restore
  ≥10/765 RGB surface-separation from the new `neutral-50`, which the
  rebase pushed uncomfortably close (was 2/765 apart).
- **`neutral-100`** nudged `#EEEEEE` → `#ECECEC` (2 units per channel) as a
  direct knock-on of the `secondary-50` fix above: `secondary-50`'s new
  value closed to 9/765 of `neutral-100`, itself now under the floor.
  `neutral-100` is not an FFI-literal value (only the family's "0"/"50"/
  "800" are), so it is free to move; 2 units per channel is visually
  imperceptible. The disabled-text colour on the new `neutral-100`
  verifies at 3.90:1, still clearing the 3.0:1 non-text floor.
- **The "warm surface" semantic alias** is repointed from the accent
  family's 100 slot to its 50 slot. Once `accent` became FFI's orange, the
  interactive-border colour on the 100 slot fell to 2.89:1 against the
  WCAG 1.4.11 3.0:1 non-text floor; the 50 slot clears it at 3.41:1
  (re-verified against the fully rebased ramp at 3.17:1 once the
  verification tool's own list was corrected — see next point).
- **The contrast-verification tool's own asserted-pairs list must be
  edited in this same PR.** Its three "on warm surface" entries currently
  reference the accent family's 100-slot token name; once the semantic
  alias is repointed to the 50 slot, those three entries must be repointed
  to match, or the tool's built-in self-consistency check reports them as
  a mispointed pair — this is the tool correctly catching exactly this
  class of drift (its own code comments confirm this is intentional, added
  after a prior incident where the alias moved and the pairs list silently
  didn't), not a false positive to work around.
- **Typography**: Plus Jakarta Sans → **Inter**, self-hosted via the same
  fontsource-variable-style import already used (the CSS entry point's
  single `@import` line), no external `<link>` — preserving the existing
  "no third-party font request" privacy rule documented in that file's own
  comment block.
- **Radius and shadow scale need no value change** — already numerically
  identical to FFI's own tiers (8/12/16px radius; FFI's card/elevated
  shadow tiers match the existing rest-state and hover/active-state
  shadow tokens respectively). Only the tier-mapping documentation needs
  writing, no token edit.
- Every provenance comment on a changed value is updated to cite ADR-0043
  rather than the superseded ADR-0034/0041 language, and to say plainly
  where a value is *not* FFI's literal number and why (the `info` hue
  rotation, the `secondary-50`/`neutral-100` nudges) — this repo's stated
  convention is that an alias/value states its rationale rather than
  letting a stale comment drift under a changed number.
- Token and semantic-alias **names** are unchanged throughout. No
  Blade/Livewire/Filament file is touched in this stage.

## Testing Decisions

- **Primary seam: the contrast-verification tool**
  (`docs/design/verify-contrast.py`), invoked exactly as CI invokes it —
  its quiet-mode CLI form, via the docs-verification gate script's first
  gate — run against the real design token file. All 49
  currently-asserted pairs, plus the hue-separation check
  (`primary`/`success`/`info`/`danger`, ≥30° apart) and the
  page-surface-distinguishability check (≥10/765 RGB between the page,
  raised, sunken, warm, and quiet surface aliases), must report a clean
  pass with zero regressions before this stage is done. This was run for
  real, three times, against a genuine candidate token file during spec
  preparation — not hand-computed — and every one of the three real-tool
  regressions reported (interactive-border contrast on the warm surface,
  `primary`/`info` hue collision, `secondary-50`/`neutral-50`
  near-collision and its `neutral-100` knock-on) is the source of an
  Implementation Decision above, not a hypothetical risk.
- **Secondary, passive seam: CI's frontend build job** (already existing,
  not extended by this stage) — builds Tailwind and greps the compiled
  output against the repo's design-system smoke-test view's utility list,
  catching a build break from the token-value or font-import change. This
  stage introduces no new utility class, so no new smoke-test line is
  needed.
- **No Livewire/Feature test seam.** Confirmed against the approved design
  doc (its Stage 1 "Foundation" description: no Blade file touched) —
  Stage 1 is pure token-file value changes + font wiring + the
  verification tool's own list fix. Component- and page-level tests belong
  to Stage 2 and Stage 3's own specs.
- **What makes a good test here**: the contrast/hue/separation assertions
  are themselves the tests — they check external, rendered behaviour
  (computed colour vs. WCAG floor) rather than implementation details, and
  already exist as prior art in this exact tool. This stage changes what
  data they run against and repairs one stale internal reference; it does
  not add new assertion machinery.
- **Prior art**: the same contrast-verification pipeline was already the
  acceptance bar for the ADR-0034/0041 Forest/Sage/Sand rebase. This stage
  repeats that same discipline for the FFI values.

## Out of Scope

- **Stage 2 — Components**: the skeleton-loading and bottom-navigation
  components, and component-level visual updates (card, button,
  icon-medallion, etc.) applying the values this stage establishes. Own
  spec/plan.
- **Stage 3 — Homepage restructure**: section reorder, secondary-CTA
  placement, trust-element repositioning, and applying Stage 1/2's system
  to the other public pages' existing structure. Own spec/plan.
- **Filament admin/operator/vendor panels** — own palette-verification
  command, untouched by this stage.
- **Any Blade/Livewire file.**
- **Booking wizard / renewal / marketplace step structure** — unchanged;
  only their visual system changes, in Stage 3, not this one.
- **Copy and voice** — the existing anti-hard-selling copy guideline stays
  authoritative; no FFI donation-site vocabulary is introduced anywhere,
  this stage or later.
- **The four-primary-service-card rule** — not amended here.
- **Search-input pill shape** — ADR-0043 lifts the general full-radius
  pill-button prohibition, but this stage does not apply it to the search
  input; that stays a separate, later decision.
- **Hero CTA label wording** (the current label vs. the PRD's preferred
  wording) — explicitly deferred in the design doc, unrelated to this
  stage's colour/type work.
- **Committing the pivot-slot ramp-generation script** as a permanent repo
  tool is a plan-time decision, not settled here (see Implementation
  Decisions, ramp-generation methodology) — this spec only fixes the
  methodology it should follow, not where its script should live.

## Further Notes

- **Upstream authority chain**: ADR-0043 (supersedes ADR-0042 and
  ADR-0041's palette/typography) → the approved FFI full-visual-clone
  design doc's palette/typography section and its three-stage sequencing
  section, this spec covers Stage 1 ("Foundation").
- This spec was produced via `specflow:grilling` run inside the
  already-approved architectural brainstorming design: three grill rounds
  covered the FFI palette/typography facts (re-verified once via a direct
  "validasi" re-read of FFI's live source, catching two structural gaps),
  and a third, separate round covered the ramp-generation methodology
  itself specifically, after the project owner's explicit correction
  ("pastikan ikuti specflow") when that technical decision was about to be
  written into a plan without being grilled first.
- Every numeric value in Implementation Decisions above is the actual
  value verified against the real contrast-verification tool during this
  session — not a placeholder or hand computation — including the
  info-ramp hue rotation's full 11-stop output and the two small
  surface-token nudges. The next step (turning this into a plan) should
  transcribe these verbatim into the plan's task steps rather than
  recomputing them.
- The three real regressions this session found and fixed
  (interactive-border under-contrast on the new warm surface,
  `primary`/`info` hue collision, `secondary-50`/`neutral-50`
  near-collision with its `neutral-100` knock-on) are recorded as their
  own Implementation Decisions precisely because each was a genuine
  tool-caught defect against a real candidate file, not a hypothetical
  risk being pre-empted.

## Comments
