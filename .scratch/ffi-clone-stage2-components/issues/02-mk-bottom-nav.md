# 02: Build `<x-mk.bottom-nav>`, the mobile persistent tab bar

**What to build:** A mobile visitor below the `lg` breakpoint can reach
the site's five main sections (Beranda, Pemesanan, Perpanjangan, Akun,
Bantuan) from a persistent bottom tab bar, without scrolling back to a
header that's out of view. The active tab is unmistakable to a
screen-reader user (`aria-current="page"`, wrapped in a labelled `<nav>`
landmark) and to a visitor with a colour-vision deficiency (marked by
shape as well as colour, never colour alone). A desktop visitor above
`lg` never sees it — there's no scrolling-back problem there to solve.
This ticket delivers the component itself, demoable and verifiable in
isolation via direct component rendering — it is not wired into any real
screen yet (that's later work, out of scope here), and it is built
against a confirmed-empty viewport-bottom: nothing in the codebase today
renders a bottom-fixed sticky action bar under any name, so there is no
live element to test coexistence against. Document, don't solve, the
z-index relationship for whichever future ticket builds one.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] `<x-mk.bottom-nav>` exists as a new `<x-mk.*>` primitive, following
      the established `<x-mk.*>` conventions (no new prop-driven
      configurability beyond what's listed below — the five tabs are a
      fixed set, not slot-driven).
- [ ] Renders exactly five tabs, in order: Beranda (`/`), Pemesanan
      (`/pemesanan-makam`), Perpanjangan (`/perpanjangan`), Akun (`/akun`),
      Bantuan (`/bantuan`).
- [ ] Visible only below the `lg` breakpoint (`--breakpoint-lg: 64rem`) —
      hidden at `lg` and above.
- [ ] The active tab (matched against the current request path) is marked
      by both a colour change and a shape/visual change — never colour
      alone.
- [ ] The active tab's transition uses colour/opacity only, through the
      existing `--mk-duration-fast` (120ms) token — no animation library.
- [ ] The active tab's anchor carries `aria-current="page"`.
- [ ] The whole component is wrapped in `<nav aria-label="Navigasi
      utama">`.
- [ ] Uses the existing `--mk-z-bottomnav: 1100` token for its stacking
      context (already defined, currently unconsumed anywhere in the
      codebase — confirmed by direct search).
- [ ] A short comment in the component documents that `--mk-z-bottomnav`
      sits above `--mk-z-sticky-cta` (900) — the token whose own comment
      names it "wizard sticky footer," even though its one real consumer
      today (`stepper.blade.php`) uses it for a top-sticky progress
      header, not a bottom CTA bar — so whoever next builds a real bottom
      CTA bar knows the intended stacking order without re-deriving it.
- [ ] No literal hex/px anywhere in the file (`ci/verify-docs.sh` GATE
      2/3 must pass against it).
- [ ] A new `tests/Feature/View/Components/MkBottomNavTest.php` exists,
      following `MkCardTest.php`/`MkIconMedallionTest.php`'s established
      seam (`Illuminate\Support\Facades\Blade::render()`, asserting on the
      rendered HTML string) and covers: all five tabs present with correct
      hrefs, hidden-above-`lg` class present, the active tab's
      colour-and-shape marking (for at least two different current-path
      cases, not just one), `aria-current="page"` on the correct tab and
      absent from the others, and the `<nav aria-label="Navigasi utama">`
      wrapper.
