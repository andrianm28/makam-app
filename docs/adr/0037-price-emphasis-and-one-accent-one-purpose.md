# ADR-0037: Add a Price-Emphasis Token and Codify "One Accent, One Purpose"

- **Status:** Accepted (docs/design-governance change, no security/authorization/financial/privacy code touched — see Consequences)

## Context

A competitive/design benchmark review of kamboja.co.id (a rival Indonesian funeral-services
platform) surfaced three approved recommendations for `docs/design/design-system.md`:

1. kamboja.co.id uses exactly one accent colour for urgency (its emergency hotline) and exactly
   one, different, accent colour for money/price figures — never reused for anything else.
   `design-system.md` §1.2(b) already applies this discipline informally to `secondary` (Leaf —
   "surface tint + accent **only**, never a fill"), and §9.2 MUST NOT 7 already enforces it for
   that one family, but the discipline was never generalized into an explicit rule covering every
   accent/semantic token.
2. kamboja.co.id's hero photography is deliberately warm, candid family photography rather than
   solemn funeral-industry imagery — validating, not contradicting, the "lighter, younger, warmer"
   brand-refresh direction already in progress
   ([`2026-08-21-brand-visual-refresh-design.md`](../superpowers/specs/2026-08-21-brand-visual-refresh-design.md)).
   §2.2's imagery guidance was constraints-only; it had no positive complement for when people are
   in-frame.
3. kamboja.co.id shows real trust signals (review-platform badges, partner/government logos,
   media coverage) prominently. This repository has no real content for that yet — no confirmed
   partner relationships, no review-platform listing — so building the component now would mean
   populating it with fabricated content, which §2.3 and `AGENTS.md` both forbid.

This ADR covers recommendation 1's colour-token consequence specifically: `resources/css/tokens.css`
was audited (26 Aug 2026) for an existing colour dedicated to rendering monetary figures (cemetery
price ranges, renewal fees, quote/invoice totals). None exists — money is currently distinguished
only by `font-weight: 700` ("reserved: totals, order reference" per §1.4), never by colour. Per
`design-system.md` §9.2 MUST NOT 12, introducing a new token requires this ADR.

## Decision

### D1 — Add `--mk-text-price`, an alias into the existing Earth family, not a new hue

`--mk-text-price: var(--color-primary-800);` — Earth's darkest legible shade, reused for a new,
single purpose: plain body-text emphasis for a **confirmed/final** monetary figure. Two reasons
this reuses an existing primitive instead of adding an eighth colour family:

- **Restraint is a stated design value here**, not an oversight: §2.2 names "deep, low-chroma,
  few hues" as the target, and §1.2(b) already states "a restrained palette is also correct for
  this domain: one brand colour, one accent, four semantics, neutrals." Adding a new hue family
  would need its own ADR-level colour decision, a new ramp, new `verify-contrast.py` hue-separation
  assertions against every existing family, and — per `AGENTS.md`'s human-review trigger list —
  is the kind of durable brand decision better made by a human, not inferred from a competitor's
  palette by an agent.
- **No existing token already claims `primary-800` for plain text on a white surface.** It is
  currently used only as a button `pressed`-state *fill* and as a badge/tint *foreground on
  `primary-100`* — neither is body text on `--mk-surface-raised`/`--mk-surface-page`. Giving it a
  third, disjoint usage context (plain text on white, dedicated to money) does not collide with
  either existing usage, and gives the new `--mk-text-price` token a genuinely single, exclusive
  purpose per D2 below.

This mirrors the pattern `--mk-intent-urgent-*` already uses (an explicit alias of `warning`
family shades, documented in `design-system.md` §1.2 as "an alias, not a new colour") rather than
inventing a hue for "urgent."

**Contrast:** not independently asserted as a new pair in `docs/design/verify-contrast.py`,
because no new hex value was introduced — `primary-800` (`#382719`) is strictly darker than the
already-verified `primary-700 on white` pair (12.16:1, §7.1), so its own ratio on white is
provably higher still. `python3 docs/design/verify-contrast.py` re-run confirms all 49 existing
pairs are unaffected (no value changed).

**Scope boundary:** an **indicative** price range (e.g. a cemetery's Step 2 "Perlu konfirmasi"
range) must keep using `neutral` per the existing §2.3 DO — `--mk-text-price` is for a
confirmed/final figure only (order total, invoice amount, accepted quote, renewal fee due). This
distinction is stated explicitly in the token's own doc comment in `tokens.css` and in
`design-system.md` §1.2(d) to prevent the two being conflated later.

### D2 — Codify "one accent, one purpose" as an explicit, enforceable governance rule

Added as `design-system.md` §1.2(d) (prose rationale, generalizing §1.2(b)'s existing Leaf-specific
cage) and §9.2 MUST NOT 13 (the enforceable form): every accent/semantic colour token in
`tokens.css` has exactly one designated purpose across the whole application and must not be
reused for a different semantic meaning elsewhere, even where visually convenient. This does not
retroactively re-litigate any existing token's purpose — it is additive governance for future
token decisions (including this ADR's own D1) and closes the gap between "we already do this for
Leaf" and "this is a rule everyone must follow for every accent."

### D3 — §2.2 imagery guidance gains a positive complement (no token/code change)

Added directly under the §2.2 table: when photography includes people, prefer candid warmth and
connection (family, community) over solitary or somber framing. This is forward-looking guidance
for imagery choices not yet made — it does not require sourcing new photography now, and no image
currently shipped needs to change.

### D4 — `<x-mk.trust-badge-strip>` reserved, not built (§3.3d)

Documentation-only: the intended component name (following the existing `<x-mk.*>` convention),
placement guidance (near the homepage hero or footer), and an explicit constraint that it must
never ship with placeholder or fabricated logos/reviews — only real content, once real business
relationships exist to supply it. No Blade component file is added by this ADR.

## Consequences

What this unblocks:

- Future price-rendering work (Blade views, Filament resources) has a real token to reach for
  instead of inventing an ad hoc "strong" colour per view — the exact drift `--mk-text-price`
  exists to prevent.
- The "one accent, one purpose" rule gives future design-token proposals (and future benchmark
  reviews) a citable rule instead of relying on inferring it from the Leaf-specific carve-out.

What this does **not** do:

- **No retrofit.** No existing Blade view, Livewire component, or Filament resource was changed
  to consume `--mk-text-price` in this ADR. Price/amount rendering call sites keep their current
  styling; adopting the new token at each site is separate, future, task-scoped work — grep
  `resources/views` and `app/Filament` for `harga`/`total`/`biaya`/currency formatting to scope it
  when that work is picked up.
- **No new colour family, no new hue-separation risk.** `verify-contrast.py`'s
  `HUE_MIN_SEPARATION` check is unaffected because no new hue was introduced.
- **Not a security/authorization/financial/privacy code change** under `AGENTS.md`'s human-review
  trigger list — this is a visual-design token and two documentation additions, not a change to
  how money is computed, charged, or authorized. Human review is still available via normal PR
  review, but this ADR does not itself require the heavier `AGENTS.md` sign-off gate reserved for
  transactional/financial *logic* changes.

Risks / revisit criteria:

- If a future brand review decides money deserves a genuinely distinct hue (not an Earth alias),
  only `--mk-text-price`'s one line in `tokens.css` changes — the two-layer token model
  (`design-system.md` §1.1) exists precisely so this kind of revision does not ripple through
  every call site.
- If `--mk-text-price` and `--mk-border-brand`/`--mk-focus-color` (both already `primary-600`,
  a lighter shade) are ever perceived as "the same brand colour" by real users despite the
  different shade and different usage context (fill/border vs. plain text), re-evaluate D1's
  "reuse the family, don't add a hue" choice against a real hue for money.
- §3.3d's reservation should be revisited the moment real partner/review/certification content
  exists — building `<x-mk.trust-badge-strip>` at that point is explicitly anticipated, not
  discouraged; only building it *before* real content exists is the thing this ADR forbids.
