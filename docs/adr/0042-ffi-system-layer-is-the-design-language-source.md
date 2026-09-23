# ADR-0042: The FFI site contributes a system layer only; its page structure and content register do not enter Makam.co.id

## Status

Accepted — 22 Sep 2026. Supersedes the **source** named by
`docs/superpowers/plans/2026-09-13-kamboja-design-language.md` (kamboja.co.id)
for any design-language work not yet merged. It supersedes **nothing** in
[ADR-0041](0041-brand-guideline-2026-supersedes-adr-0034.md): the palette,
the typeface, and every token value stay exactly as the 2026 brand guideline
sets them.

Records a decision taken in a grill session on 21–22 Sep 2026 against the
product owner's request *"owner minta tampilannya sama dengan ffi"*, pointing
at `/home/ubuntu/fundforindonesia.org`.

## Context

### What was asked, and the distinction that answers it

The request repeats a shape this repository has already answered once. In
September the same owner asked for Makam to look like kamboja.co.id, and the
Kamboja plan's opening move was to split the request in two:

- **design language** — layout rhythm, grid, image strategy, typographic
  voice, density, screen states, motion, interaction patterns. Borrowable.
- **brand identity** — palette, logo, name, token values. Makam's own.

That split is reused here and is the whole of this ADR's reasoning. What
changes is only which site supplies the first half.

### What the FFI site actually is

Read directly from `/home/ubuntu/fundforindonesia.org`, not inferred from its
appearance:

| | Finding | Evidence |
|---|---|---|
| Stack | Next.js 14.2 App Router, TypeScript, Prisma/Postgres, NextAuth, Tailwind 3.4 | `package.json`, `src/app/`, `prisma/migrations/` |
| Palette | primary `#0073E6` blue, accent `#FF6B35` orange, Material-ish semantic set | `tailwind.config.ts`, `src/styles/globals.css` |
| Type | Inter, self-hosted via `next/font/google` | `src/app/layout.tsx` |
| System layer | radius 8/12/16, two shadow tiers (`card`, `elevated`), a named shimmer/skeleton convention | `tailwind.config.ts` |
| Navigation | fixed bottom bar with five tabs on mobile, fixed top header on desktop | `src/components/layout/BottomNavBar.tsx`, `DesktopHeader.tsx` |
| Homepage | hero carousel → six-icon action grid → urgent campaigns → new campaigns → featured campaigns → prayer wall | `src/app/page.tsx` |

**It is an unrebranded clone of Kitabisa**, a real Indonesian crowdfunding
company, and the relabelling was only partly applied:

- `package.json` `"name": "kitabisa-clone"`
- `VerificationBadge.tsx`: "Matches kitabisa.com's green checkmark badge"
- `Skeleton.tsx`: "shimmer animation matching kitabisa.com's loading UX"
- section headings in code: "Yang Baru di Kitabisa", "Pilihan Kitabisa",
  "Tentang Kitabisa"
- `AboutSection.tsx` links to `instagram.com/kitabisacom`,
  `facebook.com/kitabisacom`, `youtube.com/kitabisacom`
- test fixtures use `@kitabisa.com` addresses; `src/lib/seo.test.ts` expects
  the organisation name `'Kitabisa'`

This was surfaced to the owner rather than worked around. It is recorded here
because it is the single fact that most changes what "the same as FFI"
should be taken to mean, and a future reader deserves to know it was known.

### Most of the system layer already exists, at the same values

The borrow list shrank once the token file was read rather than assumed.
`resources/css/tokens.css` already defines the radius scale FFI uses —
`--radius-md` 8px, `--radius-lg` 12px, `--radius-xl` 16px — and a five-tier
`--shadow-*` scale where FFI has two. It already defines
`--mk-skeleton-base` and `--mk-skeleton-sheen`, and `design-system.md` §6.1
already specifies the skeleton convention, including a 14 Sep addendum
requiring a page-level skeleton to carry the same `py-section` rhythm as the
section it stands in for.

So three of the four things this ADR set out to borrow were already present,
and better specified. Only two genuine gaps remain: there is no reusable
skeleton **component**, so every screen hand-rolls the markup, and there is
no mobile bottom navigation. That finding matters beyond this ADR: whatever
the owner perceives when comparing the two sites, it is not the radius,
shadow or loading layer.

### One FFI pattern the token file already refuses

`tokens.css` bans pill geometry in a comment on `--radius-full`: *"No
pill-shaped buttons: playful geometry reads wrong on a bereavement service."*
FFI's desktop header uses a pill search bar. That is a deliberate
non-adoption, not an oversight, and the same line already refused the pill
CTAs in the Kamboja source.

### Three things in FFI that Makam's own brand guideline forbids

ADR-0041 adopted `MAKAM_CO_ID_Brand_Guideline_Visual_2026.pdf`, which the
owner supplied. Its page 09 sets the image register as natural, intimate and
respectful, and **prohibits** staged stock photography and dramatised grief.
Pages 01–03 set an explicitly anti-hard-selling copy voice.

FFI's homepage is built from Pexels stock photography and an urgency ladder
(`UrgentCampaigns`, a countdown-and-progress card grid). Adopting its page
structure would put Makam in breach of the guideline its own owner supplied
six days before this request. That is not a style preference; it is a
documented conflict between two instructions from the same person.

## Decision

1. **Only the system layer crosses over, and it is nearly all already here.**
   The radius scale, the elevation tiers and the skeleton convention exist
   already at the same values, so nothing is borrowed for them; they are
   confirmed, not imported. The two real additions are a reusable skeleton
   component and mobile bottom navigation. Both are generic interface craft;
   neither carries Kitabisa or FFI identity, and neither touches a brand
   value.
2. **Every borrowed value is re-expressed in Makam's own tokens.** No FFI hex,
   no Inter, no Tailwind config copied. A borrowed *idea* lands as a token in
   `resources/css/tokens.css` under Makam's existing naming, or it does not
   land.
3. **FFI's page structure and content register do not cross over.** No hero
   carousel, no campaign cards, no urgency ladder, no prayer wall, no stock
   photography. Makam's homepage keeps the structure `docs/product/prd-yiem-2026-09-18.md`
   and `AGENTS.md` §Mandatory MVP UX already fix.
4. **Mobile bottom navigation is adopted**, with tabs drawn from Makam's own
   four mandated services plus account, never from FFI's five. This is not a
   new idea entering from outside: `design-system.md` §3.11 already describes
   one and marks it *"⚠️ PROPOSED, NOT APPROVED"*. This ADR is the approval
   that section was waiting for, and FFI's contribution is the evidence that
   the pattern carries a real product on Indonesian mobile.
5. **Makam's ten mandatory screen states outrank the borrowed style.**
   `design-system.md` §6 continues to govern; FFI's skeleton convention is
   permitted to inform §6.1's presentation and nothing else.
6. **kamboja.co.id stops being the design-language source** for work not yet
   merged. Kamboja stages already merged stay: they were evaluated on their
   own merits and none of them imported a kamboja brand value. Five of eight
   are merged — Tahap 1 (vertical rhythm), 2 (surface alternation, which
   produced ADR-0040's `--mk-surface-quiet`), 3 (mobile CTA above the fold),
   4 (component hierarchy, which produced `card`'s `emphasis` axis) and 8
   (doc sync). Tahap 3 is identified by **content, not by label**: PR #303
   carries no stage number, and was matched to Tahap 3 by what it did —
   *"hero mobile — CTA naik 256px, dari 1,4 layar di bawah lipatan jadi
   0,14"*. Stated here so a later reader does not mistake it for a
   self-declared mapping.
7. **Tahap 5, 6 and 7 are not cancelled by this ADR, because none of them
   depends on kamboja.** Each is blocked on a decision, not on a source:
   Tahap 5 (image register) and Tahap 7 (copy voice) are already unblocked by
   Makam's own brand guideline — page 09 for imagery, pages 01–03 for voice,
   per ADR-0041's Consequences — and Tahap 6 (At-Need versus Pre-Need above
   the fold) needs a product-contract review. Removing kamboja as the source
   removes nothing they were relying on.

## Consequences

- One design-language source at a time. Two sources running together produce
  exactly the inconsistency that prompted the original "plain" complaint.
- `docs/superpowers/plans/2026-09-13-kamboja-design-language.md` becomes a
  historical record for its unmerged stages. Its **method** — sample the
  source, name the mechanism, prove the gap against measured evidence — is
  kept and reused; only its source changes.
- The four open design branches (`docs/kamboja-design-language`,
  `feat/kamboja-tahap2-surface-brand`, `docs/brand-refresh-phase2-plan`,
  `docs/adr-brand-guideline-2026-supersedes-0034`) each need a decision to
  land or close. This ADR does not take that decision; it only removes the
  ambiguity about which source they would be serving.
- Two new component primitives are implied and do not exist yet: a skeleton
  and a bottom navigation bar. Neither is built by this ADR.
- **The perceived gap is not where the request points.** Since the system
  layer already matches, the difference the owner sees must come from
  elsewhere. The Kamboja plan already diagnosed three such mechanisms against
  measured evidence, the first being vertical rhythm applied at half the
  mandated amount. Work aimed at "looking more like FFI" should be pointed at
  those mechanisms, not at re-importing tokens that are already correct.
- Nothing in this ADR changes a token value, so `verify-contrast.py`'s
  asserted pairs and `design:verify-filament-palette` are unaffected.

## Alternatives considered

- **Adopt FFI's palette and typography too.** Rejected: it discards a brand
  guideline the owner supplied and paid for, and it would require an ADR
  revoking ADR-0041 on the strength of one sentence. If the owner does want
  that, it is a brand decision to state explicitly, not one to infer.
- **Clone FFI's pages closely.** Rejected: FFI's structure serves crowdfunding.
  Makam is a transaction flow for families acting under time pressure and
  grief. Porting the structure would create pages whose content has to be
  invented.
- **Run Kamboja and FFI in parallel.** Rejected: see Consequences.
- **Do nothing until the owner responds to the Kitabisa finding.** Partly
  taken: the finding went to the owner, and only the work that is safe under
  either answer — the system layer, which carries no borrowed identity —
  proceeds meanwhile.

## Open questions this ADR does not answer

- ~~Whether the owner, knowing FFI is a Kitabisa clone, still wants visual
  kinship with it at all.~~ Answered 22 Sep 2026: yes, confirmed knowing —
  see [ADR-0043](0043-ffi-full-visual-clone-supersedes-system-layer-only.md).
- Whether Makam and FFI are meant to read as one organisation's products. If
  yes, the shared element should be a deliberate house style, not one site
  imitating the other.
- Whether the four open design branches land or close.

## Superseded (22 Sep 2026)

The decision this ADR records — FFI contributes a system layer only, palette
and page structure stay Makam's own — is **superseded** by
[ADR-0043](0043-ffi-full-visual-clone-supersedes-system-layer-only.md). The
owner, informed of this ADR's central finding (FFI is an unrebranded
Kitabisa clone) and of the conflict with ADR-0041, asked explicitly for the
full visual identity and page structure, not the system layer alone. This
text is kept verbatim rather than rewritten, per this repository's own
convention for superseded reasoning (see ADR-0041's own note on ADR-0034,
and `renewal-and-grave-registry/requirements.md`'s `## Superseded` section).
The analysis above — what FFI technically is, the Kitabisa evidence, the
system-layer inventory, the conflict with the brand guideline's page 09 —
remains the record of what was true and known at the point the owner made
the reversed decision. Nothing in it was found to be wrong; the decision
built on it simply changed.
