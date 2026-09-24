# ADR-0045: Approve a hero scrim overlay, resolving OQ-K5

## Status

Accepted — 24 Sep 2026. **Resolves OQ-K5** (`docs/design/design-system.md`
§3's `<x-mk.hero>` documentation, and the kamboja design-language plan's
own §11 open-questions table), which asked whether hero text should sit
above the photo and named exactly three prerequisites — a scrim token, an
ADR, and a new contrast-verification method — before that could happen.
All three ship with this ADR. Does **not** touch `information-architecture.md`,
the four-primary-service-card rule, or any page's copy/step structure.

## Context

The owner's 24 Sep 2026 direction is a pixel-fidelity 1:1 visual clone of
the FFI reference (`fundforindonesia.org`), surfaced after reviewing the
deployed Stage 1-3 work and PR #358's color/tile fix and finding the site
still didn't read as FFI. `<x-mk.hero>`'s own file-header comment named the
unified photo-with-overlay-text hero as explicitly deferred: *"NO scrim,
overlay, or gradient is introduced — the unified hero is deliberately out
of every stage until OQ-K5 is answered."*

**What OQ-K5 actually asked, and what the kamboja plan's own corrected
analysis found:** the kamboja design-language plan (`docs/superpowers/
plans/2026-09-13-kamboja-design-language.md`) §4.3 was itself corrected on
13 Sep 2026, after checking a live capture of the real reference site
directly. That correction found the reference's real hero technique is
**not** a dark scrim at all — it composes the photo so the text-bearing
side is naturally bright (a backlit interior/window), places dark
(`#263238`) text directly on that bright area, and needs no overlay
layer whatsoever. The plan's own conclusion at the time: *"the burden shifts
from a token to image selection... it stays out of every stage... the
reason is no longer 'no evidence for this pattern exists', but 'the
evidence exists, and its requirement is photography we don't have yet'
(OQ-K3/OQ-K4)."*

This ADR does **not** pretend to adopt that exact technique — Makam has no
photography with a naturally bright text-bearing side yet (OQ-K3/OQ-K4
remain genuinely unresolved, unrelated to this ADR), and art-directing new
photography is outside a design-token decision's scope. Instead, this ADR
adopts the alternative the kamboja plan itself named: a scrim token, gated
by a real, mathematically-derived worst-case contrast guarantee, that
works with **any** photo — including the drone aerial photography Makam
has today, which the kamboja plan's own analysis confirmed does not have
the bright-side property the reference's real technique depends on.

## Decision

1. **A new token, `--mk-hero-scrim`**, is added to `tokens.css`: a
   `linear-gradient` from fully transparent at the top to a `rgb(0 0 0 /
   0.65)` dark stop at/near the bottom, where the heading/CTA sit.
2. **A new contrast-verification method**,
   `docs/design/verify-hero-scrim-contrast.py`, computes — mathematically,
   not by measuring any one specific photo — the alpha-composited colour a
   viewer would see if the scrim's darkest stop sat over a **worst-case
   pure white (`#FFFFFF`) photo**, and asserts white text against that
   computed colour clears WCAG AA (4.5:1). This is deliberately not a
   `verify-contrast.py`-style token-pair entry: the kamboja plan's own
   correction is explicit that *"a photo is not a token"* — a PAIRS-style
   entry would pass without proving anything about a real image. The
   0.65 alpha carries real margin over the 0.535 the math alone requires,
   for real-world photos (JPEG artifacts, sub-pixel rendering) a pure
   worst-case calculation doesn't model.
3. **`<x-mk.hero>` is redesigned**: the photo becomes the component's root
   visual layer; the heading/CTA render absolutely positioned over it,
   bottom-aligned, behind the scrim — replacing the previous two
   stacked `<div>`s (a photo band, then a separate `bg-primary-50` text
   panel below it). Heading colour changes from `text-neutral-900` to
   `text-neutral-0` (white), correct for text over a dark scrim.
4. **`image` becomes required**, symmetrically with `heading` (both throw
   `InvalidArgumentException` at render time now) — a scrim with no photo
   underneath makes no sense for this component's redesigned purpose, so
   there is no two-block fallback for a missing image anymore.
5. **The mobile CTA-reorder mechanism is removed** (Task C1, 13 Sep 2026,
   kamboja plan §2.7/§7 option M2 — `order-last`/`md:order-none`). It
   existed only because the old two-block layout put a photo band above
   the CTA-bearing text panel, burying the CTA below the fold on mobile.
   With the CTA now rendered on top of the photo from first paint on
   every breakpoint, that problem no longer exists, and the mechanism —
   plus its own dedicated regression test — is dead complexity, removed
   rather than left inert.

## Consequences

- `design-system.md`'s own "deliberately not done" hero note, and the
  kamboja plan's own OQ-K5/A6 rows, stay in place unmodified — this
  repository's established convention for this kind of document is
  additive supersession, never silently rewriting historical text. A
  note pointing to this ADR is added beside each, in the implementation
  that follows.
- Any future real photography that DOES have a naturally bright
  text-bearing side (closing OQ-K3/OQ-K4) does not require reverting this
  ADR — `<x-mk.hero>`'s new structure (photo root layer, text positioned
  over it) already accommodates a caller that wants lighter or no scrim
  for a specific bright photo; that would be a follow-up decision about
  making the scrim's strength configurable per call site, not a reversion
  of this one.
- `verify-hero-scrim-contrast.py` only proves the scrim's own worst-case
  floor. It does not, and cannot, prove any *specific* photo reads well
  with real subject matter under the scrim — that remains an art-direction
  judgment call at the point a real photo is chosen, same as it already
  is for every other decorative image in this codebase.

## What this ADR deliberately does not do

- It does not adopt the reference site's own real hero technique
  (bright-composition + dark text, no overlay) — that needs photography
  Makam does not have (OQ-K3/OQ-K4), which remain open, unrelated to this
  decision.
- It does not build FFI's real auto-rotating multi-slide carousel (Framer
  Motion transitions, touch/swipe, dot indicators) — this ADR's scope is
  the hero's static visual composition only.
- It does not change `information-architecture.md`, the
  four-primary-service-card rule, or any page's copy or step structure.

## Alternatives considered

- **Wait for bright-composition photography (OQ-K3/OQ-K4) instead of
  building a scrim.** This is the reference site's own real technique,
  and was genuinely considered given the owner's 1:1 direction. Not
  chosen for now: it depends on art-directed photography this repo does
  not have today and has no committed timeline for, while the owner's
  direction asks for visible progress on the pixel-fidelity clone now.
  Nothing here forecloses adopting the bright-composition technique later
  once suitable photography exists — see Consequences above.
- **A fixed-opacity single-colour overlay across the whole photo**, rather
  than a gradient. Rejected: it would darken the photo's upper portion for
  no readability benefit, since no text sits there — a gradient gets the
  same worst-case guarantee only where it's actually needed.
