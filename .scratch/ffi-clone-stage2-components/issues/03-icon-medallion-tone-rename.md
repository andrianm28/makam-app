# 03: Rename `<x-mk.icon-medallion>`'s stale `tone` values

**What to build:** An engineer reading a call site that passes
`<x-mk.icon-medallion tone="...">` can tell what colour they're choosing
from the prop value itself, without cross-referencing `tokens.css` first.
Today the accepted values `earth` and `leaf` name a brand identity two
rebases gone (Earth brown, Leaf green) even though they now resolve to
FFI blue and Sage respectively — a caller has no way to know that from
the name. This ticket renames those two values to something
palette-honest, updates every real call site to the new names, and keeps
`brand` (already palette-agnostic — it names what it does, the brand
fill, not a colour) untouched. This is a self-contained rename: 4 files
touch the component in total (one is the definition itself), the blast
radius is small and fully enumerated below, not a wide refactor.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The exact new value names for what are currently `earth` and `leaf`
      are decided and documented (e.g. matching the token family names
      they resolve to, `primary`/`secondary` — the specific choice is
      this ticket's own decision to make, not prescribed here).
      `brand` is unchanged.
- [ ] `resources/views/livewire/public/home-page.blade.php`'s two explicit
      usages (`tone="earth"` at one call site, `tone="leaf"` at another)
      are updated to the new names.
- [ ] `resources/views/livewire/public/akun/akun-index.blade.php`'s four
      `<x-mk.icon-medallion>` usages currently pass no `tone` at all and
      silently inherit the component's default (today `earth`). This
      ticket decides, on purpose, what those four should render as, and
      makes each usage pass an explicit tone rather than continue relying
      on an unstated default — a rename must not leave these four
      silently riding whatever the new default happens to be.
      `card.blade.php` was checked and does **not** actually render an
      `<x-mk.icon-medallion>` (it only mentions the component's name in
      a comment) — no change needed there.
- [ ] `tests/Feature/View/Components/MkIconMedallionTest.php`'s four
      `tone="earth"` assertions are updated to the new value name(s), and
      the suite passes.
- [ ] `icon-medallion.blade.php`'s own doc comment (which currently
      explains "why `tone=\"leaf\"` is inside the Leaf cage, not an
      exception to it") is updated to stop naming a colour identity that
      no longer exists, while keeping whatever of that explanation still
      applies under the new name(s).
- [ ] `php artisan design:verify-filament-palette` and
      `bash ci/verify-docs.sh` both still pass — this ticket changes prop
      *values*, not any `tokens.css` entry, so neither should regress.
