# 01: Header search bar

**What to build:** A desktop visitor sees a search input in the header,
centered between the logo and the nav links, matching FFI's real shape
(`h-10`, `rounded-full`, a search icon inside the input on the left,
Indonesian placeholder text — Makam's own copy voice, not FFI's literal
placeholder). Typing a term and submitting reaches the real cemetery
directory with that term applied as a real search — this reuses
`CemeteryDirectoryIndex`'s already-built search capability, not a new
search backend. A mobile visitor sees no change at all: FFI's own real
mobile header has no visible search bar either (confirmed by reading its
source before this ticket was written), so Makam's mobile header stays
exactly as it is today.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] A `<form>` + `<input type="search">` renders inside
      `header.blade.php`'s desktop bar only (`lg:` and up), positioned
      between the logo and the nav links.
- [ ] The input's visual shape matches FFI's real `DesktopHeader.tsx`:
      `h-10`, fully rounded, a search icon rendered inside the input via
      absolute positioning on the left, using this project's own existing
      icon-provenance convention (a real Heroicons glyph, not invented
      path data).
- [ ] Placeholder text is Indonesian and matches Makam's own copy voice
      (ADR-0041) — not a translation of FFI's literal placeholder.
- [ ] The input has a real accessible label (not just the placeholder) —
      confirm the exact mechanism (`aria-label`, associated `<label>`,
      etc.) against this codebase's own existing form-input conventions
      before implementing.
- [ ] Submitting the form with a real query performs a real GET request
      to the cemetery directory (`/pemakaman`) with the search term
      applied — read `CemeteryDirectoryIndex`'s real, current query-param
      name and search behavior directly before wiring this; do not assume
      the parameter is named `q` without checking.
- [ ] Submitting the form with the search term actually filters the real
      cemetery directory results — verified end to end, not just that the
      request reaches the right URL.
- [ ] The mobile header (`lg:hidden` bar) is unchanged — no search input
      added there, confirmed by a real diff review, not just "I didn't
      touch that file."
- [ ] No live-query/autocomplete JavaScript is introduced — a plain form
      submit only, matching FFI's own real desktop search behavior
      exactly (confirmed by reading `DesktopHeader.tsx`'s `onSubmit`
      handler: no debounced query, no live results dropdown).
- [ ] Keyboard-only: the input and its submit affordance are reachable in
      a sensible tab order and carry the project's existing global focus
      treatment — no bespoke focus styling invented for this one control.
- [ ] `tests/Feature/Livewire/Public/HomePageRouteTest.php` (the
      established seam for shared-layout header/footer content) gains
      real assertions: the search form's markup (input type, accessible
      label, form action target), and a second, real HTTP request
      submitting a real search term that reaches the cemetery directory
      and returns the expected filtered result — not just that the form
      exists.
- [ ] `bash ci/verify-docs.sh` passes.

**Note for whoever picks this up:** ticket 03 (footer redesign) also
touches `HomePageRouteTest.php`'s shared-layout assertions. No functional
dependency between the two — if both land close together, expect a small
rebase, resolved by whichever lands second, not a blocker to either.
