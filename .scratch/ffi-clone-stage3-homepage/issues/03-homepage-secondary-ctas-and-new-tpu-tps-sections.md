# 03: Homepage secondary CTAs + urgent/newest TPU-TPS sections

**What to build:** A homepage visitor sees three new secondary-CTA text
links below the services card grid — Perpanjang Makam, Layanan
Pemakaman, and Wakaf Tanah — reusing the exact placement and visual
weight the homepage's own existing code comment already documents for
its prior "Lihat TPU & TPS" link (never competing with the hero's one
primary CTA, never placed inside `<x-mk.hero>`). Below the services
section, the visitor now also sees two new sections built from Makam's
real cemetery data, styled in FFI's card-grid treatment: TPU/TPS with
urgent availability, and the newest published TPU/TPS. Both sections
degrade the same honest way the existing featured-cemeteries section
already does when there's no data to show (hide the section entirely,
never fabricate content) — same `design-system.md` §6.2 empty-state row,
same provider-unavailable discipline as the homepage's existing
try/catch-guarded queries.

This ticket does NOT remove the old "how it works" or "featured
cemeteries"/"trust safety" sections yet — they stay in place, unchanged,
for now. The new sections land in their target position (between
services and the old sections), so the homepage temporarily carries both
old and new content in this intermediate state. Ticket 04 removes what's
now fully superseded, once its own remaining new section (featured/
verified, carrying the relocated trust badges) is also in place — the old
sections can't come out before all three of their replacements exist.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] Three secondary-CTA text links render below the services grid:
      Perpanjang Makam (`/perpanjangan`), Layanan Pemakaman
      (`/marketplace`), and Wakaf Tanah. No route to a Wakaf Tanah
      destination exists in this codebase today — find the real one if it
      exists under a name this ticket didn't search for, or treat this as
      a named, undecided gap and say so explicitly in the PR rather than
      inventing a URL.
  - [ ] All three links use the same placement/visual-weight pattern the
        homepage's own existing "Lihat TPU & TPS" precedent comment
        documents — not a new pattern.
- [ ] A new "urgent-availability TPU/TPS" section renders real,
      published cemeteries whose availability the platform's own
      urgent-availability rule currently flags, styled in FFI's
      restyled card-grid treatment (read the real, current FFI reference
      markup for the visual pattern at implementation time — this
      ticket's brief does not hand you literal FFI markup to copy).
  - [ ] Renders nothing (section hidden entirely) when no cemetery
        currently qualifies as urgent-availability — verified against
        real seeded data, not a mocked empty state.
  - [ ] A query failure here degrades the same way the homepage's
        existing featured-cemeteries/FAQ-highlights queries already do:
        reported, section hidden, rest of the page still renders.
- [ ] A new "newest published TPU/TPS" section renders the most-recently
      published real cemeteries, same visual treatment, same
      empty/failure discipline as above.
- [ ] The existing "how it works", "featured cemeteries", and "trust
      safety" sections are untouched by this ticket (explicitly verified,
      not just "not mentioned") — their removal is ticket 04's job.
- [ ] `HomePageRouteTest` gains new assertions (following its own
      existing methods as the template) for: the three secondary-CTA
      links' presence, href, and placement; both new sections' presence,
      order (after services, before the old "how it works" section), and
      real-data content; both new sections' empty-state and
      provider-unavailable behaviour.
- [ ] `bash ci/verify-docs.sh` and `php artisan blade:verify-content-survival`
      both pass against the changed view.
