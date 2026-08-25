# ADR-0034: Adopt the Official Makam.co.id Brand Identity

## Status

Proposed — 17 Aug 2026. Task 1 of the implementation plan (palette rebase +
this ADR) is executed; Tasks 2–7 (typography wiring, raster asset pipeline,
component/Filament sync, documentation sync, whole-branch verification) are
not yet executed. This ADR records the decision for the whole adoption, not
only what has shipped so far — see [`docs/superpowers/plans/2026-08-17-brand-identity-adoption.md`](../superpowers/plans/2026-08-17-brand-identity-adoption.md)
for per-task status.

> **Superseded in part, 21 Aug 2026** — OQ-12 (real brand hex, resolved). The
> PROVISIONAL colour values recorded throughout this ADR were derived from a
> chat-reviewed render, not the official logo. They are now superseded by
> real logo-sampled values; this historical record is kept as-is (ADRs are
> not rewritten), but for the current, authoritative palette see
> [`docs/design/design-system.md`](../design/design-system.md) §1.2.

## Context

[`docs/design/design-system.md`](../design/design-system.md) v0.1 was written
under design-system open question OQ-02's explicit "no prior identity
assumed" default: a provisional Petrol teal primary, a placeholder "M"
monogram logo, and an empty `favicon.ico`. [ADR-0028](0028-adopt-token-driven-design-system.md)
itself flagged the primary colour as still open (OQ-01) and noted that
reversing it would require re-verifying all 46 contrast pairs.

On 17 Aug 2026 the stakeholder supplied an official identity render in chat,
together with the pasted *"Filosofi Logo Makam.co.id"* philosophy text. This
resolves **OQ-02** (an existing identity now exists and is authoritative) and
is the trigger for reopening **OQ-01** (brand primary colour). The
authoritative philosophy text, quoted as the reasoning authority for every
palette decision below:

> figure-8 = continuity/eternity; 8 radial leaves = life/growth/renewal;
> intersection = *"where life meets eternity"*; dark brown = earth, calm,
> stability, warmth, respect, humanist (deliberately "not too tech"); green =
> life, hope, sustainability, future green-cemetery positioning; Poppins =
> modern, clean, digital, friendly, professional.

The logo source PNG that the pipeline needs (`docs/design/brand/source/logo.png`)
was **not** supplied alongside the render and was confirmed absent at the
start of this task. Per the plan's documented fallback, Task 1 proceeds using
candidate hex values derived from reviewing the render in chat
(`brown #5D3A1F`, `green #2E7D32`), with every value derived that way flagged
`PROVISIONAL` and real pixel-sampling deferred to the new open question
**OQ-12** (see Consequences).

> **Superseded in part, 21 Aug 2026** — OQ-12 is now resolved: the logo
> source PNG arrived and real hex values were sampled from it. The
> `#5D3A1F`/`#2E7D32` values above are historical; see
> `docs/design/design-system.md` §1.2 for the current, logo-sampled palette.

## Decision

### D1 — Reverse OQ-01: Earth brown replaces Petrol teal as `primary`

The former Petrol choice was explicitly *provisional*, made only because no
identity existed yet, and was deliberately "not green" so a brand CTA could
never be confused with a `success` badge. The official identity mandates a
brown-led palette (philosophy: earth/calm/stability/warmth/humanist), which
**reverses** that decision. The former ambiguity concern is no longer solved
by hue-avoidance — it is now solved structurally: brand fills are brown,
green (`secondary`) never fills anything (D3). `resources/css/tokens.css`
§1.1 renames the family "Earth" and replaces every shade.

### D2 — Earth ramp values (PROVISIONAL — OQ-12)

> **Superseded in part, 21 Aug 2026** — OQ-12 (real brand hex, resolved). The
> table below is historical; see `docs/design/design-system.md` §1.2 for the
> current, logo-sampled palette.

| shade | hex | note |
|---|---|---|
| 50 | `#FAF5EF` | |
| 100 | `#F2E8DC` | |
| 200 | `#E2CDB6` | |
| 300 | `#CDA882` | |
| 400 | `#B08458` | |
| 500 | `#9A6F42` | |
| 600 | `#5D3A1F` | base — sampled brown, PROVISIONAL |
| 700 | `#4D3019` | hover / link |
| 800 | `#3E2713` | pressed |
| 900 | `#2F1D0E` | footer / inverse header |
| 950 | `#1E1208` | |

600 is the fallback candidate value (chat-reviewed render, hue ≈26°); 50–500
and 700–950 follow the plan's constant-hue starting curve unmodified — the
verifier passed against them on the first candidate iteration, so no further
tuning was required (see Evidence). All eleven shades are flagged
`PROVISIONAL` in `tokens.css` because none has been confirmed against
official brand values (OQ-12); the 600 comment carries the flag explicitly
since it is the one value directly derived from the sampling step.

### D3 — Leaf green replaces Sandstone as the caged `secondary`

> **Superseded in part, 21 Aug 2026** — OQ-12 (real brand hex, resolved). The
> table below is historical; see `docs/design/design-system.md` §1.2 for the
> current, logo-sampled palette.

`secondary` keeps its existing restricted-usage cage unchanged (50–200
surface tint, 300–400 decorative, 700–900 text-on-tint; **never** a fill,
badge, button, or alert) but its values and rationale change:

| shade | hex |
|---|---|
| 50 | `#F0F7F0` |
| 100 | `#DCEDDD` |
| 200 | `#BADBBB` |
| 300 | `#8FC692` |
| 400 | `#5FA964` |
| 500 | `#3F8A46` |
| 600 | `#2E7D32` (PROVISIONAL — sampled green) |
| 700 | `#27682B` |
| 800 | `#205423` |
| 900 | `#19431C` |
| 950 | `#0D2810` |

The cage's justification moves from proximity-to-`warning` (Sandstone sat
~6.5° from `warning`) to proximity-to-`success`: Leaf measures hue ≈123° at
600, and `success` measures ≈146° — a ≈23° gap. This is closer than the old
Sandstone/warning gap was, so the cage matters *more*, not less: any Leaf
fill would misread as a status success, so the cage is retained without
weakening `verify-contrast.py`'s `HUE_MIN_SEPARATION` rule — `secondary` is
deliberately excluded from `HUE_FAMILIES`, and its previous explicit
`HUE_EXCEPTIONS` entry is now dead weight (D5) because Leaf is caged, not
separated by hue. Philosophy authority: the eight radial leaves read as
life/growth/renewal; Leaf is the palette's expression of that.

### D4 — Danger hue tuned −11° to restore ≥30° primary/danger separation

Earth's hue (≈26°) sits only ≈24° from the pre-existing danger hue (≈3°),
below `verify-contrast.py`'s `HUE_MIN_SEPARATION = 30.0` for the `primary` /
`success` / `info` / `danger` status families. Per the spec's "Collision
finding 1" and the binding rule to fix the token, never the assertion
(design-system.md §9.4), every danger shade's hue was rotated **−11°**
(HLS lightness and saturation held), moving the base shade from `3°` to
measured `352.0°`. New danger 600 = `#A32435` (was `#A32A24`). Measured
primary/danger separation after the tune: **≈34.1°** (`|26.1 − 352.0| = 325.9`,
`360 − 325.9 = 34.1`), clearing the 30° floor. This tune is a computed hue
rotation of an already-approved colour, not a value read off the logo
render, so it is **not** flagged PROVISIONAL.

### D5 — Primary/warning proximity: reviewed with mitigation, no rule change

Earth (≈26°) and `warning` (≈39°) sit only ≈12.5° apart. Per the spec's
"Collision finding 2", `warning` was never part of the ≥30°
`HUE_MIN_SEPARATION` assertion (only `primary`/`success`/`info`/`danger`
are), and the structural mitigation that already existed continues to apply
unchanged: a solid dark-brown CTA carrying a white label never shares a
shape with a pale-amber badge carrying an icon plus dark text
(design-system.md §7.5, no-colour-only-status). Resolution: **documented,
reviewed, mitigated — no rule or token change.**

### D6 — `--mk-surface-warm` re-points to the Earth family

`--mk-surface-warm` moved from `var(--color-secondary-50)` to
`var(--color-primary-50)`: the warm-cream "trust/quiet section" role
belongs to Earth now that Earth, not Leaf, carries the brand's warmth
association. `docs/design/verify-contrast.py`'s two surface-warm pairs
(`text-default on surface-warm`, `border-interactive on surface-warm`) were
re-pointed from `color-secondary-50` to `color-primary-50` to follow —
otherwise they would silently keep verifying the *old* mapping and drift
from what the token actually resolves to.

### D7 — Poppins as `--font-display`; new `--font-document` (Task 2, not yet executed)

Per the philosophy ("Poppins = modern, clean, digital, friendly,
professional"), `--font-display` becomes a Poppins-led stack (`"Poppins",
"Inter var", "Inter", ui-sans-serif, system-ui, sans-serif`), self-hosted via
`@fontsource/poppins`, latin subset, weight 600 only, for h1/h2, the hero,
and the header wordmark. Inter is unchanged for body/UI/h3/h4 (Poppins' wide
geometry hurts at small sizes). A new `--font-document` token is added,
holding the *old* `--font-display` value (`"Source Serif 4", "Lora",
ui-serif, Georgia, Cambria, serif`) verbatim, so that no document
(certificate/agreement/invoice — `print.css` does not exist yet) can
silently inherit Poppins once one is built. Filament panels stay all-Inter
(brand voice is a public-surface concern). This decision is recorded here
but implemented in Task 2, which has not run yet.

### D8 — Raster asset pipeline, ext-gd, inverse-variant fallback (Task 3, not yet executed)

Logo assets ship as **raster** (WebP + PNG), not SVG, built by a pure-PHP +
ext-gd pipeline (`BrandAssetBuilder`, mirroring the existing
`FilamentPaletteGenerator` framework-free precedent) rather than a
Node+sharp or Python+Pillow pipeline, because neither sharp nor Pillow is
present on this host's tooling path while ext-gd (including WebP support) is
verified present. The inverse variant (brown→white selective recolour for
dark surfaces, leaves left untouched by construction since their hue falls
outside the recolour's hue window) has a documented fallback if the recolour
visually fails review: a light rounded chip behind the full-colour mark,
recorded explicitly in the task report rather than shipped silently. This
decision is recorded here but implemented in Task 3, which is hard-blocked
on the still-absent source PNG.

### D9 — Every PROVISIONAL flag, enumerated

> **Superseded in part, 21 Aug 2026** — OQ-12 (real brand hex, resolved).
> Every value flagged PROVISIONAL below has since been replaced with a real,
> logo-sampled value; see `docs/design/design-system.md` §1.2. This list is
> kept as a historical record of what was provisional and why.

- `--color-primary-600` (`#5D3A1F`) — sampled brown, PROVISIONAL.
- `--color-secondary-600` (`#2E7D32`) — sampled green, PROVISIONAL.
- The remaining Earth and Leaf shades (50–500, 700–950 of both families) —
  derived from the sampled 600 values via a constant-hue curve chosen at
  planning time, not independently confirmed; PROVISIONAL under the same
  OQ-12 umbrella even though only the 600 lines carry the inline comment
  (per the plan's Step 3 instruction, the inline flag is required on 600 and
  on any other shade that was hand-tuned to pass the verifier — none was).
- The danger ramp is **not** PROVISIONAL (D4) — it is a computed hue
  rotation of the already-accepted danger colour, not a render-derived value.
- Every raster asset from the Task-3 pipeline (not yet built) will be
  PROVISIONAL once it exists, per the spec's §8 NOT TESTED notes, until
  OQ-12 closes.

### D10 — Four planning-time deviations from the spec

Recorded here per the plan's Global Constraints, all already reviewed at
planning time:

1. No `print.css` or printable document views exist yet in this repo.
   `--font-document` is added and `design-system.md` §8.5 will be annotated
   (Task 6), but no document-header logo ships in this phase.
2. The asset pipeline tool is **ext-gd**, not sharp or Pillow (D8) — neither
   exists on the tooling path this repo builds on.
3. Poppins ships via the `@fontsource/poppins` npm package, matching how
   Inter already ships — no hand-written `@font-face`, no manual preload
   link.
4. `icon-192.png` / `icon-512.png` are dropped from the Task-3 manifest — no
   PWA manifest exists in this repo to consume them (YAGNI); tracked as an
   OQ-12 follow-up if a manifest is ever added.

### D11 — Final-review fix: spec §4.5's email brand-usage rule was missing (not planning-time)

Unlike D10, this is not a reviewed planning-time deviation — it is a Task 6
execution gap caught during the whole-branch final review. The spec's §4.5
Surfaces table required "a new short rule section in design-system.md
(hosted `/brand/` lockup URL reference only, brown-on-white only, no
attachments — consistent with AGENTS.md notification rules). No templates
built." Task 6 synced most of design-system.md to this ADR but never added
that section. Fixed post-hoc as `design-system.md` §8.6 "Email brand usage":
hosted `/brand/` lockup URL reference only (no inline/attached logo),
brown-on-white only (no inverse variant in email), no attachments (per
`AGENTS.md`'s notification rules) — rule only, no templates exist yet.

## Consequences

### Positive

- The palette now expresses the stakeholder's actual brand identity instead
  of a provisional placeholder, with the philosophy text as a traceable,
  quoted authority for every colour and typography decision above.
- The primary/danger hue collision that the identity swap created was
  caught and fixed *before* it reached the real `tokens.css` — the
  candidate-file iteration in Task 1 Step 2 exists specifically to make this
  kind of regression impossible to ship silently (design-system.md §9.4:
  fix the token, never the assertion).
- `verify-contrast.py`'s dead-code `HUE_EXCEPTIONS` entry (`("secondary",
  "warning")`, unreachable because the loop only ever pairs `HUE_FAMILIES`)
  is retired along with the Sandstone family it was written for, rather than
  silently carried forward as stale documentation.

### Negative

- Every brand colour value in this pass is PROVISIONAL (D9) until OQ-12
  closes with official hex values and a vector source — this repo is
  shipping a best-effort reading of a chat-reviewed render, not confirmed
  brand collateral.
- The danger ramp moved (D4): any other document or code that hard-coded
  the old `#A32A24` outside `tokens.css` would now be wrong — none was
  found in this repo (GATE 2 of `ci/verify-docs.sh` enforces exactly this),
  but downstream consumers outside this repo (if any exist) are not covered
  by that gate.
- `design-system.md` itself is not yet updated to match this ADR (that is
  Task 6) — until it lands, the design-system document under-describes the
  now-shipped `tokens.css`, which is a documented, temporary rank-1/rank-2
  drift, not a silent one.

## Alternatives rejected

- **Keep Petrol as `primary`.** Rejected: superseded by the stakeholder's
  authoritative identity: the whole point of OQ-02 resolving is that an
  identity now exists and the provisional placeholder no longer applies.
- **Retain Sandstone as `secondary`, treat green as decorative-only
  artwork with no token.** Rejected: the spec calls for green as a first-class
  brand colour (leaves = life/growth/renewal), and giving it no token would
  force every future component that needs a Leaf accent to hardcode a hex,
  which `tokens.css` §9 forbids.
- **Solve the primary/danger hue collision by moving `danger` to a
  completely different colour family (e.g. away from red) instead of a hue
  rotation.** Rejected: `danger` needs to keep reading as "danger" across the
  whole app; a small hue rotation within the same red-brick family preserves
  that association while restoring separation, and is the smaller, more
  reviewable change.
- **Leave the primary/warning ≈12.5° proximity unresolved without a
  documented mitigation.** Rejected: silently leaving a genuine perceptual
  collision undocumented would be indistinguishable from not having noticed
  it; D5 records the review and the existing structural mitigation instead.
- **sharp (Node) or Pillow (Python) for the Task-3 asset pipeline.**
  Rejected: neither is present on this host's tooling path; ext-gd is, and
  the `FilamentPaletteGenerator` precedent already established a
  framework-free PHP+ext-gd pattern this repo trusts.
- **SVG logo assets instead of raster.** Rejected at planning time (recorded
  in the plan's architecture section): the raster pipeline was chosen
  deliberately; SVG was not pursued further once ext-gd was confirmed to
  cover the requirement.

## Evidence

`python3 docs/design/verify-contrast.py`, run against the real
[`resources/css/tokens.css`](../../resources/css/tokens.css) after all Task 1
edits (Earth/Leaf/danger ramps applied, `--mk-surface-warm` re-pointed, and
[`docs/design/verify-contrast.py`](../design/verify-contrast.py)'s stale
surface-warm references synced):

```
WCAG contrast verification — resources/css/tokens.css
79 colour tokens parsed, 46 pairs asserted
...
RESULT: PASS — all 46 pairs meet WCAG 2.1 AA
```

Hue separation of semantic families (600 shade), measured:

```
primary      26.1 deg
success     145.5 deg
info        227.6 deg
danger      352.0 deg
secondary   123.0 deg
warning      38.6 deg
```

Full output is pasted in the Task 1 report
(`.superpowers/sdd/2026-08-17-brand-identity-adoption/task-1-report.md`,
git-ignored execution ledger, not part of this commit) and will be pasted
into `design-system.md` §7.1/§12 in Task 6.

## Open questions

- **OQ-01 — Resolved.** Earth brown is the brand primary (D1–D2), per this
  ADR.
- **OQ-02 — Resolved.** An official identity exists (stakeholder-supplied
  render + philosophy text, 17 Aug 2026) and is authoritative.
- **OQ-12 — New.** Official brand hex values, a vector source for the mark,
  and a horizontal lockup are still outstanding. Every colour value derived
  from the chat-reviewed render (D9) and every future raster asset (D8)
  stays PROVISIONAL until OQ-12 closes.

## NOT TESTED

- The raster asset pipeline (Task 3), `<x-mk.logo>` rewrite and header/footer
  wiring (Task 4), Filament palette regeneration (Task 5), and
  `design-system.md`/`CHANGELOG.md` sync (Task 6) have not been executed —
  this ADR documents their decisions, not their shipped state.
- No user testing, visual review, or real-device check of the new palette
  has happened; `design-system.md`'s tone rules remain reasoned, not
  researched (unchanged from v0.1).
- The source PDF (*Filosofi Logo Makam.co.id.pdf*) could not be parsed
  directly; its content was supplied as pasted text in chat and is treated
  as authoritative per the spec.
