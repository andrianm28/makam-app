# Whole-Frontend FFI 1:1 Visual Redesign — Spec

## Problem Statement

A visitor moving across Makam's public site sees an inconsistent experience:
the homepage and shared layout (header, footer, hero) now carry FFI's real
visual language after the pixel-fidelity batch (PRs #358–#363, merged and
deployed to dev/beta at commit `2bb06fac`), but every other public page —
cemetery directory, marketplace, the booking wizard, renewal, the account
area, FAQ, help centre, and the legal pages — still carries the older,
pre-redesign visual language. The site therefore does not read as one
coherent product; it reads as one redesigned page bolted onto a mostly
unchanged site. Separately, the homepage hero currently leads with a
facility/location photograph rather than a warm, human one, even though the
right photograph (a real, licensed "family memory" image) already exists in
the repository and is only used in a secondary section today.

## Solution

Extend FFI's real visual language — the colors, typography, spacing,
card/button/section rhythm already codified in `tokens.css` and the `mk.*`
Blade component library — across every public page, not just the homepage.
Where FFI has a genuinely strong structural analog for a Makam page type
(cemetery directory, marketplace, FAQ, help centre, legal pages), clone that
page's structure literally. Where it does not (the booking wizard, renewal,
the account area — domains FFI's donation-platform page types don't map
onto), apply FFI's visual language to Makam's own existing flow structure
without forcing a donation-checkout shape onto a grave-plot booking wizard.
On the homepage, promote the existing family-memory photograph to the
hero's primary image, demoting the facility-first photograph out of the
hero. Everywhere, keep a firm split already validated against a real-world
precedent (kamboja.co.id): the marketing/emotional register (hero,
homepage, category imagery) never shows closeup grave/tombstone imagery,
while functional product photography of actual cemetery grounds stays on
listing/detail pages, because a customer booking a specific cemetery
genuinely needs to see what they are booking.

## User Stories

1. As a first-time visitor, I want the homepage hero to lead with a warm,
   human photograph rather than a facility photograph, so that my first
   impression of Makam is reassuring rather than clinical.
2. As a first-time visitor, I want every page I click into from the
   homepage to look like it belongs to the same product, so that I don't
   wonder if I've been redirected to a different, older site.
3. As a visitor browsing the cemetery directory, I want the listing grid
   and filters to match the same card, spacing, and typography language as
   the homepage, so that browsing feels continuous with where I started.
4. As a visitor viewing a single cemetery's detail page, I want its layout
   (hero image, structured info blocks, call-to-action) to match FFI's real
   detail-page pattern, so that the page presents information in a clear,
   familiar rhythm.
5. As a visitor viewing a cemetery's detail page, I want to still see real
   photography of that cemetery's actual grounds, so that I can judge what
   I am booking before committing.
6. As a visitor browsing the marketplace, I want the vendor listing grid to
   match the same visual language as the cemetery directory and homepage,
   so that the whole site feels like one product.
7. As a visitor viewing a marketplace vendor's detail page, I want its
   layout to follow the same detail-page pattern as a cemetery's detail
   page, so that I don't have to learn two different page conventions.
8. As a visitor shopping for a headstone or grave-care product, I want the
   product catalog icons to stay as they are, so that browsing an actual
   memorial-product catalog isn't disrupted by an unrelated imagery rule.
9. As a customer starting the grave-plot booking wizard, I want its buttons,
   cards, spacing, and typography to match the rest of the redesigned site,
   so that starting a booking doesn't feel like leaving the product.
10. As a customer partway through the booking wizard, I want its existing
    step sequence and behavior to work exactly as before, so that a visual
    refresh doesn't introduce new confusion or risk to an in-progress
    booking.
11. As a customer starting a renewal, I want the renewal flow's visual
    language to match the booking wizard and the rest of the site, so that
    renewing feels as trustworthy as booking did.
12. As a customer partway through renewal, I want its existing step
    sequence, payment handling, and confirmation behavior to work exactly
    as before, so that a visual refresh doesn't put a real payment flow at
    risk.
13. As a logged-in customer viewing my account area, I want the order
    history and settings views to match the rest of the redesigned site's
    visual language, so that my account feels like part of the same
    product I booked through.
14. As a logged-in customer using the memorial/QR check-in module, I want
    its existing behavior preserved exactly, so that a visual refresh
    doesn't change how I record a visit.
15. As a visitor with a question, I want the FAQ page's accordion pattern to
    match FFI's real FAQ layout, so that finding an answer feels as
    polished as the rest of the site.
16. As a visitor looking for support, I want the help centre page's layout
    to match FFI's real static-page pattern, so that getting help feels
    consistent with the rest of the site.
17. As a visitor reading the privacy policy or terms of service, I want
    those pages to match FFI's real static-page pattern, so that legal
    content doesn't look like an afterthought bolted onto a redesigned
    product.
18. As a visitor, I want every redesigned page's real content, copy, and
    functionality to stay exactly what it was before, so that a visual
    refresh never quietly changes what I'm being told or offered.
19. As a keyboard-only visitor, I want every redesigned page to keep this
    project's existing global focus treatment and a sensible tab order, so
    that the redesign doesn't regress accessibility on any page it touches.
20. As a screen-reader user, I want every redesigned page's headings, labels,
    and landmarks to remain real semantic elements, not just visually
    styled text, so that the redesign doesn't regress how the page reads
    aloud.
21. As a mobile visitor, I want every redesigned page to keep working at
    phone width with no horizontal scroll and no broken layout, so that the
    redesign doesn't regress the mobile experience on any page.
22. As a visitor on any redesigned page, I want text and interactive
    elements to keep clearing WCAG AA contrast, so that a visual refresh
    never quietly makes the site harder to read.
23. As the project owner, I want the pages with no real FFI structural
    analog (the booking wizard, renewal, the account area) to keep their
    own proven flow structure, so that a visual refresh never risks a real
    booking, payment, or account interaction breaking.
24. As the project owner, I want the pages with a strong FFI structural
    analog (cemetery directory, marketplace, FAQ, help centre, legal) to be
    cloned literally, so that the parts of the site closest to FFI's own
    strengths get the full benefit of that fidelity.
25. As the project owner, I want the Filament admin, vendor, and operator
    panels left untouched by this initiative, so that an internal tool
    doesn't get redesigned to look like a public donation platform's
    storefront for no operational benefit.
26. As the project owner, I want the token values, component shapes, and
    ADR decisions already verified correct in the prior pixel-fidelity
    batch treated as the foundation, not redone from zero, so that this
    initiative spends its effort on the pages that still need it.
27. As the project owner, I want every page this initiative touches to keep
    passing its existing route-level test and `ci/verify-docs.sh`'s design
    gates, so that "looks like FFI now" claims are verified, not assumed.
28. As a visitor logging in or registering, I want the auth pages to match
    the rest of the redesigned site's visual language, so that signing in
    doesn't feel like leaving the product for an unstyled form.
29. As a family member who scanned a memorial QR code, I want the public
    memorial page to look consistent with the rest of the redesigned site,
    while still showing me the actual memorial/grave I came to see, so
    that the redesign doesn't strip away the identifying photo I need.
30. As a customer checking a certificate's status, viewing an invoice
    receipt, scheduling a visitation, or expressing pre-need interest, I
    want each of those pages to match the rest of the redesigned site's
    visual language, so that no corner of the product feels unfinished.

## Implementation Decisions

- **Foundation retained, not rebuilt.** `tokens.css`, the `mk.*` Blade
  component library, `design-system.md`, and ADR-0043/ADR-0044/ADR-0045
  stand as the correct foundation. This initiative is a fresh *design*
  pass — deciding what the rest of the site should look like — built on
  that existing architecture, not a rebuild of the architecture itself.
- **Hero image swap.** The homepage hero's primary image becomes the
  existing family-memory photograph already in the repository; the
  existing facility/location photograph is demoted out of the hero (it may
  remain elsewhere in the homepage, or be dropped, at the implementer's
  discretion within the hero component's existing API).
- **Cemetery directory (listing + detail).** Restyled to FFI's real
  filterable-grid pattern for the listing (card grid, category/city filter
  treatment) and FFI's real detail-page pattern for a single cemetery
  (hero image, structured info blocks, call-to-action), using only Makam's
  existing content and existing search/filter capability. No new backend
  query behavior is introduced.
- **Marketplace (vendor listing + vendor detail).** Restyled to the same
  grid → detail structural pattern as the cemetery directory, since both
  map to the same FFI analog (a filterable card grid leading to a detail
  page). The marketplace's existing booking/checkout mechanics are
  unchanged — this is a visual pass only.
- **Marketplace product-catalog icons are unaffected.** The
  gravestone-shaped SVG icons used as catalog imagery for an actual
  headstone/memorial-product marketplace are a distinct case from
  marketing imagery and are out of scope for the photography rule below.
- **Booking wizard (multi-step grave-plot booking).** FFI's visual language
  (color, spacing, card style, button shapes, progress/step-indicator
  treatment) is applied to the wizard's existing step structure. The step
  sequence, its content, and its underlying booking/availability behavior
  are unchanged — FFI's real donation-checkout flow (amount + payment
  method selection) does not structurally map onto grave-plot selection
  with real-time availability state, so no attempt is made to reshape the
  wizard's steps to match it.
- **Renewal flow.** Same visual-language-only treatment as the booking
  wizard, applied to the renewal flow's existing step structure (start,
  payment, confirmation). Its existing payment handling and confirmation
  behavior are unchanged.
- **Account area (akun).** FFI's dashboard/list visual language is applied
  to the account area's existing tabs and list structure (order history,
  settings). The memorial/QR check-in module keeps its existing behavior
  exactly as it is today — FFI has no equivalent module to draw from.
- **FAQ, help centre, and legal pages.** Cloned literally to FFI's real
  static-page and FAQ-accordion patterns, since these are the strongest
  available structural analogs, using Makam's own existing FAQ, help, and
  legal content verbatim — no content is translated from FFI's own copy.
- **Auth pages (login, register, forgot/reset password).** Cloned literally
  to FFI's real auth-form pattern — a strong analog, since FFI has its own
  `/login` and `/register` pages. Existing authentication behavior,
  validation, and copy are unchanged.
- **Memorial pages (family-managed view and the public, QR-scanned view).**
  FFI's visual language applied to both existing views; no FFI equivalent
  exists for either. The public memorial page is a distinct case for the
  photography rule below: a family member arriving via a QR scan needs to
  identify the actual memorial/grave they're visiting, so this page's
  existing grave-identifying imagery is functional, not marketing, and
  stays — the same carve-out already given to cemetery detail pages.
- **Small transactional utility pages (certificate status, invoice
  receipt, visitation scheduling, pre-need interest capture).** FFI's
  visual language applied to each page's existing structure; none has a
  direct FFI analog. Existing behavior on each is unchanged.
- **Photography rule, applied consistently across every page this
  initiative touches.** The marketing/emotional register (hero sections,
  homepage imagery, category imagery) never shows closeup grave/tombstone
  imagery. Functional product photography of real cemetery grounds
  (already existing in the repository, already governed by its own
  existing "daylight, no people, no religious iconography" convention)
  stays on cemetery listing/detail pages, where it serves a real
  purchasing decision rather than the marketing register.
- **Filament admin, vendor, and operator panels are explicitly untouched**
  by this initiative — they are a separate system with their own existing
  design convention (the generated Filament palette mirroring select
  token values), not part of the public storefront this initiative
  redesigns.
- **No backend or domain logic changes anywhere in this initiative.** This
  is a visual/presentation-layer redesign across the public site. Any real
  defect found incidentally during implementation (as happened during the
  prior pixel-fidelity batch, e.g. the WCAG link-contrast regression it
  caught and fixed) is fixed and reported explicitly, not silently
  patched over and not left unfixed to preserve scope purity.
- **A new ADR records this initiative's scope**, additive to
  ADR-0043/ADR-0044/ADR-0045 per this repository's established
  amendment-only ADR convention: the prior batch's scope (homepage +
  shared layout) is expanded to the whole public frontend, and the hero
  image swap is recorded as a deliberate reversal of which existing photo
  leads the homepage.

## Testing Decisions

- **Seams under test — the existing route-level Feature test for each
  page group is the primary seam**, matching this repository's own
  established convention for "does this page now look like FFI" claims:
  - Homepage + hero image swap: `HomePageRouteTest`
  - Cemetery directory: `CemeteryDirectoryIndexRouteTest`,
    `CemeteryDetailRouteTest`
  - Marketplace: `MarketplaceIndexRouteTest`, `ProductDetailRouteTest`
  - Booking wizard: `BookingWizardRouteTest`
  - Renewal: `RenewalStartTest`, `RenewalPaymentTest`,
    `RenewalConfirmationTest`
  - Account area: `AkunIndexRouteTest`, `CareHistoryPageRouteTest`
  - FAQ: `FaqIndexRouteTest`, `FaqArticleDetailRouteTest`
  - Help centre: `HelpCentreRouteTest`
  - Legal pages: `PrivacyPolicyRouteTest`, `TermsOfServiceRouteTest`,
    `FooterLegalLinksRouteTest`
  - Auth pages: `AuthRouteTest`, `LoginPageTest`, `RegisterPageTest`,
    `PasswordResetTest`
  - Memorial pages: `MemorialPublicPageTest` for the public view; the
    family-managed view (`MemorialFamilyPage`) has no dedicated route test
    today — the ticket that touches it adds one, matching this
    repository's own existing route-test convention, rather than shipping
    a visual change with no seam covering it.
  - Small utility pages: `CertificateStatusPageTest`,
    `InvoiceReceiptPageTest`, `VisitationPageTest`,
    `PreNeedInterestPageTest`
  - Shared hero component: `tests/Feature/View/Components/MkHeroTest.php`
    (the image-prop change), and any other `<x-mk.*>` primitive test whose
    component is touched.
- **Real WCAG contrast, responsive-breakpoint, and rendered-visual
  verification cannot be done through server-rendered assertions alone.**
  The existing Playwright browser suite is the seam for these:
  `e2e-home.spec.ts`, `e2e-booking.spec.ts`/`e2e-booking-mobile.spec.ts`/
  `e2e-booking-loading-states.spec.ts`, `e2e-marketplace.spec.ts`/
  `e2e-marketplace-mobile.spec.ts`, `e2e-renewal.spec.ts`/
  `e2e-renewal-external.spec.ts`, `e2e-faq.spec.ts`, `e2e-akun.spec.ts`,
  `e2e-responsive-breakpoints.spec.ts`, `e2e-a11y-interaction.spec.ts`.
  Extend these per page group rather than hand-rolling new contrast math;
  a real axe-core false positive (already precedented in
  `e2e-marketplace.spec.ts`'s narrowly-scoped `AxeBuilder().exclude(...)`)
  is handled the same documented way, never by silently changing a color
  value that was never actually wrong.
- **What makes a good test here:** assert real external behavior — the
  rendered markup and classes reflecting the new visual language, a real
  HTTP round-trip through the real route, and that existing content,
  copy, and functionality did not regress. Never assert internal
  implementation details of how a Blade partial happens to be structured.
- **Prior art**: the already-merged PR #358–#363 batch is the direct
  precedent for this repository's own testing style for these claims —
  e.g. `HomePageRouteTest`'s service-tile and hero assertions, and
  `e2e-marketplace.spec.ts`'s axe-exclude pattern for a verified false
  positive. Reuse that pattern per page group rather than inventing a new
  testing style.
- **`bash ci/verify-docs.sh` must pass for every unit of work** (GATE 1
  WCAG contrast, GATE 2 no hardcoded design values, GATE 3 no arbitrary
  Tailwind values, GATE 11 no raw z-index, GATE 12 no unreplaced focus
  suppression) — the same mechanical enforcement already governing the
  prior batch, unchanged by this initiative.

## Out of Scope

- The Filament admin, vendor, and operator panels — no visual changes.
- Any backend/domain logic, pricing, availability, payment, or
  notification behavior on any page — this initiative is visual/
  presentation-layer only.
- Sourcing or licensing new photography. The existing family-memory and
  cemetery-grounds photographs already in the repository are used as-is;
  sourcing additional stock photography is a separate future decision.
- The pre-existing `storage:link` wiring gap for admin-uploaded
  marketplace product photos — a known, unrelated issue, not fixed here.
- Restructuring the booking wizard's or renewal's actual step sequence or
  domain logic to match FFI's donation-checkout flow — visual language
  only, per the confirmed domain mismatch between grave-plot booking and
  donation checkout.
- Any FFI page type with no Makam equivalent: fundraiser/campaign
  authoring, a zakat calculator, or a messaging inbox — none of these are
  built.

## Further Notes

- This spec is deliberately an umbrella for a wide initiative. Given its
  size, it is broken into per-wave tickets via `/specflow:to-tickets`
  before implementation, in this priority order: **Wave 1** — homepage
  hero image swap and any remaining shared-layout gaps; **Wave 2** —
  cemetery directory, marketplace, and auth pages (the strongest FFI
  analogs, highest customer-facing value); **Wave 3** — booking wizard,
  renewal, the account area, memorial pages, and the small transactional
  utility pages (certificate status, invoice receipt, visitation,
  pre-need interest — all visual-language-only, lower structural risk);
  **Wave 4** — FAQ, help centre, and legal pages (simplest, strong
  analogs, lowest priority since already functional). The page inventory
  above was validated directly against the real Livewire component tree
  (`app/Livewire/Public/*`) rather than carried over assumed-complete from
  the prior batch's narrower seven-page audit, which had missed the auth,
  memorial, certificates, invoices, visitation, and pre-need page groups
  entirely.
- The prior FFI pixel-fidelity batch (PRs #358–#363, merged and deployed
  to dev and beta at commit `2bb06fac` on 24 Sep 2026) is the confirmed-
  correct foundation this initiative builds on. Its real, independently
  verified decisions — FFI-accurate token colors, the icon-medallion
  circle shape, the header search bar, the hero scrim (ADR-0045, resolving
  OQ-K5), and the light-surface footer — are not revisited here.
- The kamboja.co.id research behind the photography rule found that even
  a funeral-services company with a genuine business need to show
  graveside documentation keeps that imagery in a separate, clearly-
  scoped section, never in its marketing/hero register. This spec's
  photography rule follows that same real-world pattern rather than
  banning cemetery imagery outright, which would work against Makam's own
  customers' real need to see what they are booking.
