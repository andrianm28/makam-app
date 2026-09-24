# 03: Footer redesign

**What to build:** A visitor scrolling to the bottom of any public page
sees a light-surface footer with links grouped under clear category
headings — matching FFI's real footer structure — instead of today's
dark inverse panel. This is a real, deliberate reversal of a prior
decision (the footer was intentionally upgraded to the dark inverse
treatment in an earlier batch), made explicitly per the owner's 1:1
pixel-fidelity direction, the same way the icon-medallion squircle-to-
circle reversal (PR #358) was.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The shared footer (`layouts/app.blade.php`) changes surface from
      `bg-primary-900`/inverse text to a light surface — confirm the
      exact existing token/utility name already in `tokens.css` for this
      (e.g. a `bg-neutral-100`-shaped surface) rather than inventing a
      new one, with `text-neutral-900`/`text-neutral-600` for
      body/secondary text.
- [ ] Content is restructured into grouped columns under real heading
      elements, matching FFI's real `Footer.tsx` pattern — but using
      ONLY Makam's own existing destinations: a "Bantuan" group (FAQ,
      Bantuan/Kontak) and a "Legal" group (Kebijakan Privasi, Syarat &
      Ketentuan) at minimum.
- [ ] No invented pages: Makam has no About/Careers/Press equivalents —
      the footer does not gain an "Informasi" column populated with
      pages that don't exist, and does not force FFI's exact four-column
      count if Makam's real content doesn't fill it.
- [ ] Social-media icons: dropped unless a real, already-established
      Makam social presence exists elsewhere in this codebase (checked
      before implementing, not assumed either way) — no icons linking to
      accounts that don't exist.
- [ ] The company name/address (already rendered today via
      `CompanyInfo`) stays present somewhere in the redesigned footer.
- [ ] The old dark-surface classes (`bg-primary-900`, inverse text
      utilities) are genuinely removed, not left alongside the new ones.
- [ ] Every link in the redesigned footer remains a real, working
      destination — no placeholder `href="#"`.
- [ ] Keyboard-only and screen-reader users: the new column headings are
      real heading elements (not just visually bold text), and every
      link keeps this project's existing global focus treatment.
- [ ] `tests/Feature/Livewire/Public/Legal/FooterLegalLinksRouteTest.php`
      and `tests/Feature/Livewire/Public/HomePageRouteTest.php` (both
      already assert real footer content) are updated: assert the new
      column headings and grouped links, and assert the old dark-surface
      classes are genuinely gone — not just that new light-surface
      classes were added.
- [ ] `bash ci/verify-docs.sh` passes.

**Note for whoever picks this up:** ticket 01 (header search bar) also
touches `HomePageRouteTest.php`'s shared-layout assertions. No functional
dependency between the two — if both land close together, expect a small
rebase, resolved by whichever lands second, not a blocker to either.
