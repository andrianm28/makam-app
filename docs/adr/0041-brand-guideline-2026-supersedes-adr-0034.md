# ADR-0041: Adopt Brand Guideline 2026 — Forest/Sage/Sand supersedes ADR-0034's Earth/Leaf

## Status

Accepted — 15 Sep 2026. **Supersedes [ADR-0034](0034-adopt-makam-brand-identity.md) in full**
on colour and typography. The product owner supplied
[`MAKAM_CO_ID_Brand_Guideline_Visual_2026.pdf`](../design/brand/source/MAKAM_CO_ID_Brand_Guideline_Visual_2026.pdf)
(Version 1.0, 2026 — stored in this repository as the authority this ADR
argues from) and confirmed, asked directly, that it **replaces** the existing
palette rather than sitting beside it as marketing-only direction.

This ADR records the decision and its measured consequences. It changes no
token by itself; the token rebase, the component sync, and the documentation
sync are separate units of work, each with its own verification.

## Context

### What ADR-0034 locked, and why it is not simply wrong

ADR-0034 made Earth `#563B26` the primary and caged Leaf `#336B3E` as a tint
and accent only — `design-system.md` §1.2(b) and §9.2 MUST NOT 7 both forbid a
Leaf fill, badge, button, or alert. Those values were **sampled from the real
logo** (`docs/design/brand/source/logo.png`), which is why OQ-12 was closed
against them. That work was correct for what it had: a logo file and no
guideline.

The guideline is the artefact ADR-0034 did not have. It does not correct a
sampling error; it states a brand direction the logo alone could not express.

### What the guideline says

| Role | Name | Value | Guideline page |
|---|---|---|---|
| Primary | Forest | `#29483A` | 07 |
| Secondary | Sage | `#8FA99A` | 07 |
| Accent | Sand | `#D8C6A5` | 07 |
| Background | Ivory | `#F7F4ED` | 07 |
| Text | Charcoal | `#303330` | 07 |

Typography (page 08): **Plus Jakarta Sans** primary — website, app, social,
corporate. **Lora** secondary — emotional headline, quote, storytelling.

Recommended ratio (page 07): Ivory 57% / Sage 25% / Sand 15% / Charcoal 3%.

### The colours swap roles — that is the substance of the change

Green moves from a caged tint to the primary that fills brand fields. Brown
leaves the palette entirely. This is the exact rule enforced as recently as
14 Sep 2026, when a `secondary` medallion fill was refused under §9.2 MUST
NOT 7 (ADR-0040 D3's closing paragraph). That refusal was right under
ADR-0034 and is moot under this one — recorded here so the reversal reads as
a decision rather than an inconsistency.

**Brown is not erased from the identity.** The logo on guideline page 06
still draws its infinity curve in brown with green leaves at the centre. What
changes is that brown is no longer a UI role. The mark keeps it; the
interface does not.

## Decision

### D1 — The five brand colours become the semantic base

`Forest` primary, `Sage` secondary, `Sand` accent, `Ivory` page background,
`Charcoal` body text. Existing `--mk-*` semantic tokens keep their names and
their consumers; only the primitives they resolve to change. That is the
whole point of the two-layer model in `design-system.md` §1.1, and this is
the first time it earns its keep.

### D2 — Measured contrast, before any token moved

Every ratio below was computed from the guideline's own hex values, not
estimated. **This is the half of the work the guideline cannot do for
itself**, and it changes what the palette may be used for.

**Text pairs that pass AA (≥ 4.5:1):**

| Ratio | Pair |
|---|---|
| 11.64 | Charcoal on Ivory — body text |
| 10.08 | White on Forest — primary button |
| 9.18 | Forest on Ivory — headings |
| 7.64 | Charcoal on Sand |
| 6.02 | Forest on Sand |
| 6.02 | Sand on Forest — the accent text the guideline's own cover uses |
| 5.06 | Charcoal on Sage |
| 12.79 | Charcoal on white — card surface |
| 10.08 | Forest on white — card surface |

**Large-text only (≥ 3:1, < 4.5:1):** Forest on Sage, and Sage on Forest,
both 3.99. Usable for display headings; never for body copy.

**Fails, and what that constrains:**

| Ratio | Pair | Consequence |
|---|---|---|
| 2.53 | White on Sage | Sage is **not** a button fill with a white label |
| 1.67 | White on Sand | Sand is **not** a button fill with a white label |

### D3 — Sage and Sand are SURFACES, never borders and never focus rings

This is the constraint a reader would otherwise discover from a failed audit.
WCAG 1.4.11 requires 3:1 for non-text boundaries. Measured against the
surfaces they would sit on:

| Ratio | Pair | Verdict |
|---|---|---|
| 2.30 | Sage vs Ivory | below 3:1 |
| 1.52 | Sand vs Ivory | below 3:1 |
| 2.53 | Sage vs white | below 3:1 |
| 1.67 | Sand vs white | below 3:1 |
| 9.18 | **Forest vs Ivory** | passes — this is the focus ring |

So borders keep a neutral ramp and the focus ring is Forest. Sage and Sand
tint areas; they do not draw lines.

### D4 — The 57/25/15/3 ratio does not mention Forest, and that is read as deliberate

The guideline's ratio bar names Ivory, Sage, Sand and Charcoal, and omits the
primary. Read literally that would leave the brand colour unused, which
contradicts page 01, where Forest fills the entire cover.

The reading adopted here: the ratio governs **large page areas**, and Forest
is a colour of emphasis — buttons, brand fields, headings, the footer — not
of background. That is consistent with both the cover and with §2.3's "exactly
one primary action per view".

**If the owner meant something else, this is the line to correct**, and it is
cheap to correct: it is a distribution guideline, not a token value.

### D5 — Typography changes both faces, and Lora is a NEW kind of role

Plus Jakarta Sans replaces Inter; Lora replaces Poppins as the display face.
But Lora is not a like-for-like swap: Poppins was a geometric sans used for
display weight, while Lora is a **serif scoped to emotional headline, quote,
and storytelling** (guideline page 08). A serif applied to every heading
would overshoot the guideline, not follow it.

Both are Google Fonts and both carry Latin-Extended coverage for Indonesian.

### D6 — What this ADR deliberately does NOT do

No token file changes here. No component changes. No `verify-contrast.py`
edit. Those are separate units of work because each has its own failure mode
and its own gates, and because a palette rebase that lands in the same commit
as its own justification cannot be reviewed independently of it.

## Consequences

- **`verify-contrast.py`'s 49 asserted pairs are all rebased.** Every one
  names colours that no longer exist. The rebase is mechanical but total, and
  GATE 1 fails loudly until it is done — which is the correct behaviour.
- **The Filament palette resyncs**; `design:verify-filament-palette` is the
  gate that proves it.
- **§1.2(b) and §9.2 MUST NOT 7 are rewritten, not deleted.** Green is no
  longer caged; the cage moves to Sage and Sand as fills-with-light-labels
  (D2) and as borders (D3).
- **ADR-0037's "one accent, one purpose"** survives intact. It gains a real
  accent to govern: Sand, which ADR-0034's palette never had.
- **Kamboja plan Tahap 7 (copy voice) is unblocked** by guideline pages 01-03:
  "Menemani Keluarga, Menjaga Kenangan", "Kami hadir untuk memudahkan, ketika
  keluarga membutuhkan", and an explicit anti-hard-selling stance.
- **Tahap 5 (image register) is partly unblocked** by page 09: natural,
  intimate, respectful; three registers; and a prohibition list — horror
  aesthetic, dramatic grief, staged stock photography, explicit death
  imagery. OQ-K3/OQ-K10 (whether new photography is commissioned) stay open.
- **OQ-12 is re-opened and re-closed against a better source.** It was closed
  in Aug 2026 against a logo sample. The guideline is the document that
  sample was standing in for.

## Open questions this ADR does not answer

- **OQ-B1 — Does Forest have a ratio share?** See D4.
- **OQ-B2 — Do the raster brand assets get regenerated?** `logo.png` is brown
  and green, and stays valid per D1's note. Any Forest-filled lockup would be
  new artwork the guideline does not supply.
- **OQ-B3 — Does Lora ship at all before a quote/storytelling surface
  exists?** Loading a second webfont that nothing renders is weight without
  benefit; the first Lora consumer should arrive with it.
