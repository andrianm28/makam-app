# 02: Replace the three hand-rolled loading placeholders with `<x-mk.skeleton>`

**What to build:** A visitor on a slow connection sees the real
`<x-mk.skeleton>` component's shimmer placeholder — not a plain grey
pulsing `div` — while the renewal start page's two loading regions, the
cemetery directory's loading region, and the FAQ index's loading region
stream in their real data. Behaviour (what shows while loading, when it
stops showing) is unchanged; only the placeholder's implementation
becomes the shared component instead of three hand-written copies of the
same idea. A visitor with `prefers-reduced-motion` set sees the
component's own already-built static end state on all three pages, the
same as any other `<x-mk.skeleton>` instance.

**Note for whoever picks this up:** two of the three files this ticket
touches (`renewal/start.blade.php`, `faq/index.blade.php`) are also
touched by ticket 05 (visual-consistency audit), which runs in parallel
with this one and does not block on it. Expect a possible small merge
conflict in those two files if both land around the same time — resolve
by rebasing whichever lands second; there is no functional dependency
between the two changes, they just share a file.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The two hand-rolled placeholder `div`s in the renewal start page are
      each replaced by a real `<x-mk.skeleton>` instance, using whichever
      predefined `shape` (`text`/`card`/`media`/`section`) is the closest
      match to what each one currently renders. Exact pixel-height parity
      with the value being replaced is not required — the spec already
      accepts this as a small, known visual delta.
- [ ] The cemetery directory's one hand-rolled placeholder `div` is
      replaced the same way.
- [ ] The FAQ index's one hand-rolled placeholder `div` is replaced the
      same way.
- [ ] No hand-rolled `bg-[var(--mk-skeleton-base)] animate-pulse` markup
      remains anywhere in the codebase after this ticket (confirmed by a
      repo-wide search, not assumed from the four sites already known).
- [ ] Each page's existing loading-state test (or a new one, following
      the same seam its other tests already use — real HTTP request
      against real Livewire loading behaviour, not a mocked state)
      confirms the real component renders in place of the old markup.
- [ ] `bash ci/verify-docs.sh` passes.
