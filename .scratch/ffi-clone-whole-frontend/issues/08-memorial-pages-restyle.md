# 08: Memorial pages restyle (family-managed view + public QR view)

**What to build:** A family member managing a memorial profile, and a
visitor who scanned that memorial's QR code, both see FFI's visual
language applied to the memorial pages' existing structure. No FFI
equivalent exists for this module. The public memorial page is a
deliberate exception to the marketing-imagery rule elsewhere in this
initiative: a visitor arriving via a QR scan needs to identify the
actual memorial/grave they came to see, so this page's existing
grave-identifying imagery is functional, not marketing, and stays
exactly as it works today — the same carve-out already given to
cemetery detail pages (ticket 02).

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] Both `MemorialFamilyPage` and `MemorialPublicPage` use the site's
      FFI-aligned visual language (cards, spacing, typography, buttons)
      via this project's own existing `mk.*` primitives.
- [ ] The public memorial page's existing grave/memorial-identifying
      imagery is NOT removed or replaced under the marketing-imagery rule
      — this ticket explicitly documents why (functional identification
      need, not emotional marketing), matching the cemetery-detail
      carve-out already recorded in the parent spec.
- [ ] The existing visit check-in behavior, QR generation/scanning, and
      moderation rules are completely unchanged — verified by a real diff
      review.
- [ ] The family-managed view's existing edit/manage capabilities are
      completely unchanged.
- [ ] `MemorialPublicPageTest` gains real assertions for the new visual
      markers where they change; all existing behavioral assertions
      continue to pass unchanged.
- [ ] `MemorialFamilyPage` has no dedicated route test today — this
      ticket adds one (matching this repository's own existing
      route-test convention for public Livewire pages) covering at least
      the real visual change and that existing manage/edit behavior still
      works.
- [ ] `bash ci/verify-docs.sh` passes.
