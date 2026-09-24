# Hero Scrim + OQ-K5 Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve OQ-K5 by redesigning `<x-mk.hero>` into a unified photo-with-overlay-text hero, backed by a new scrim token and a new worst-case contrast-verification method.

**Architecture:** Add `--mk-hero-scrim` (a linear-gradient token) to `tokens.css`. Add `docs/design/verify-hero-scrim-contrast.py`, which computes the alpha-composited worst-case colour (scrim over a pure-white photo) mathematically and asserts WCAG AA against it — not a token-pair comparison, since a photo isn't a token. Redesign `<x-mk.hero>` so the photo is the root visual layer with heading/CTA absolutely positioned over it behind the scrim, removing the old two-block layout and its now-dead mobile CTA-reorder mechanism. Record the decision in a new ADR.

**Tech Stack:** Laravel Blade, Tailwind CSS 4 (`@theme`/`@utility` tokens), Python 3 (contrast verification scripts, no new dependencies).

**Spec:** `.scratch/ffi-clone-pixel-fidelity/spec.md`; ticket `.scratch/ffi-clone-pixel-fidelity/issues/02-hero-scrim-oq-k5.md`.

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah
di bawah keluar dengan status 0.

    ~/.claude/skills/specflow/scripts/check-plan-headings.sh    <rencana ini> <task-brief>
    ~/.claude/skills/specflow/scripts/check-seam-constraints.sh <rencana ini> <task-brief>

## Global Constraints

- Do not build FFI's real auto-rotating multi-slide carousel (Framer Motion, touch/swipe, dot indicators) — static visual composition only.
- Do not touch `information-architecture.md`, the four-primary-service-card rule, or any page's copy/step structure.
- `design-system.md`'s existing "deliberately not done" hero note stays in place unmodified — additive supersession only, a note pointing to the new ADR added beside it.
- The new contrast-verification method must be a real, runnable check computing a worst-case composited colour mathematically — not a `verify-contrast.py`-style token-pair entry, since a photo is not a colour token.

---

### Task 1: Scrim token, verification method, and hero redesign

**Files:**
- Modify: `resources/css/tokens.css` (`--mk-hero-scrim`)
- Create: `docs/design/verify-hero-scrim-contrast.py`
- Modify: `resources/views/components/mk/hero.blade.php`
- Modify: `tests/Feature/View/Components/MkHeroTest.php`
- Create: `docs/adr/0045-hero-scrim-resolves-oq-k5.md`
- Modify: `docs/design/design-system.md` (additive note beside the OQ-K5 line)

**Interfaces:**
- Consumes: `--color-primary-600`/`700` (PR #358, already merged into this branch's base) for `<x-mk.button variant="primary">`'s own styling, unchanged by this task.
- Produces: `--mk-hero-scrim` (a `linear-gradient` CSS value, consumed via `bg-[image:var(--mk-hero-scrim)]` on the hero's scrim layer — no other component consumes it). `<x-mk.hero>`'s `image` prop is now required (throws `InvalidArgumentException` when omitted, matching `heading`'s existing precedent) — no later task in this plan; this is the last task.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk
task ini adalah component-level: `Illuminate\Support\Facades\Blade::render()`
against `tests/Feature/View/Components/MkHeroTest.php`, matching this
repo's own established pattern for `<x-mk.*>` primitives. Cakup SETIAP
perilaku task ini MELALUI seam itu: heading/image/CTA rendering, the
missing-heading and missing-image exceptions, the scrim layer's real
class string, the heading's new white colour, the old panel/reorder
classes' genuine absence, and DOM order. Untuk kendala worst-case
kontras, seam terpisah adalah `python3 docs/design/
verify-hero-scrim-contrast.py` dijalankan langsung, bukan lewat PHPUnit.

- [x] `--mk-hero-scrim` added to `tokens.css` §2.11 (component tokens): `linear-gradient(to top, rgb(0 0 0 / 0.65) 0%, rgb(0 0 0 / 0.65) 45%, rgb(0 0 0 / 0) 100%)`, documented with the full worst-case-alpha derivation and the honest accounting of what the reference site's own real technique actually is (bright-composition + dark text, not a scrim) versus why this token exists instead (no suitable photography yet, OQ-K3/OQ-K4).
- [x] `docs/design/verify-hero-scrim-contrast.py` created: extracts `--mk-hero-scrim`'s darkest alpha stop from `tokens.css` directly, computes the alpha-composited colour over a worst-case pure-white (`#FFFFFF`) background, and asserts WCAG AA (4.5:1) for white text against that computed colour. Run: `python3 docs/design/verify-hero-scrim-contrast.py`. Result: PASS, 6.98:1 (min 4.5).
- [x] `<x-mk.hero>` redesigned: photo is the root visual layer (`<picture>` first in DOM, `h-80 md:h-96 w-full object-cover`); scrim layer (`absolute inset-0 bg-[image:var(--mk-hero-scrim)] aria-hidden="true"`) sits between photo and text; text layer (`absolute inset-x-0 bottom-0 flex flex-col gap-4 p-8 md:p-12`) renders heading (now `text-neutral-0`, not `text-neutral-900`), slot, and CTA. `image` prop now throws `InvalidArgumentException` when null, matching `heading`'s existing precedent — no two-block fallback for a missing image. The old `order-last`/`md:order-none` mobile-reorder classes and the `bg-primary-50` panel are removed entirely (dead complexity once the CTA is always rendered over the photo, not below it).
- [x] `MkHeroTest.php` updated: existing heading/image/CTA/alt/missing-heading tests kept unchanged in substance; added `test_it_throws_without_an_image`; replaced `test_the_photo_renders_after_the_text_panel_on_mobile_only` (asserted the now-removed reorder mechanism) with `test_the_heading_and_cta_render_over_the_photo_via_the_scrim` (asserts the scrim's real class string, the heading's white colour, the old panel/reorder classes' genuine absence, and DOM order).
- [x] `docs/adr/0045-hero-scrim-resolves-oq-k5.md` created, following ADR-0044's own Status/Context/Decision/Consequences/"What this ADR deliberately does not do"/Alternatives-considered shape. Records the kamboja plan's own 13 Sep 2026 §4.3 correction honestly (the reference's real technique is bright-composition + dark text, not a scrim) and why this ADR adopts the scrim alternative instead (no suitable photography yet).
- [x] `design-system.md`'s OQ-K5 paragraph gets an additive "Resolved 24 Sep 2026 (ADR-0045)" note immediately after it — the original paragraph is unmodified.
- [x] Run `python3 docs/design/verify-contrast.py` — PASS, all 49 pairs (unaffected by this task, confirmed not just assumed).
- [x] Run `bash ci/verify-docs.sh` — RESULT: ALL DOC GATES PASS (19/19).
- [x] Blade-compile-check `hero.blade.php` via the Docker probe technique against the pinned CI app image (this host cannot run PHP 8.5 directly) — no syntax errors.
- [x] Commit.

---

**Self-review:**

- **Spec coverage:** every ticket acceptance-criterion checkbox is addressed above.
- **Placeholder scan:** none — this plan documents work already completed in a single continuous implementation pass (as a fork, not dispatching separate implementer subagents), not a hand-off to a fresh implementer.
- **Type consistency:** `--mk-hero-scrim`'s name matches exactly between `tokens.css`, `hero.blade.php`'s `bg-[image:var(--mk-hero-scrim)]` usage, and `verify-hero-scrim-contrast.py`'s own regex extraction.
