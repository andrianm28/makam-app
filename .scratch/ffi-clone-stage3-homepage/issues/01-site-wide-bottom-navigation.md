# 01: Wire `<x-mk.bottom-nav>` into the shared public layout, site-wide

**What to build:** A mobile visitor on ANY public page — homepage,
booking wizard, marketplace, renewal, FAQ, akun, cemetery directory, help
centre — sees the persistent 5-tab bottom navigation bar (Beranda,
Pemesanan, Perpanjangan, Akun, Bantuan), with the tab for the page they're
currently on marked active (shape + colour, `aria-current="page"`). The
existing hamburger menu in the header keeps working exactly as it does
today, unchanged, still reachable for Layanan Pemakaman and FAQ — the two
items the five tabs don't cover (ADR-0044 Amendment 1). A desktop visitor
never sees the bar (`lg:hidden`). Sticky wizard CTAs and page content on
every wired page respect the already-documented `z-sticky-cta` <
`z-bottomnav` stacking order and the `--mk-bottomnav-total` content
padding, so the fixed bar never overlaps real content on any page.

This is the prefactor-shaped ticket of this stage: one mechanical,
repeated change (pass the bottom nav's active-tab value alongside the
header's own `active` value, through every public page's existing
`->layout('layouts.app', [...])` call) applied once per public page
controller, plus one addition to the shared layout itself. Landing it
first, cleanly, means no other Stage 3 ticket has to also carry a slice
of "and wire the nav bar here too."

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] `<x-mk.bottom-nav>` renders in the shared public layout, alongside
      (not replacing) `<x-mk.header>`.
- [ ] Every public page passes its own active-tab value through the same
      `->layout('layouts.app', [...])` mechanism `<x-mk.header>`'s
      `active` prop already uses — a new, distinct key (the two
      components' active-tab vocabularies are disjoint: the header's four
      keys are `pemesanan`/`layanan`/`perpanjangan`/`faq`; the bottom
      nav's five keys are `beranda`/`pemesanan`/`perpanjangan`/`akun`/
      `bantuan`). A page maps onto whichever of the two vocabularies
      actually names it, and passes nothing (component default, `null`)
      for the other.
- [ ] Verified for every real public route that currently renders through
      the shared layout: homepage, booking wizard, marketplace, renewal
      start, FAQ index, akun, cemetery directory, help centre — each
      shows the bar with the correct tab (or no tab) marked active.
- [ ] The bar is `lg:hidden` on every one of those pages (confirmed, not
      assumed from the component's own existing test).
- [ ] No page's real content is visually overlapped by the now-fixed
      bottom bar — confirmed by checking each wired page actually applies
      (or already structurally satisfies) the `--mk-bottomnav-total`
      bottom padding/spacing the component's own documentation names as a
      Stage 3 wiring obligation.
- [ ] Any sticky element that coexists with the bar on a wired page
      (e.g. a wizard's sticky footer CTA, if one exists on a wired route)
      sits above it (`z-sticky-cta` < `z-bottomnav`) — confirmed on
      whichever real page actually has one, or noted as "no real page in
      scope has one yet" if none currently does.
- [ ] The existing hamburger menu's markup and behaviour are unchanged —
      no edit to `<x-mk.header>` itself, only the new component added
      beside it.
- [ ] Every route's own existing HTTP-level feature test
      (`HomePageRouteTest`, `BookingWizardRouteTest`,
      `MarketplaceIndexRouteTest`, `RenewalStartTest`,
      `FaqIndexRouteTest`, `AkunIndexRouteTest`,
      `CemeteryDirectoryIndexRouteTest`, `HelpCentreRouteTest`) gets a new
      assertion (or an extended existing one) confirming the bottom nav
      renders with the correct active tab on that page — this ticket does
      not re-test the component's own internals (`MkBottomNavTest`
      already covers those from Stage 2), only its presence and
      active-state correctness per page.
- [ ] `bash ci/verify-docs.sh` passes.
