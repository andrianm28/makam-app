# ADR-0040: Add `--mk-surface-quiet` and Widen the Brand Field

- **Status:** Accepted (design-token + public-view change; no security/authorization/financial/privacy code touched — see Consequences)
- **Date:** 14 Sep 2026
- **Supersedes in part:** `App\Platform\FeatureGate\Modes\UrgentMode`'s "never dismissible" doc-block reasoning (D4 below). No other ADR is superseded; ADR-0034's palette and ADR-0037's "one accent, one purpose" rule are both unchanged and both constrain this one.

## Context

`docs/superpowers/plans/2026-09-13-kamboja-design-language.md` §2 measured the live homepage at
1440×900 on 13 Sep 2026 and found two mechanisms behind its plainness that are not matters of
taste:

**(1) Every band is the same band.** `design-system.md` §4.4 ends with *"Proximity carries the
grouping — do not reach for divider lines."* That instruction only works if consecutive sections
are actually distinguishable from one another. Tahap 1 (commit `ebdeec7e`) fixed the *rhythm* half
— `@utility py-section` / `py-section-lg` finally consume `--mk-section-gap` / `--mk-section-gap-lg`,
40 px mobile / 64 px desktop, across 27 public views — but air alone cannot draw a boundary between
two sections painted the identical colour. On the homepage the last three sections (Kehangatan
Keluarga, FAQ highlights, customer-service CTA) run consecutively on `--mk-surface-page`, so §4.4's
promise is unmet there and the only remaining way to mark the boundary would be the divider line
§4.4 forbids.

**(2) The brand colour is ink, almost never a field.** §2.2 of the plan surveyed every element
larger than 2×2 px on the homepage and counted painted backgrounds:

| Painted background | Elements |
|---|---|
| `#FFFFFF` (cards) | 23 |
| `#E1F1E4` `secondary-100` | 7 |
| `#EEF0F0` `neutral-100` | 6 |
| `#F0E6DE` `primary-100` | 5 |
| `#F9F4F0` `primary-50` | 3 |
| `#F7F8F8` `neutral-50` (page) | 1 |
| `#FDF6EB` `warning-50` | 1 |
| **`#563B26` `primary-600`** | **1** |
| `#F2F9F3` `secondary-50` | 1 |
| `#2A1D13` `primary-900` (footer) | 1 |

The brand colour fills **exactly one** element on the whole page — the 160×52 px `Pesan Makam`
button. As a *text* colour it appears 46 times. A page whose 23 largest surfaces are white on
near-white reads plain regardless of how good the palette is.

**(3) A semantic layer that gets bypassed.** `tokens.css` §2's own header says *"Always reference
the SEMANTIC token in component CSS, not the primitive."* Two homepage sections paint tints by
reaching straight for the Tailwind primitives `bg-secondary-50` and `bg-primary-50`. For the second
of those a semantic token already exists and was simply bypassed (`--mk-surface-warm`, ADR-0034);
for the first, no semantic token existed to reference at all. The same audit cited in `app.css`'s
own rhythm comment found this is systemic: 31 of 94 `--mk-*` tokens had zero consumers, clustered
precisely in the scales no CI gate protects.

`design-system.md` §9.2 MUST NOT 12 and §9.4 require an ADR before introducing any new token, which
is what this document is.

## Decision

### D1 — Add `--mk-surface-quiet`, a third page band, as a pure alias of `secondary-50`

```css
--mk-surface-quiet: var(--color-secondary-50);   /* #F2F9F3 */
```

The page now has three bands to alternate between, all pre-existing values:

| Token | Value | Role |
|---|---|---|
| `--mk-surface-page` | `neutral-50` `#F7F8F8` | the default band |
| `--mk-surface-quiet` | `secondary-50` `#F2F9F3` | **new name**, cool/green quiet band |
| `--mk-surface-warm` | `primary-50` `#F9F4F0` | warm band, trust/reassurance (ADR-0034 D6) |

**No value is introduced.** `#F2F9F3` is the exact colour the homepage's "Cara kerja" band already
painted by writing the raw primitive. What changes is that the intent now has a name, so the next
section that needs a quiet band references the semantic token instead of guessing at a primitive —
the drift `--mk-text-price` was created to prevent in ADR-0037, in a different scale.

**Inside the Leaf cage, not an exception to it.** §1.2(b) permits `secondary` 50–200 *as surface
tint* and forbids it as a fill, badge, button, or alert. A page band is the canonical permitted
use. The cage's reason (Leaf sits ≈14° from `success`, so a Leaf *fill* would misread as a status)
is untouched: a full-bleed page band carries no status reading, and `--mk-surface-quiet` must never
be used to fill a control, badge, or alert. ADR-0037's "one accent, one purpose" (§9.2 MUST NOT 13)
is satisfied — this token has exactly one purpose, page banding, and nothing else in `tokens.css`
claims `secondary-50` for a different meaning.

**Contrast: no new pair, and none needed.** `docs/design/verify-contrast.py` already asserts every
text colour that can sit on this band, and none of their values changed:

| Pair | Measured | Floor |
|---|---|---|
| `text-default on secondary-50` (`#444B4B` on `#F2F9F3`) | **8.34:1** | 4.5 |
| `text-strong on secondary-50` (`#1A1F1F` on `#F2F9F3`) | **15.58:1** | 4.5 |
| `secondary-700 on secondary-50` (`#2A5833` on `#F2F9F3`) | **7.72:1** | 4.5 |

The assertion count stays at **49** and the suite still reports `RESULT: PASS — all 49 pairs meet
WCAG 2.1 AA`, exactly as the plan's §4.1 predicted for this stage. Nothing in `verify-contrast.py`
was weakened, removed, or re-asserted — §9.4's closing rule.

### D2 — Two `@utility` entries in `app.css`; `surface-warm` is not a new token

```css
@utility surface-quiet { background-color: var(--mk-surface-quiet); }
@utility surface-warm  { background-color: var(--mk-surface-warm); }
```

Same precedent and same reasoning as the `--mk-z-*`, `--mk-duration-*` and `py-section` blocks
already in that file: a semantic token with no utility is a token every call site reaches past.
`surface-warm` adds **no token** — `--mk-surface-warm` has existed since ADR-0034; it only lacked a
way to be written in Blade, which is exactly why the trust section wrote `bg-primary-50` instead.

Bare utility names (not `bg-surface-quiet`) match `touch-target` and `py-section` above them and
keep these clear of Tailwind's own `bg-*` functional namespace.

### D3 — `<x-mk.icon-medallion>` gains a third tone, `brand` — a real `primary-600` field

`earth` (`primary-100` tile / `primary-800` icon) and `leaf` (`secondary-100` / `secondary-800`)
are both *tints*. Adding `brand` — `bg-primary-600 text-neutral-0` — gives the page a second
element actually **filled** with the brand colour, which is the specific deficit (2) measures.

- **Contrast:** `white on primary-600` (`#FFFFFF` on `#563B26`) is **10.25:1**, already asserted in
  `verify-contrast.py`. No new pair. (The medallion is `aria-hidden` decoration, so this is a floor
  it clears rather than a requirement it must meet.)
- **Closed list stays closed.** `$tones` keeps its throw-on-unknown guard and the class strings stay
  complete literals for the JIT scanner, per that component's own doc block.
- **Size is deliberately untouched.** Enlarging the medallion on service cards is Tahap 4 of the
  plan, a separate change; this ADR touches its colour field only.
- **Not a status surface.** §3.3a's rule that a medallion never appears adjacent to
  order/payment/availability data is unchanged and still binding — more so for `brand`, since a
  filled tile reads louder than a tint.

`design-system.md` §3.3a is updated in the same change to document the third tone, because leaving
the documented closed list at two while the component accepts three would be a rank-2/rank-3
conflict, which §9.1 calls a defect.

### D4 — The `G-OPS-01` banner becomes dismissible, and its hotline gets button weight

This is the plan's U7, and it **reverses** the reasoning in `UrgentMode::fallback()`'s doc block.
Stating that plainly: that doc block argued the banner belongs to §6.9's "never dismissible" class.
§6.9's literal rule is narrower — *"Dismissible **only** for informational modes — never for one
that changes how a user must pay."* Closing `G-OPS-01` does not change how anyone pays; it changes
what the platform can honestly say about Urgent/At-Need acceptance. `PaymentMode::ManualCoordination`
and `PreNeedMode::InterestOnly` stay non-dismissible because they genuinely do change the payment
path. `GraveSearchMode`, `WhatsAppMode` and `MemorialMode` are already dismissible on exactly this
distinction; `UrgentMode` is being moved to the side of that line §6.9's own wording puts it on.

Two changes follow, and the second matters more than the first:

1. `UrgentMode::CapacityUnknown` returns `dismissible: true`. `<x-mk.alert>` only wires its Alpine
   `x-data`/`x-show` when `dismissible` is true; if JS never runs the banner simply stays visible,
   which is the safe failure mode. The close control is already a 44 px `touch-target` with
   `aria-label="Tutup"` (§3.8).
2. The hotline number inside the banner moves from an inline underlined link to
   `<x-mk.button variant="secondary">` — white fill, `primary-700` label, `primary-600` border, 44 px
   high. **`secondary`, deliberately not `primary`:** §2.3's "exactly one primary action per view"
   belongs to `Pesan Makam`, and a second brand-filled button at the top of the page would both
   break that rule and read as the urgency-manufacturing §2.3 forbids.

**N10 — no service promise is added.** Every word of the banner's Indonesian copy is unchanged:
the same title, the same "Jam operasional dan cakupan layanan Urgent … berbeda-beda di setiap
TPU/TPS" body, the same `hotline di bawah ini dapat dihubungi kapan pun untuk menanyakan
ketersediaan`, the same phone number, the same `atau`, the same `hubungi Bantuan` link, the same
trailing full stop. Only the phone number's *visual weight* and the banner's dismissibility change.
No 24/7 claim, no response-time claim, no acceptance claim — `G-OPS-01` is still closed and the
banner still says so. `design-system.md` §6.9's row for this gate ("operating hours and coverage,
**no acceptance claim**, hotline shown") is satisfied before and after.

`tests/Unit/Platform/FeatureGate/Modes/UrgentModeTest.php`'s assertion is updated from
`assertFalse` to `assertTrue` with the rename that goes with it. That is a decision changing, not a
test being bent to fit code: the ADR is the record, the test follows it.

### D5 — Where the alternation is actually applied, and where it is not

Applied to `resources/views/livewire/public/home-page.blade.php`, which is the only public view
with more than one page-level band — it holds 7 of the 33 `py-section` occurrences in
`resources/views`. Resulting order, top to bottom:

| Homepage section | Band |
|---|---|
| 3 · Four service cards | page |
| 4 · Cara kerja | **quiet** |
| 5 · TPU/TPS unggulan | page |
| 6 · Trust/safety | **warm** (§4.5 requires `--mk-surface-warm` here) |
| 6b · Kehangatan Keluarga | page |
| 7 · FAQ highlights | **quiet** |
| 8 · Customer-service CTA | page |
| 9 · Footer | inverse (`primary-900`) |

Every boundary now falls between two different colours, with no rule, border, or divider added
anywhere. §4.5's normative section **order** is untouched, and §4.5's requirement that the
trust/safety section use `--mk-surface-warm` is now met by name instead of by coincidence.

**Not applied to the other 26 views**, and this is a decision rather than an omission: each of them
carries exactly one page-level `py-section` wrapper. Their inner `<section>` elements are content
subsections inside a single column — the seven in `help-centre.blade.php`, the seven in each legal
document, the ten wizard steps — not page bands. Tinting those would produce uniform or striped
prose, which is neither what §4.4 asks for nor what "alternation" means. When one of those pages
grows genuine page-level bands, `surface-quiet` is there to band them with.

### D6 — The footer's brand field grows to the section rhythm

`layouts/app.blade.php`'s footer is the page's largest brand field and was padded `py-8` (32 px) —
the same half-rhythm value Tahap 1 removed from every other band. It becomes
`py-section lg:py-section-lg` (40 / 64 px), consuming the same tokens as every section above it.
No colour, link, copy, or structure changes; the `primary-900` field simply stops being the one
band still on the old rhythm.

## Consequences

What this unblocks:

- §4.4's "no divider lines" instruction is now achievable rather than aspirational: a page can mark
  a section boundary with a band change.
- A future section needing a quiet tint has a semantic token to reference. Before this, the only
  way to paint that colour was the raw primitive `tokens.css` §2's header forbids.
- Tahap 4 of the plan (card emphasis variants, larger medallions on service cards, two-tone
  headings) can assume three bands exist rather than inventing its own.

What this does **not** do:

- **No colour value changes.** Not one hex in `tokens.css` moved. The `verify-contrast.py` pair
  count stays 49 and the hue-separation check is untouched.
- **No new hue, no new family, no new radius/shadow/gradient/duration token** — the plan's N2–N5
  and N9 prohibitions all hold.
- **No dark mode** (`OQ-07` still open), no `dark:` utility added.
- **Not a security/authorization/financial/privacy change** under `AGENTS.md`'s human-review trigger
  list. D4 changes a banner's dismissibility and a phone number's visual weight; it changes no gate
  value, no server-side mode resolution, and no payment path. The gate state is still read from the
  server (§6.9), and `G-OPS-01` itself is untouched.

Risks / revisit criteria:

- **D4 is the one to watch.** If analytics or support volume ever suggest visitors are dismissing
  the Urgent banner and then arriving surprised at Step 3, revisit `dismissible: true` first — it is
  a one-line revert in `UrgentMode::fallback()` plus its test. The banner is per-page-load state
  (Alpine, not persisted), so a dismissal never survives a navigation.
- If `surface-quiet` and `surface-page` prove indistinguishable to real users at typical mobile
  brightness (`#F2F9F3` vs `#F7F8F8` is a subtle step by design — this is a calm-palette product,
  not a high-contrast one), the fix is a deeper band (`secondary-100`, already a permitted tint
  shade with its own verified pairs), not a divider line.
- If a later view reaches for `surface-quiet` to fill a *control* rather than a band, that is a
  §1.2(b) cage violation and must be rejected in review; no automated gate catches it, because the
  value is a legitimate token used in a legitimate-looking way.
