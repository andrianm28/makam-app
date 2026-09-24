# 03: Marketplace restyle (listing + vendor detail)

**What to build:** A visitor browsing the marketplace sees a vendor
listing grid and a vendor detail page that match the same FFI grid →
detail structural pattern as the cemetery directory (confirmed analog:
FFI's `/explore/[category]` + `/campaign/[slug]`), restyled to match the
rest of the redesigned site, with the marketplace's existing
booking/checkout mechanics completely unchanged.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The vendor listing page's card grid, spacing, and typography match
      the site's FFI-aligned visual language, using this project's own
      existing `mk.*` primitives.
- [ ] The vendor detail page's layout matches the same detail-page pattern
      used for cemetery detail pages (ticket 02) — hero/gallery image,
      structured info blocks, call-to-action — so both detail-page types
      feel like the same product.
- [ ] The marketplace's existing add-to-cart/booking mechanics, pricing
      display, and vendor data are completely unchanged — this is a
      visual pass only.
- [ ] The gravestone-shaped SVG product-catalog icons (real product
      imagery for an actual headstone/memorial-product marketplace) are
      untouched by this ticket and unaffected by the marketing-imagery
      rule — they are catalog icons, not marketing photography.
- [ ] `MarketplaceIndexRouteTest` gains real assertions for the new
      grid markup; the existing scoped assertion for "no cart/checkout
      affordance" on the landing page (already rescoped in the prior
      batch to look past the shared-layout header) is not broken by this
      restyle.
- [ ] `ProductDetailRouteTest` gains real assertions for the new
      detail-page layout.
- [ ] `tests/browser/e2e-marketplace.spec.ts` and
      `tests/browser/e2e-marketplace-mobile.spec.ts` still pass, including
      the existing axe-exclude for the verified modal-backdrop contrast
      false positive — that exclusion is not removed or widened by this
      restyle.
- [ ] `bash ci/verify-docs.sh` passes.
