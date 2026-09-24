# 05: Visual-consistency audit — the remaining public pages

**What to build:** A visitor moving from the homepage to any other public
page — booking wizard, renewal, marketplace, FAQ, akun, cemetery
directory, help centre — sees the same FFI-derived palette, typography,
card/button/icon-medallion treatment the homepage now has. No page feels
like it was left behind by the Stage 1/2 rebase. Since Stage 1 rebased
`tokens.css` *values* under unchanged *names*, and Stage 2 updated the
`x-mk.*` component library itself, most of these seven pages should
already display the new system correctly with zero code change — this
ticket's real job is confirming that's actually true per page, and fixing
whatever straggler it finds (a literal hardcoded value that predates the
token system, a component instance still using a Stage-2-superseded API
such as the old `earth`/`leaf` tone values, an arbitrary Tailwind value
that should be a token reference). No page's structure, step count, field
rules, validation, or copy changes as part of this ticket.

**Note for whoever picks this up:** two of the seven pages this ticket
covers (renewal start, FAQ index) are also touched by ticket 02 (skeleton
retrofit), which runs in parallel with this one and does not block on it.
Expect a possible small merge conflict in those two files if both land
around the same time — resolve by rebasing whichever lands second; there
is no functional dependency between the two changes, they just share a
file.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] Booking wizard (`/pemesanan-makam`): audited for any hardcoded
      design value or superseded component API; any finding fixed.
- [ ] Renewal (`/perpanjangan` and its sibling steps): same.
- [ ] Marketplace (`/marketplace`): same.
- [ ] FAQ (`/faq` and its sibling routes): same.
- [ ] Akun (`/akun` and its sibling routes): same.
- [ ] Cemetery directory (`/pemakaman`): same.
- [ ] Help centre (`/bantuan`): same.
- [ ] Every finding (or the explicit absence of one) is recorded per page
      in this ticket's PR description — a page audited with nothing found
      is a real, reportable outcome, not a skipped step.
- [ ] No page's step structure, field rules, validation, or copy changed
      as a side effect of any fix made here.
- [ ] Each touched page's own existing route-level test suite still
      passes, and gains an assertion for any real defect this ticket
      fixed (so a regression of that specific fix would be caught again).
- [ ] `bash ci/verify-docs.sh` passes (GATE 2/GATE 3 in particular, since
      this ticket exists to find and fix exactly what those gates check
      for).
