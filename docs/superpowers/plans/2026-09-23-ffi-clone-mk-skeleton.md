# FFI Clone Stage 2 — `<x-mk.skeleton>` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `<x-mk.skeleton>`, a new loading-placeholder primitive in the `<x-mk.*>` component library, so a visitor sees structured placeholder content instead of a blank gap while a page section streams in.

**Architecture:** One new Blade component file following the exact `@props`/class-composition/`$attributes->merge()` convention every existing `<x-mk.*>` primitive already uses (see `button.blade.php`'s own file-header comment, which names itself the convention reference). Four shapes (`text`/`card`/`media`/`section`) are static class-string variants, never PHP-interpolated strings, matching the `@source`-scanner-safety discipline `card.blade.php`/`badge.blade.php` were bitten by twice before this repo's tests started asserting on rendered class strings directly.

**Tech Stack:** Laravel Blade components, Tailwind CSS 4 (`@theme`-generated utilities only, no arbitrary values), PHPUnit (`Illuminate\Support\Facades\Blade::render()` seam).

**Spec:** `.scratch/ffi-clone-stage2-components/issues/01-mk-skeleton.md` (ticket; seam and testing rationale drawn from the parent spec, `.scratch/ffi-clone-stage2-components/spec.md`)

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah
di bawah keluar dengan status 0. Controller yang membaca header ini: kalau
salah satu belum dijalankan, jalankan dulu; kalau ada yang gagal, perbaiki
rencananya, jangan melewati gerbangnya.

    /home/ubuntu/.claude/skills/specflow/scripts/check-plan-headings.sh    docs/superpowers/plans/2026-09-23-ffi-clone-mk-skeleton.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief
    /home/ubuntu/.claude/skills/specflow/scripts/check-seam-constraints.sh docs/superpowers/plans/2026-09-23-ffi-clone-mk-skeleton.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief

## Global Constraints

- `shape` prop: `text` (default), `card`, `media`, `section`.
- `text` shape renders `lines` placeholder lines (int, default 3) — `lines` is meaningless/ignored for the other three shapes.
- `count` prop (int, default 1) renders that many independent placeholder instances of whichever `shape` was chosen.
- `section-rhythm` (bool) is valid only on `shape="section"` and MUST be `true` whenever `shape="section"` is used — this is a hard constraint, not a default: rendering `shape="section"` without `section-rhythm` true is a misuse the component (or its test) must not silently accept as equivalent to a correct call.
- Root element always carries `aria-busy="true"`.
- Exactly one `sr-only` node is always present, carrying the `announce` prop's text (string, default `"Memuat…"`).
- All colour is `--mk-skeleton-base` / `--mk-skeleton-sheen` (already defined in `tokens.css`, resolving to `neutral-200`/`neutral-100`) — no colour prop on this component, no literal hex/px/arbitrary-Tailwind-value anywhere in the file. Both tokens are meant to be used together (a resting base tone plus a lighter sheen that sweeps across it), not just the base alone — a single-tone `animate-pulse` on `--mk-skeleton-base` alone would leave `--mk-skeleton-sheen` completely unused despite it existing specifically for this component.
- `prefers-reduced-motion`: **already handled globally**, not something this component implements itself. `tokens.css`'s existing `@media (prefers-reduced-motion: reduce)` block (§3, "REDUCED MOTION") applies `animation-duration: 1ms !important; animation-iteration-count: 1 !important;` to `*, *::before, *::after` universally — any animation this component uses is automatically collapsed to effectively-static the moment that media query matches, with zero component-specific code. The component's job is only to use a real CSS animation (not, say, a JS-driven one that would bypass this), so the existing global rule can do its job.
- Every internal shape/size class map MUST be a static literal PHP array (never built via string interpolation) — the specific defect class `card.blade.php`/`badge.blade.php` shipped twice, which the `@source` scanner cannot see because it reads file text, not executed PHP output.
- Out of scope, do not touch: `<x-mk.bottom-nav>` (separate ticket), `<x-mk.icon-medallion>`'s `tone` prop (separate ticket), any existing `<x-mk.*>` primitive, any `tokens.css` value, any real screen/page wiring this component into actual content (Stage 3 territory) — this component is built and tested in isolation only.
- **PHPUnit cannot run on this host.** `docs/agents/issue-tracker.md`'s "Baseline test route" section: worktrees ship without `vendor/`, a full `composer install` is forbidden on this host per `CLAUDE.md`, and the pinned deployed app image (`ghcr.io/andrianm28/makam-app`) does not carry PHPUnit (a dev-only dependency, confirmed absent from its `vendor/bin/` — same situation Stage 1 already hit with Pint). The real, authoritative test run happens in CI's "PHP (validate, lint, analyse, test)" job after pushing. Do not report a local PHPUnit pass — report `NOT TESTED locally, verified via CI` explicitly, per `AGENTS.md`'s rule against reporting `PASS` for a check that wasn't executed.

## File Structure

- **Create:** `resources/views/components/mk/skeleton.blade.php` — the component itself.
- **Modify:** `resources/css/app.css` — one new `@utility mk-skeleton-shimmer` (plus its `@keyframes`), placed beside the existing `@utility z-*`/`duration-*` block, following that exact established pattern (a semantic token gets a utility so `bg-secondary-50`-style primitive-reaching is never the easy path). No new `tokens.css` entry — both tokens (`--mk-skeleton-base`, `--mk-skeleton-sheen`) already exist.
- **Create:** `tests/Feature/View/Components/MkSkeletonTest.php` — its test file, following `MkCardTest.php`/`MkIconMedallionTest.php`'s exact seam and assertion style.

---

### Task 1: Build `<x-mk.skeleton>` and its test suite

**Files:**
- Create: `resources/views/components/mk/skeleton.blade.php`
- Modify: `resources/css/app.css`
- Test: `tests/Feature/View/Components/MkSkeletonTest.php`

**Interfaces:**
- Consumes: nothing from another task (only task in this plan).
- Produces: `<x-mk.skeleton shape="text|card|media|section" lines="{int}" count="{int}" section-rhythm="{bool}" announce="{string}">` — a self-contained component with no dependency on any other new Stage 2 component. Nothing in this plan is consumed by a later task.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk
task ini adalah `Illuminate\Support\Facades\Blade::render('<x-mk.skeleton
... />')`, mengikuti pola yang sudah ada di
`tests/Feature/View/Components/MkCardTest.php` dan
`MkIconMedallionTest.php` — merender komponen langsung dan mengassert pada
string HTML yang dihasilkan. Cakup SETIAP perilaku dan kondisi batas
komponen ini MELALUI seam itu: keempat nilai `shape`, `lines` pada
berbagai nilai (termasuk 1 dan sebuah nilai besar seperti 10), `count`
pada berbagai nilai, `section-rhythm` baik saat diberikan true pada
`shape="section"` maupun kondisi keliru (section tanpa section-rhythm),
`aria-busy="true"` selalu hadir, node `sr-only` dengan teks `announce`
default maupun custom, kelas warna dasar (`--mk-skeleton-base`/`-sheen`)
hadir, dan perilaku reduced-motion. Helper/PHP-map internal diuji secara
tidak langsung lewat seam ini, tidak pernah dengan memanggil PHP internal
komponen secara langsung. Nilai harapan dalam test adalah literal yang
sudah diketahui dari spec ini (string kelas Tailwind yang sebenarnya,
bukan dihitung ulang).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/View/Components/MkSkeletonTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-mk.skeleton> — the loading-placeholder primitive, design doc
 * §3 (FFI full-visual-clone), Stage 2 ticket 01.
 *
 * Class-string assertions are deliberate, not incidental: card.blade.php
 * and badge.blade.php both once built a Tailwind class by interpolating
 * a PHP variable, invisible to the @source scanner (it reads file text,
 * not executed PHP), and shipped completely unstyled while every
 * functional test still passed. Asserting the actual rendered class
 * string is what catches that here.
 */
final class MkSkeletonTest extends TestCase
{
    public function test_default_shape_is_text_with_three_lines(): void
    {
        $html = Blade::render('<x-mk.skeleton />');

        $this->assertStringContainsString('aria-busy="true"', $html);
        // three placeholder line elements
        $this->assertSame(3, substr_count($html, 'mk-skeleton-line'));
    }

    public function test_lines_prop_controls_line_count(): void
    {
        $one = Blade::render('<x-mk.skeleton :lines="1" />');
        $ten = Blade::render('<x-mk.skeleton :lines="10" />');

        $this->assertSame(1, substr_count($one, 'mk-skeleton-line'));
        $this->assertSame(10, substr_count($ten, 'mk-skeleton-line'));
    }

    public function test_card_shape_renders_a_single_card_placeholder_block(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="card" />');

        $this->assertStringContainsString('mk-skeleton-card', $html);
        $this->assertStringNotContainsString('mk-skeleton-line', $html);
    }

    public function test_media_shape_renders_a_media_placeholder_block(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="media" />');

        $this->assertStringContainsString('mk-skeleton-media', $html);
    }

    public function test_section_shape_requires_section_rhythm_true(): void
    {
        $withRhythm = Blade::render('<x-mk.skeleton shape="section" :section-rhythm="true" />');
        $this->assertStringContainsString('mk-skeleton-section', $withRhythm);
        $this->assertStringContainsString('py-section', $withRhythm);

        // Omitting section-rhythm on shape="section" must not silently
        // render as if it were true -- it's a misuse, and the component's
        // rendered output must make the missing rhythm class visible so
        // a caller notices, not paper over it.
        $withoutRhythm = Blade::render('<x-mk.skeleton shape="section" />');
        $this->assertStringNotContainsString('py-section', $withoutRhythm);
    }

    public function test_count_prop_renders_that_many_independent_instances(): void
    {
        $html = Blade::render('<x-mk.skeleton shape="card" :count="3" />');

        $this->assertSame(3, substr_count($html, 'mk-skeleton-card'));
    }

    public function test_aria_busy_and_sr_only_announce_are_always_present(): void
    {
        $default = Blade::render('<x-mk.skeleton />');
        $this->assertStringContainsString('aria-busy="true"', $default);
        $this->assertStringContainsString('sr-only', $default);
        $this->assertStringContainsString('Memuat', $default);

        $custom = Blade::render('<x-mk.skeleton announce="Memuat daftar makam…" />');
        $this->assertStringContainsString('Memuat daftar makam…', $custom);
    }

    public function test_uses_the_shimmer_utility_and_no_colour_prop(): void
    {
        $html = Blade::render('<x-mk.skeleton />');

        // mk-skeleton-shimmer (app.css) is the two-tone --mk-skeleton-base/
        // -sheen CSS animation utility -- its presence is what proves both
        // tokens are actually in play, not just the base alone, AND is the
        // sole hook tokens.css's existing global @media
        // (prefers-reduced-motion: reduce) rule needs (it collapses ANY
        // animation-duration to 1ms for *, *::before, *::after -- this
        // component does nothing reduced-motion-specific itself, and
        // must not: a JS-driven effect instead of a real CSS animation
        // would silently escape that global rule).
        $this->assertStringContainsString('mk-skeleton-shimmer', $html);
        $this->assertStringNotContainsString('style=', $html); // no inline colour override
    }
}
```

- [ ] **Step 2: Confirm the tests fail for the right reason**

PHPUnit cannot run on this host (see Global Constraints). Confirm instead
by reading: the component file does not exist yet, so every
`Blade::render('<x-mk.skeleton ... />')` call in the test above would
throw a "Component class not found" error if it could run. This is the
expected pre-implementation state — record it in the task report rather
than a real command's output.

- [ ] **Step 3: Add the shimmer utility to `app.css`**

In `resources/css/app.css`, immediately after the existing
`@utility duration-slower { transition-duration: var(--mk-duration-slower); }`
line (end of the `duration-*` block), insert:

```css
/* Skeleton shimmer -- --mk-skeleton-base/-sheen, tokens.css §2.9. A
 * two-tone gradient sweep, not a single-colour opacity pulse, so both
 * tokens are actually consumed. tokens.css's own global
 * @media (prefers-reduced-motion: reduce) rule (*, *::before, *::after)
 * collapses this animation's duration to 1ms automatically -- nothing
 * reduced-motion-specific belongs here or in the component. */
@utility mk-skeleton-shimmer {
  background-image: linear-gradient(
    90deg,
    var(--mk-skeleton-base) 0%,
    var(--mk-skeleton-sheen) 50%,
    var(--mk-skeleton-base) 100%
  );
  background-size: 200% 100%;
  animation: mk-skeleton-shimmer-sweep 1.5s ease-in-out infinite;
}

@keyframes mk-skeleton-shimmer-sweep {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
```

- [ ] **Step 4: Implement `<x-mk.skeleton>`**

Create `resources/views/components/mk/skeleton.blade.php`:

```blade
{{--
    resources/views/components/mk/skeleton.blade.php

    <x-mk.skeleton> — loading-placeholder primitive, design doc §3
    (FFI full-visual-clone, Stage 2 ticket 01). Follows button.blade.php's
    established convention: @props lists every prop with its default,
    class composition is base + shape, built once in PHP as static
    literal strings (never string-interpolated -- see the file-header
    comment on card.blade.php for why that specific mistake is dangerous
    here), merged once via $attributes->merge().

    Colour is always --mk-skeleton-base/-sheen (tokens.css), consumed
    through the mk-skeleton-shimmer utility (app.css) -- no colour prop,
    matching every other <x-mk.*> primitive's refusal to accept a raw
    colour override. Reduced motion needs no handling here: tokens.css's
    existing global @media (prefers-reduced-motion: reduce) rule
    collapses any animation-duration to 1ms automatically.
--}}
@props([
    'shape' => 'text',
    'lines' => 3,
    'count' => 1,
    'sectionRhythm' => false,
    'announce' => 'Memuat…',
])

@php
    $base = 'mk-skeleton-shimmer rounded-md';

    $shapeClasses = [
        'text' => 'mk-skeleton-line h-4 w-full mb-2 last:mb-0',
        'card' => 'mk-skeleton-card h-40 w-full rounded-lg',
        'media' => 'mk-skeleton-media aspect-video w-full rounded-lg',
        'section' => 'mk-skeleton-section h-64 w-full rounded-lg' . ($sectionRhythm ? ' py-section' : ''),
    ];

    $shapeClass = $shapeClasses[$shape] ?? $shapeClasses['text'];
    $instanceCount = $shape === 'text' ? 1 : max(1, (int) $count);
    $lineCount = $shape === 'text' ? max(1, (int) $lines) : 1;
@endphp

<div {{ $attributes->merge(['class' => 'block']) }} aria-busy="true">
    <span class="sr-only">{{ $announce }}</span>

    @for ($i = 0; $i < $instanceCount; $i++)
        @if ($shape === 'text')
            @for ($j = 0; $j < $lineCount; $j++)
                <div class="{{ $base }} {{ $shapeClass }}" aria-hidden="true"></div>
            @endfor
        @else
            <div class="{{ $base }} {{ $shapeClass }}" aria-hidden="true"></div>
        @endif
    @endfor
</div>
```

- [ ] **Step 5: Run the tests**

Attempt: `php artisan test tests/Feature/View/Components/MkSkeletonTest.php`

Expected: this will fail to run at all on this host (no `vendor/`, no
PHP 8.5) — confirm the failure is the environment, not a fixable local
issue, and record `NOT TESTED locally` in the task report. Do not attempt
a workaround that installs project dependencies on this host.

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/mk/skeleton.blade.php resources/css/app.css tests/Feature/View/Components/MkSkeletonTest.php
git commit -m "feat(design): add <x-mk.skeleton> loading-placeholder primitive

Four shapes (text/card/media/section), aria-busy + sr-only announce, a
new mk-skeleton-shimmer utility using both --mk-skeleton-base/-sheen as
a two-tone gradient sweep (not a single-colour pulse). Reduced motion
needs no component-specific handling -- tokens.css's existing global
@media (prefers-reduced-motion: reduce) rule collapses any animation
automatically. PHPUnit not runnable on this host -- verified via CI's
PHP job after push, per docs/agents/issue-tracker.md's baseline route."
```

- [ ] **Step 7: Push and confirm via CI**

```bash
git push -u origin feat/ffi-clone-mk-skeleton
```

Then check the resulting PR's (or branch's) CI run for the "PHP
(validate, lint, analyse, test)" job. This is the real, authoritative
test result for this task — read its actual output rather than assuming
green. If it fails, fix and push again; do not mark this task complete
until that CI job is confirmed green by reading its real result.
