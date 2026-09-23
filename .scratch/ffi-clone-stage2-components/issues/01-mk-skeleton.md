# 01: Build `<x-mk.skeleton>`, the loading-placeholder primitive

**What to build:** A visitor on a slow connection sees a structured
loading placeholder — not a blank gap, not a layout jump — while a
section of a page streams in. A screen-reader user hears that the region
is loading. A visitor with `prefers-reduced-motion` set sees the
placeholder's base colour with no pulse animation. The component supports
four shapes callers actually need: a few lines of placeholder text, a
card-shaped block, a media/image-shaped block, and a whole section
(mandatory rhythm spacing so it doesn't visually collapse against
neighbouring content). This ticket delivers the component itself,
demoable and verifiable in isolation via direct component rendering — it
is not wired into any real screen yet (that's later work, out of scope
here).

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] `<x-mk.skeleton>` exists as a new `<x-mk.*>` primitive, following the
      same `@props`/class-composition/`$attributes->merge()` convention
      `button.blade.php`'s own file-header comment documents for every
      primitive after it.
- [ ] `shape` prop accepts `text` (default), `card`, `media`, `section`.
- [ ] `text` shape renders `lines` placeholder lines (default 3).
- [ ] `count` prop renders that many independent placeholder instances
      (default 1).
- [ ] `section-rhythm` is a boolean, valid only on the `section` shape,
      and is mandatory `true` whenever `shape="section"` is used.
- [ ] Root element always carries `aria-busy="true"`.
- [ ] Exactly one `sr-only` node is always present, carrying the
      `announce` prop's text (default `"Memuat…"`).
- [ ] All colour is `--mk-skeleton-base`/`--mk-skeleton-sheen` (already
      defined in `tokens.css`) — no colour prop, no literal hex/px
      anywhere in the file (`ci/verify-docs.sh` GATE 2/3 must pass against
      it).
- [ ] When `prefers-reduced-motion` is set, the component shows its base
      colour statically with no pulse animation — same convention
      `tokens.css`'s existing shadow rules already use for reduced motion.
- [ ] A new `tests/Feature/View/Components/MkSkeletonTest.php` exists,
      following `MkCardTest.php`/`MkIconMedallionTest.php`'s established
      seam (`Illuminate\Support\Facades\Blade::render()`, asserting on the
      rendered HTML string) and covers every shape, `lines`, `count`,
      `section-rhythm`'s mandatory-true constraint, `aria-busy`, the
      `sr-only` announce text, and the reduced-motion class/state.
- [ ] Every `$shapes`/similar internal class map is a static literal
      string array (not built via PHP string interpolation) — the same
      `@source`-scanner defect class `MkCardTest.php`'s own file-header
      comment documents, and the new test file asserts the actual
      rendered class string for this reason, not just that "something"
      rendered.
