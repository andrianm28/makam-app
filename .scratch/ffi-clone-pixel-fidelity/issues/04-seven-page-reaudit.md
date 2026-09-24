# 04: Seven-page visual re-audit against PR #358's corrected values

**What to build:** A visitor moving from the homepage to any of the seven
other public pages (booking wizard, renewal, marketplace, FAQ, akun,
cemetery directory, help centre) sees the same corrected FFI blue
(`primary-600`/`primary-700`, PR #358) and circular icon-medallion badges
the homepage now has — confirmed page by page, not assumed. Stage 3
ticket 05 already audited these same seven pages and found zero
findings, but that audit ran before PR #358's color/shape correction
existed, so it never checked against the values this ticket checks
against.

**Blocked by:** None (can start immediately) — reads already-merged
PR #358 state only; does not depend on tickets 01-03's new work.

**Status:** ready-for-agent

- [ ] Booking wizard (`/pemesanan-makam`): checked for any remaining
      pre-correction color value or icon-medallion usage; any finding
      fixed.
- [ ] Renewal (`/perpanjangan` and its sibling steps): same.
- [ ] Marketplace (`/marketplace`): same.
- [ ] FAQ (`/faq` and its sibling routes): same.
- [ ] Akun (`/akun` and its sibling routes): same.
- [ ] Cemetery directory (`/pemakaman`): same.
- [ ] Help centre (`/bantuan`): same.
- [ ] Same method as Stage 3 ticket 05: grep for hardcoded hex/px values
      outside `tokens.css`, arbitrary Tailwind values without a
      `var(--mk-*)` wrapper, and any remaining icon-medallion usage that
      assumed the old squircle shape (e.g. a hand-rolled shape override
      that fought the component's own class rather than relying on it).
- [ ] Every finding (or the explicit absence of one) is recorded per
      page in this ticket's PR description — a page audited with
      nothing found is a real, reportable outcome, not a skipped step.
- [ ] No page's step structure, field rules, validation, or copy changed
      as a side effect of any fix made here.
- [ ] Each touched page's own existing route-level test suite still
      passes, and gains an assertion for any real defect this ticket
      fixed.
- [ ] `bash ci/verify-docs.sh` passes (GATE 2/GATE 3 in particular).
