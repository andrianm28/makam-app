# 04: Homepage featured/verified section, trust relocation, and old-section cleanup

**What to build:** A homepage visitor now sees the "lokasi
terverifikasi" and "harga transparan" trust badges within the first
screenful of real content — moved into a new "featured/verified TPU/TPS"
section that also carries forward the substance of the old "how it
works" and "trust/safety" copy (redistributed, not deleted — some of it
lands here, the rest in the FAQ highlights section). This closes the PRD
requirement the design doc's own compliance table names: "elemen
kepercayaan ... di area pertama."

Once this section is in place, the now-fully-superseded old "how it
works", "featured cemeteries", and "trust/safety" sections come out. The
homepage's section order now matches the design doc's §4.1 mapping
exactly, end to end: urgent banner → hero → secondary CTAs → plot
availability → services → urgent TPU/TPS → newest TPU/TPS →
featured/verified TPU/TPS (with the trust badges) → family warmth → FAQ
highlights (carrying its share of the redistributed copy) → CS CTA. No
`PrayerWall`-equivalent section exists anywhere (there never was one to
remove — Makam has no honest equivalent, so none was added).

**Blocked by:** 03 (this ticket's cleanup step removes sections that 03's
new sections replace — they can't come out before all three replacements,
including this ticket's own, exist)

**Status:** ready-for-agent

- [ ] A new "featured/verified TPU/TPS" section renders real, published,
      verified cemeteries in FFI's card-grid treatment (read the real,
      current FFI reference markup for the visual pattern at
      implementation time), positioned immediately after ticket 03's
      "newest published" section.
  - [ ] The "lokasi terverifikasi" badge (already-decided definition:
        active capability profile, evidence present — unchanged from the
        existing featured-cemeteries section's own definition) and
        "harga transparan" indicator both render on qualifying cards in
        this section.
  - [ ] Same empty-state/provider-unavailable discipline as the homepage's
        other real-data sections.
- [ ] The substance of the old "how it works" explanation and "trust/
      safety" copy is redistributed — verified as actually present
      somewhere real (this new section and/or the FAQ highlights
      section), not silently dropped. Where exactly each piece of copy
      lands is this ticket's own call to make and document, not decided
      in this ticket file.
- [ ] The old "how it works" section is removed.
- [ ] The old "featured cemeteries" section is removed (its real-data
      responsibility is now split across ticket 03's two sections and
      this ticket's own).
- [ ] The old "trust/safety" section is removed (its badges now live in
      this ticket's new section; its explanatory copy redistributed per
      the point above).
- [ ] The homepage's final section order, read top to bottom, matches:
      urgent banner, hero, secondary CTAs, plot availability, services,
      urgent TPU/TPS, newest TPU/TPS, featured/verified TPU/TPS, family
      warmth, FAQ highlights, customer-service CTA — verified directly
      against the rendered page, not assumed from the diff.
- [ ] Every one of the ten mandatory states (`design-system.md` §6.1–
      §6.10) that applied to any now-removed or now-added section still
      applies correctly on the new page — re-verified against the new
      markup, not assumed to have carried over from the old sections
      automatically.
- [ ] `HomePageRouteTest` gains assertions for: the featured/verified
      section's presence, order, and trust-badge rendering; the full
      final section order (one assertion checking all eleven positions in
      sequence, not just each section's independent presence); that none
      of the three removed sections' old heading IDs
      (`how-it-works-heading`, `featured-cemeteries-heading`,
      `trust-heading`) remain in the rendered output.
- [ ] `bash ci/verify-docs.sh` and `php artisan blade:verify-content-survival`
      both pass against the changed view.
- [ ] The design doc's §7 PRD-compliance table's two "Closed in Stage 3"
      rows (secondary CTAs in the hero area, trust element in the first
      screenful) are re-confirmed against the real merged state, not just
      the plan, per the design doc's own §8 verification requirement for
      this stage.
