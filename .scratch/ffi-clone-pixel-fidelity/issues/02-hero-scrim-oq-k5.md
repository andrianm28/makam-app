# 02: Hero scrim + OQ-K5 resolution

**What to build:** A visitor viewing the homepage sees the hero as one
cohesive visual moment — the heading and CTA sit directly over the
full-bleed photo, readable through a dark scrim, the way FFI's real hero
renders — instead of today's two disconnected blocks (a photo band, then
a separate text panel below it). This closes **OQ-K5**
(`design-system.md`'s own tracked open question), which has named this
exact redesign as blocked on three things until now: a scrim token, an
ADR, and a new contrast-verification method that doesn't depend on the
specific photo underneath. All three ship in this ticket.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] A new token, `--mk-hero-scrim`, is added to `tokens.css` — a
      gradient value (transparent near the top, dark enough at the
      bottom that white text clears WCAG AA over it) placed with the
      same care/documentation convention every other token in that file
      already carries.
- [ ] A new contrast-verification method exists that guarantees the
      scrim's own darkest stop is opaque/dark enough for white text to
      clear 4.5:1 **regardless of the photo underneath** — a worst-case
      floor, not a measurement of any one specific photo. Decide at
      implementation time whether this extends `verify-contrast.py` or
      is its own script; either way it must be a real, runnable check,
      not a comment asserting the guarantee informally.
- [ ] `<x-mk.hero>` is redesigned: the photo is the component's root
      visual layer, with the heading/CTA positioned over it behind the
      scrim — replacing the current two-stacked-`<div>` structure
      (photo band, then a separate `bg-primary-50` text panel below).
- [ ] The component's existing behavior when `heading` is missing is
      unchanged (still throws `InvalidArgumentException` at render time
      — no sensible fallback, per the component's own existing
      precedent).
- [ ] Decide and implement the component's behavior when `image` is
      missing: either `image` becomes effectively required for the new
      overlay composition (since a scrim with no photo underneath makes
      little sense), or a two-block fallback is kept for that case —
      document whichever is chosen directly in the component's own file,
      the same way its existing doc comments explain past decisions.
- [ ] A new ADR is written recording: the unified hero is now approved,
      the scrim token and its worst-case contrast guarantee, and that
      this resolves OQ-K5. `design-system.md`'s existing "deliberately
      not done" note about the hero stays in place, unmodified — this
      doc's own established convention is additive supersession, never
      silently deleting historical text — with a note added beside it
      pointing to the new ADR.
- [ ] FFI's real auto-rotating multi-slide carousel (Framer Motion
      transitions, touch/swipe, dot indicators, configurable interval —
      confirmed by reading `HeroBanner.tsx` before this ticket was
      written) is explicitly NOT built. This ticket's scope is the
      hero's static visual composition only.
- [ ] `prefers-reduced-motion` and no-JS: the scrim is pure CSS, not
      dependent on any animation or script running.
- [ ] `tests/Feature/View/Components/MkHeroTest.php` (this repo's
      established seam for `<x-mk.*>` primitives) gains real assertions:
      the scrim renders, the heading/CTA render positioned over the
      photo (not below it), the decided `image`-missing behavior, and a
      real check against the new contrast-verification method's own
      guarantee.
- [ ] `bash ci/verify-docs.sh` passes, and the new contrast-verification
      method itself passes when run for real.
