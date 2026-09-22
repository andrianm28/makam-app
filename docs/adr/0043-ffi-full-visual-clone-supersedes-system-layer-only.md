# ADR-0043: Full FFI visual identity and page structure, superseding system-layer-only and the 2026 brand guideline

## Status

Accepted — 22 Sep 2026. **Supersedes [ADR-0042](0042-ffi-system-layer-is-the-design-language-source.md)
in full** (system-layer-only becomes full visual clone) and **supersedes
[ADR-0041](0041-brand-guideline-2026-supersedes-adr-0034.md)'s palette and
typography** (Forest/Sage/Sand/Ivory/Charcoal and Plus Jakarta Sans are
replaced, not kept alongside). It does not touch ADR-0041's other content
(the copy-voice and image-register pages of the guideline are unaffected
unless a later change says so explicitly).

Records a decision taken 22 Sep 2026, relayed as *"owner minta visual ui
persis seperti ffi"* ("the owner wants the UI to look exactly like FFI"),
following directly from the grill session that produced ADR-0042 the same
day.

## Context

ADR-0042 recorded two findings and reached a narrower decision on their
strength: the FFI site (`/home/ubuntu/fundforindonesia.org`) is an
unrebranded clone of Kitabisa, a real Indonesian crowdfunding company
(package name `"kitabisa-clone"`, code comments citing kitabisa.com,
unconverted social links), and most of what there was to borrow from it
already existed in Makam's own token file at the same values. On those two
findings, ADR-0042 chose to take only FFI's system layer (radius, shadow,
skeleton convention, bottom navigation pattern) and keep Makam's own
palette, typeface, and page structure.

Before any of ADR-0042's two open gaps (a skeleton component, a bottom nav)
were built, the instruction returned, stronger and more specific. Two
questions were put to confirm it was an informed decision, not a relay of
the original ask without the new facts attached:

1. **Does the owner know FFI is a Kitabisa clone, and still want it exactly?**
   Confirmed: yes, known, still wanted.
2. **Does "exactly" include page structure**, given FFI's pages are
   crowdfunding-specific content (campaign cards, an urgency ladder, a prayer
   wall) with no equivalent in Makam's domain? Confirmed: **yes, including
   structure.**

Both answers are recorded here because they are what make this ADR
different from a plain repeat of the earlier request — they are new
information ADR-0042 did not have.

## Decision

1. **Palette replaced in full.** `resources/css/tokens.css`'s Forest/Sage/
   Sand/Ivory/Charcoal system is replaced with FFI's: primary `#0073E6`,
   primary-dark `#005BB5`, accent `#FF6B35`, success `#00C853`, warning
   `#FFB300`, danger `#D50000`, background `#FFFFFF` / `#F5F5F5`, text
   `#212121` / `#757575`, border `#E0E0E0`. Contrast pairs are re-verified
   against these values, not assumed compliant — WCAG AA is a hard CI gate
   (GATE 1) independent of which palette is authoritative.
2. **Typography replaced.** Plus Jakarta Sans is replaced by Inter,
   self-hosted the same way this codebase already self-hosts its current
   typeface (no third-party `<link>` tag; `docs/architecture/technology-
   baseline.md`'s font-loading constraint is satisfied by substitution, not
   exempted).
3. **The pill-button prohibition is lifted.** `tokens.css`'s
   `--radius-full` comment — *"No pill-shaped buttons: playful geometry
   reads wrong on a bereavement service"* — is superseded for whatever
   surfaces this decision's implementation touches. The reasoning that
   produced the prohibition is not found to be wrong; the owner's instruction
   overrides it knowingly.
4. **Page structure follows FFI's**, adapted to Makam's actual domain
   content rather than inventing crowdfunding content Makam has none of.
   The adaptation mapping is **not fixed by this ADR** — it is exactly the
   kind of design decision `AGENTS.md`'s Development methodology routes
   through `brainstorming` before a plan is written, and rushing it here
   would silently pre-empt that step.
5. **The image-register and copy-voice pages of the 2026 brand guideline
   (ADR-0041 pages 01–03, 09) are not touched by this ADR.** Only palette and
   typography are superseded. If the new visual direction conflicts with the
   guideline's anti-hard-selling voice or its ban on staged stock photography
   — FFI's own homepage uses exactly that — the conflict is `AGENTS.md`
   §Source precedence business as usual and gets resolved when the specific
   surface is designed, not pre-decided in bulk here.

## Consequences

- **`verify-contrast.py`'s asserted pairs need a full second rebase**, the
  same mechanical, total rework ADR-0041 required when it replaced ADR-0034's
  palette. GATE 1 fails until it is done, which is correct, not a defect.
- **`design:verify-filament-palette` needs to decide its own scope.** Nothing
  in this ADR extends the FFI palette to the admin/operator/vendor Filament
  panels; ADR-0042's public-surface-only boundary is kept unless a later
  decision widens it.
- **Every consumer of the old token names breaks by design**, not by
  accident: `resources/views/components/mk/*` and every public Blade view
  reference `--color-primary-*`, `--color-secondary-*`, `--color-accent-*`
  by name. Whether the new palette keeps those names with new values, or
  takes new names, is an implementation choice for the plan, not this ADR —
  named here as a real decision so it is not made silently mid-refactor.
- **A real conflict with a BINDING document, left unresolved by this ADR.**
  `AGENTS.md` §Mandatory MVP UX states, as a MUST: *"Homepage has exactly
  these four primary services: Pemesanan Makam, Layanan Pemakaman,
  Perpanjangan Makam, FAQ."* FFI's own quick-action grid has **six** tiles
  (Donasi/Zakat/Galang Dana/Experience/Kolaborasi CSR/Asuransi). "Page
  structure exactly like FFI" and "exactly four services" cannot both be
  followed literally at the tile-count level. This ADR does **not** decide
  which wins. `AGENTS.md` itself states it is binding and that stakeholder
  MVP items are not to be dropped "merely because an external gate is
  closed" — a visual-parity instruction is not the same class of reason
  that clause anticipates, so silently overriding the four-card rule here
  would be the kind of scope decision this repository's own process reserves
  for a human, not an inference. Flagged as the first question for
  brainstorming, not resolved by assumption.
- **Kamboja's five merged stages are not reverted by this ADR.** They
  changed rhythm, surface treatment, and component structure without
  importing a brand value; nothing in this decision requires undoing them,
  though the new palette will recolour whatever they built.
- **This reopens, rather than closes, the four open design branches**
  ADR-0042 flagged (`docs/kamboja-design-language`,
  `feat/kamboja-tahap2-surface-brand`, `docs/brand-refresh-phase2-plan`,
  `docs/adr-brand-guideline-2026-supersedes-0034`) — note two of the three
  branch names still existed as of ADR-0042; both were deleted in this
  session's branch-cleanup pass since their content had already merged
  under different branch names, confirmed content-identical before
  deletion, so nothing is lost by their absence here.

## What this ADR deliberately does not do

- It does not write the new token values into `tokens.css`. That is
  implementation, gated on `writing-plans` per `AGENTS.md`.
- It does not resolve the four-services-vs-six-tiles conflict.
- It does not define the content-adaptation mapping for FFI's crowdfunding-
  specific sections (`UrgentCampaigns`, the "Yang Baru"/"Pilihan Kami"
  campaign grids, `PrayerWall`) onto Makam's domain.
- It does not touch the Filament admin/operator/vendor panels.
- It does not change PRD requirements (`docs/product/prd-yiem-2026-09-18.md`)
  that reference the current visual system, e.g. its trust-element and
  hero-CTA content; those are checked against whatever the plan produces,
  not amended here.

## Alternatives considered

- **Keep ADR-0042's system-layer-only boundary and ask the owner to confirm
  a THIRD time before going further.** Rejected: two direct questions were
  already asked and answered this session with the specific new information
  (Kitabisa identity, structural scope) that would have changed the answer
  if it were going to. A third round on the same axis would be re-litigating
  a decision already made, which this repository's own operating norms
  treat as the wrong default once a concern has been raised and reaffirmed.
- **Silently resolve the four-services-vs-six-tiles conflict now** (e.g. by
  picking six because "exactly like FFI" was the more recent instruction).
  Rejected: `AGENTS.md` is this repository's binding instruction set, the
  four-card rule is written as a MUST with no gate-closure exception, and
  changing it is a scope decision, not a styling one — exactly what
  `brainstorming` exists to surface rather than have an agent decide
  in an ADR nobody was asked to approve for that specific point.
