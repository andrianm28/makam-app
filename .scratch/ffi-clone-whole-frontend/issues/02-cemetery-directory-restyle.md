# 02: Cemetery directory restyle (listing + detail)

**What to build:** A visitor browsing the cemetery directory sees a
listing grid and a single-cemetery detail page that match FFI's real
filterable-grid → detail pattern (confirmed by reading FFI's own source:
`/explore/all`/`/search` for the listing, `/campaign/[slug]` for detail) —
card grid, spacing, typography, filter treatment, and detail-page layout
(hero image, structured info blocks, call-to-action) all restyled to
match the rest of the redesigned site, using only Makam's own existing
cemetery content and existing search/filter capability.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The listing page's card grid, spacing, and typography match the
      site's FFI-aligned visual language (the same token/component system
      already used on the homepage), using this project's own existing
      `mk.*` primitives rather than inventing new ones.
- [ ] The listing page's existing city/type/search (`q`) filters continue
      to work exactly as before — this ticket does not add, remove, or
      change filter behavior, only its visual presentation.
- [ ] The detail page's layout (hero image, structured info blocks,
      call-to-action) matches FFI's real detail-page pattern.
- [ ] Functional photography of real cemetery grounds stays on both the
      listing cards and the detail page's hero — this page is the
      explicit carve-out from the marketing-imagery rule (a customer needs
      to see what they're booking), not subject to the "no gambar
      kuburan" rule that governs homepage/marketing imagery.
- [ ] No cemetery data, copy, or pricing changes as a side effect.
- [ ] `CemeteryDirectoryIndexRouteTest` gains real assertions for the new
      grid/filter markup.
- [ ] `CemeteryDetailRouteTest` gains real assertions for the new
      detail-page layout.
- [ ] If no browser-level a11y/responsive test currently covers this page
      (none does today), add a minimal one covering the real visual
      change, or extend an existing suite — `ci/verify-docs.sh`'s own
      contrast/design-value gates apply regardless.
- [ ] `bash ci/verify-docs.sh` passes.
