# Brand Visual Refresh — Phase 1 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-anchor the Earth (primary) and Leaf (secondary) color ramps to the real official logo (resolving OQ-12), regenerate every downstream consumer of those values, and build the two new interactive primitives (`<x-mk.hero>`, and documentation confirming `<x-mk.card interactive as="a">` already covers nav-card usage) that later phases will apply to real pages.

**Architecture:** A new, reusable ramp-generation script (`docs/design/brand/generate-ramp.php`, following the same "plain CLI tool, exercised directly" convention as the repo's existing `sample-logo-colours.php`/`FilamentPaletteGenerator`) derives an 11-shade tint/shade scale from a single anchor color, holding hue+saturation fixed and interpolating lightness along the SAME position-based curve the current, already-AA-verified primary ramp uses. Its output becomes the new `tokens.css` values directly — no hand-typed hex. Downstream, `design:generate-filament-palette` regenerates the admin panel's color array from the new tokens.css (existing tooling, not new). No application logic changes — this phase touches design tokens, documentation, and one new presentational Blade component.

**Tech Stack:** PHP 8.5 (pinned CI image), Laravel 13's Blade/Filament tooling already in the repo, no new dependencies.

**Spec:** `docs/superpowers/specs/2026-08-21-brand-visual-refresh-design.md`

## Global Constraints

- Every color pairing that ends up used for white-text-on-fill must clear 4.5:1 (WCAG AA, normal text) — verified by the ramp script's own output, not assumed.
- Leaf (secondary) stays tint/accent-only — never a filled button, badge, or alert (design-system.md §1.2b, unchanged by this plan).
- No hardcoded hex values anywhere outside `tokens.css` (`ci/verify-docs.sh` Gates 1-2 already enforce this).
- `App\Support\Design\FilamentPaletteGenerator`'s generated file must never drift from `tokens.css` (`design:verify-filament-palette` must pass).
- This plan's scope is Phase 1 (Foundation) only, per the spec's own phasing — no page layout changes, no application of the new primitives to a live page yet.

---

### Task 1: Ramp-generation script and final color values

**Files:**
- Create: `docs/design/brand/generate-ramp.php`
- Test: none (this is a CLI generation tool in the same category as `sample-logo-colours.php`, which also has no test file — its output is verified by running it and checking the printed contrast numbers, the same verification method this task uses)

**Interfaces:**
- Produces: the exact hex values for `--color-primary-{50..950}` and `--color-secondary-{50..950}`, consumed by Task 2.

- [ ] **Step 1: Write the ramp-generation script**

```php
<?php

declare(strict_types=1);

// Usage: php generate-ramp.php <anchor-hex> <family-name>
// Derives an 11-step 50-950 tint/shade ramp from a single anchor color,
// matching the lightness-step CURVE (not absolute L values) of the
// existing tokens.css primary ramp — already proven to produce an
// accessible result end-to-end — holding hue+saturation fixed at the
// anchor's own HSL values. Prints each shade's hex plus its WCAG
// contrast ratio against white (the fill/button usage check).

function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function rgbToHsl(int $r, int $g, int $b): array
{
    $r /= 255;
    $g /= 255;
    $b /= 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) {
        return [0.0, 0.0, $l];
    }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match ($max) {
        $r => fmod((($g - $b) / $d), 6),
        $g => (($b - $r) / $d) + 2,
        default => (($r - $g) / $d) + 4,
    };
    $h *= 60;
    if ($h < 0) {
        $h += 360;
    }

    return [$h, $s, $l];
}

function hslToRgb(float $h, float $s, float $l): array
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60 => [$c, $x, 0],
        $h < 120 => [$x, $c, 0],
        $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c],
        $h < 300 => [$x, 0, $c],
        default => [$c, 0, $x],
    };

    return [
        (int) round(($r + $m) * 255),
        (int) round(($g + $m) * 255),
        (int) round(($b + $m) * 255),
    ];
}

function relativeLuminance(int $r, int $g, int $b): float
{
    $chan = function ($v) {
        $v /= 255;

        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $chan($r) + 0.7152 * $chan($g) + 0.0722 * $chan($b);
}

function contrastRatio(array $rgb1, array $rgb2): float
{
    $l1 = relativeLuminance($rgb1[0], $rgb1[1], $rgb1[2]);
    $l2 = relativeLuminance($rgb2[0], $rgb2[1], $rgb2[2]);
    [$lighter, $darker] = $l1 > $l2 ? [$l1, $l2] : [$l2, $l1];

    return ($lighter + 0.05) / ($darker + 0.05);
}

// Today's ACTUAL primary ramp's own lightness curve, expressed as each
// shade's fractional POSITION between the family's 600 shade and the
// relevant endpoint (white for lighter shades, black for darker ones) —
// e.g. shade 50's position is how far 50's L sits along the [600-L, 1.0]
// interval. A position-based (not multiplicative-ratio) curve is what
// makes this scale correctly to a new anchor whose own lightness differs
// notably from the old anchor's — a multiplicative ratio blows up past
// 1.0 and clips every light shade to the same near-white value once the
// new anchor is lighter than the old one (confirmed live while building
// this script against the real secondary anchor). Computed directly from
// resources/css/tokens.css's current, already-AA-verified primary ramp
// (not invented) — the new ramp inherits a curve shape already proven to
// work end-to-end.
$oldAnchorL = 0.24314; // current primary-600's own L
$oldShadeL = [
    50 => 0.959, 100 => 0.906, 200 => 0.800, 300 => 0.657, 400 => 0.518, 500 => 0.431,
    600 => $oldAnchorL,
    700 => 0.200, 800 => 0.159, 900 => 0.120, 950 => 0.075,
];
$curvePositions = [];
foreach ($oldShadeL as $shade => $l) {
    $curvePositions[$shade] = $shade < 600
        ? ($l - $oldAnchorL) / (1 - $oldAnchorL)   // fraction of the way from anchor to white
        : ($shade === 600 ? 0.0 : ($oldAnchorL - $l) / $oldAnchorL); // fraction toward black
}

$anchorHex = $argv[1] ?? null;
$familyName = $argv[2] ?? 'family';
if ($anchorHex === null) {
    fwrite(STDERR, "Usage: php generate-ramp.php <anchor-hex> <family-name>\n");
    exit(1);
}

[$ar, $ag, $ab] = hexToRgb($anchorHex);
[$h, $s, $anchorL] = rgbToHsl($ar, $ag, $ab);

$white = [255, 255, 255];
$hexes = [];

foreach ($curvePositions as $shade => $pos) {
    $l = $shade < 600
        ? $anchorL + $pos * (1 - $anchorL)
        : ($shade === 600 ? $anchorL : $anchorL - $pos * $anchorL);
    $l = min(0.98, max(0.02, $l));
    [$r, $g, $b] = hslToRgb($h, $s, $l);
    $hex = sprintf('#%02X%02X%02X', $r, $g, $b);
    $hexes[$shade] = $hex;
    $contrastVsWhite = contrastRatio([$r, $g, $b], $white);
    $aaPass = $contrastVsWhite >= 4.5 ? 'AA-white-OK' : 'below-4.5';
    printf("--color-%s-%d: %s;  /* white-text contrast %.2f:1 (%s) */\n", $familyName, $shade, $hex, $contrastVsWhite, $aaPass);
}

// Text-on-tint spot check: the 700/800/900 shades used as text color on
// the family's own 50 tint (design-system.md's established
// muted-text-on-tint pattern, e.g. primary-700 as link text on
// primary-50 background).
fwrite(STDERR, "\ntext-on-{$familyName}-50 spot checks:\n");
[$tr, $tg, $tb] = hexToRgb($hexes[50]);
foreach ([700, 800, 900] as $shade) {
    [$sr, $sg, $sb] = hexToRgb($hexes[$shade]);
    $c = contrastRatio([$sr, $sg, $sb], [$tr, $tg, $tb]);
    fwrite(STDERR, sprintf("  %s-%d on %s-50: %.2f:1 (%s)\n", $familyName, $shade, $familyName, $c, $c >= 4.5 ? 'AA-OK' : 'below-4.5'));
}
```

- [ ] **Step 2: Run it against both real logo-sampled anchors and verify the output**

```bash
php docs/design/brand/generate-ramp.php '#563B26' primary
php docs/design/brand/generate-ramp.php '#336B3E' secondary
```

Expected output (already verified during planning, against the pinned CI PHP 8.5 image — reproduce it, don't just trust this plan's copy):

```
--color-primary-50: #F9F4F0;  /* white-text contrast 1.09:1 (below-4.5) */
--color-primary-100: #F0E6DE;  /* white-text contrast 1.23:1 (below-4.5) */
--color-primary-200: #E0CAB8;  /* white-text contrast 1.58:1 (below-4.5) */
--color-primary-300: #C9A386;  /* white-text contrast 2.32:1 (below-4.5) */
--color-primary-400: #B47E55;  /* white-text contrast 3.47:1 (below-4.5) */
--color-primary-500: #986943;  /* white-text contrast 4.73:1 (AA-white-OK) */
--color-primary-600: #563B26;  /* white-text contrast 10.25:1 (AA-white-OK) */
--color-primary-700: #47311F;  /* white-text contrast 12.16:1 (AA-white-OK) */
--color-primary-800: #382719;  /* white-text contrast 14.26:1 (AA-white-OK) */
--color-primary-900: #2A1D13;  /* white-text contrast 16.36:1 (AA-white-OK) */
--color-primary-950: #1B120C;  /* white-text contrast 18.45:1 (AA-white-OK) */

text-on-primary-50 spot checks:
  primary-700 on primary-50: 11.13:1 (AA-OK)
  primary-800 on primary-50: 13.06:1 (AA-OK)
  primary-900 on primary-50: 14.98:1 (AA-OK)

--color-secondary-50: #F2F9F3;  /* white-text contrast 1.07:1 (below-4.5) */
--color-secondary-100: #E1F1E4;  /* white-text contrast 1.17:1 (below-4.5) */
--color-secondary-200: #C0E1C6;  /* white-text contrast 1.42:1 (below-4.5) */
--color-secondary-300: #93CC9E;  /* white-text contrast 1.85:1 (below-4.5) */
--color-secondary-400: #67B777;  /* white-text contrast 2.44:1 (below-4.5) */
--color-secondary-500: #4FA660;  /* white-text contrast 3.02:1 (below-4.5) */
--color-secondary-600: #336B3E;  /* white-text contrast 6.34:1 (AA-white-OK) */
--color-secondary-700: #2A5833;  /* white-text contrast 8.26:1 (AA-white-OK) */
--color-secondary-800: #214629;  /* white-text contrast 10.65:1 (AA-white-OK) */
--color-secondary-900: #19351F;  /* white-text contrast 13.37:1 (AA-white-OK) */
--color-secondary-950: #102113;  /* white-text contrast 16.81:1 (AA-white-OK) */

text-on-secondary-50 spot checks:
  secondary-700 on secondary-50: 7.72:1 (AA-OK)
  secondary-800 on secondary-50: 9.95:1 (AA-OK)
  secondary-900 on secondary-50: 12.49:1 (AA-OK)
```

**Real finding to carry into Task 2, not silently ship**: `primary-500`'s white-text contrast is `4.73:1` — passes AA but with a thin margin (the current, provisional `primary-600` sits at a comfortable `10.05:1`). Do NOT use `primary-500` as a white-text button fill anywhere. Keep button/fill usage on `primary-600` (comfortable `10.25:1` margin); get the "fresher/younger" feeling from lighter tint backgrounds (`primary-50`/`100`), the new hero imagery (Task 4), and the lighter, less-saturated hue itself (§3.1 of the spec already notes the new anchor is less saturated than the old provisional guess) — not from moving the button fill to a lighter, thinner-margin shade. This supersedes the spec's §3.3 tentative "provisionally 500" language — record this resolution in Task 3's design-system.md update.

- [ ] **Step 3: Commit**

```bash
git add docs/design/brand/generate-ramp.php
git commit -m "feat(design): add color ramp generator, derive logo-anchored primary/secondary values"
```

---

### Task 2: Update tokens.css and regenerate the Filament palette

**Files:**
- Modify: `resources/css/tokens.css` (the `--color-primary-*` and `--color-secondary-*` blocks, currently at the line ranges shown by `grep -n "color-primary-\|color-secondary-" resources/css/tokens.css`)
- Modify (generated, via command not hand-edit): `app/Support/Design/generated/FilamentPalette.php`

**Interfaces:**
- Consumes: Task 1's verified hex output.
- Produces: the new token values every other consumer (Blade components, Filament panels) reads.

- [ ] **Step 1: Replace the primary and secondary color blocks in tokens.css**

Replace the existing `--color-primary-50` through `--color-primary-950` block with:

```css
  --color-primary-50:  #F9F4F0;
  --color-primary-100: #F0E6DE;
  --color-primary-200: #E0CAB8;
  --color-primary-300: #C9A386;
  --color-primary-400: #B47E55;
  --color-primary-500: #986943; /* thin AA margin (4.73:1) if ever used as white-text fill -- prefer 600 for fills, see design-system.md */
  --color-primary-600: #563B26; /* base — white label AA 10.25:1 — sampled directly from the real logo (docs/design/brand/source/logo.png), resolves OQ-12 */
  --color-primary-700: #47311F; /* hover / link on light */
  --color-primary-800: #382719; /* pressed */
  --color-primary-900: #2A1D13; /* footer, inverse header */
  --color-primary-950: #1B120C;
```

Replace the existing `--color-secondary-50` through `--color-secondary-950` block with:

```css
  --color-secondary-50:  #F2F9F3;
  --color-secondary-100: #E1F1E4;
  --color-secondary-200: #C0E1C6;
  --color-secondary-300: #93CC9E;
  --color-secondary-400: #67B777;
  --color-secondary-500: #4FA660;
  --color-secondary-600: #336B3E; /* sampled directly from the real logo (docs/design/brand/source/logo.png), resolves OQ-12 */
  --color-secondary-700: #2A5833;
  --color-secondary-800: #214629;
  --color-secondary-900: #19351F;
  --color-secondary-950: #102113;
```

- [ ] **Step 2: Verify no hardcoded-value gate regression**

```bash
bash ci/verify-docs.sh
```

Expected: `RESULT: ALL DOC GATES PASS` (same as before this change — this step only changes values inside the designated tokens.css block, which the gate already permits).

- [ ] **Step 3: Regenerate and verify the Filament admin palette**

Using the pinned PHP 8.5 image (this host's PHP is too old to run `artisan` directly):

```bash
sudo docker run --rm --user 1000:1000 \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint /bin/sh \
  ghcr.io/andrianm28/makam-app@sha256:299cae72c342cf33dadb6ea976f0a97a58329419f55bfd0aec8cf2436423f97d \
  -c "php artisan design:generate-filament-palette && php artisan design:verify-filament-palette"
```

Expected: the generate command reports success, and `design:verify-filament-palette` reports no drift. If it reports drift, the generate command did not run cleanly — do not hand-edit `app/Support/Design/generated/FilamentPalette.php` to force a match; re-run generate instead.

- [ ] **Step 4: Commit**

```bash
git add resources/css/tokens.css app/Support/Design/generated/FilamentPalette.php
git commit -m "feat(design): re-anchor primary/secondary color ramps to the real logo"
```

---

### Task 3: Update design-system.md — resolve OQ-12, document the new method

**Files:**
- Modify: `docs/design/design-system.md` (§1.2 color table and its surrounding prose, §11 open questions)

**Interfaces:**
- Consumes: Task 1's verified values, Task 2's landed tokens.css.

- [ ] **Step 1: Update §1.2's color table**

Find the table row (per the file's current §1.2 structure):
```
| **Primary — "Earth"** | `#5D3A1F` (PROVISIONAL — OQ-12) | 26° | Brand, primary CTA, links, active nav, focus ring |
| **Secondary — "Leaf"** | `#2E7D32` (PROVISIONAL — OQ-12) | 123° | Surface tint + accent **only** (never a fill, badge, button, or alert) |
```

Replace with:
```
| **Primary — "Earth"** | `#563B26` | 26° | Brand, primary CTA, links, active nav, focus ring |
| **Secondary — "Leaf"** | `#336B3E` | 132° | Surface tint + accent **only** (never a fill, badge, button, or alert) |
```

(Hue values recomputed from the new hex — verify with `docs/design/brand/generate-ramp.php`'s own `rgbToHsl()` output or an equivalent check before finalizing; don't hand-guess.)

- [ ] **Step 2: Update the surrounding prose that references "PROVISIONAL pending OQ-12"**

The paragraph after the table (starting "(a) Primary is Earth brown, not teal...") and the file's §0.3 status note both reference "every Earth/Leaf value is PROVISIONAL pending OQ-12". Replace with a dated resolution note, e.g.:

```
**OQ-12 resolved 21 Aug 2026.** The real official logo (`docs/design/brand/source/logo.png`)
replaced the earlier render-based estimate. Every Earth/Leaf hex above is sampled directly from
that file (`docs/design/brand/sample-logo-colours.php`) and the full 50-950 ramp is derived from
those anchors by `docs/design/brand/generate-ramp.php` (position-based lightness interpolation
along the same curve the original ramp used, holding hue+saturation fixed — see that script's own
doc comment for the method). No value here is an estimate any longer.
```

Update §11's OQ-12 entry to "Resolved 21 Aug 2026" with a one-line pointer to this note, matching how OQ-01/OQ-02 are already recorded as resolved in this same section.

- [ ] **Step 3: Record the primary-500 contrast finding**

Add a note near the button/fill color guidance in §3.1 (or wherever `primary-600` is documented as the fill color) recording that `primary-500` carries only a thin AA margin as a white-text fill and should not be used for that purpose — button fills stay on `primary-600`. This is Task 1's real finding, now documented so a future contributor doesn't reach for `500` on a fill without knowing why not to.

- [ ] **Step 4: Verify and commit**

```bash
bash ci/verify-docs.sh
git add docs/design/design-system.md
git commit -m "docs(design): resolve OQ-12 with real logo-sampled brand colors"
```

---

### Task 4: Build the `<x-mk.hero>` primitive

**Files:**
- Create: `resources/views/components/mk/hero.blade.php`
- Test: `tests/Feature/View/Components/MkHeroTest.php`

**Interfaces:**
- Produces: `<x-mk.hero :image="$path" heading="..." :cta="['label' => '...', 'href' => '...']">` — a reusable hero block pairing a real photo with a heading and one primary CTA, for later phases to apply to real pages (homepage first, per the spec's Phase 2).
- Consumes: `<x-mk.button>` (existing, for the CTA), the new `primary`/`secondary` tokens from Task 2.

- [ ] **Step 1: Read the existing card/button primitives' conventions first**

Read `resources/views/components/mk/card.blade.php` and `resources/views/components/mk/button.blade.php` in full before writing — this repo's established convention (documented in both files' own headers) is `@props([...])` with defaults, classes composed once in a single PHP block, one `$attributes->merge()` call on the root element. Match this exactly; don't invent a different composition style for the new component.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class MkHeroTest extends TestCase
{
    public function test_it_renders_the_heading_image_and_cta(): void
    {
        $html = Blade::render(
            '<x-mk.hero image="/images/cemetery-garden-01.jpg" heading="Tenang, hormat, terpercaya." :cta="[\'label\' => \'Pemesanan Makam\', \'href\' => \'/pemesanan-makam\']" />'
        );

        $this->assertStringContainsString('Tenang, hormat, terpercaya.', $html);
        $this->assertStringContainsString('/images/cemetery-garden-01.jpg', $html);
        $this->assertStringContainsString('Pemesanan Makam', $html);
        $this->assertStringContainsString('/pemesanan-makam', $html);
    }

    public function test_the_image_has_an_empty_alt_by_default_since_it_is_decorative(): void
    {
        // design-system.md §2.2: imagery is atmosphere (real cemeteries/
        // gardens, daylight), never content the heading doesn't already
        // convey -- matches this repo's existing convention for
        // decorative images (empty alt, not a missing one).
        $html = Blade::render(
            '<x-mk.hero image="/images/cemetery-garden-01.jpg" heading="Tenang, hormat, terpercaya." :cta="[\'label\' => \'Pemesanan Makam\', \'href\' => \'/pemesanan-makam\']" />'
        );

        $this->assertStringContainsString('alt=""', $html);
    }

    public function test_it_throws_without_a_heading(): void
    {
        $this->expectException(\Throwable::class);

        Blade::render('<x-mk.hero image="/images/cemetery-garden-01.jpg" />');
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

```bash
sudo docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint /bin/sh ghcr.io/andrianm28/makam-app@sha256:299cae72c342cf33dadb6ea976f0a97a58329419f55bfd0aec8cf2436423f97d \
  -c "php vendor/bin/phpunit tests/Feature/View/Components/MkHeroTest.php"
```

Expected: FAIL — the component doesn't exist yet.

- [ ] **Step 4: Write the component**

```blade
{{--
    resources/views/components/mk/hero.blade.php

    <x-mk.hero> — Phase 1 of the brand visual refresh
    (docs/superpowers/specs/2026-08-21-brand-visual-refresh-design.md
    §4.2). Pairs a real photo (design-system.md §2.2: cemeteries/gardens,
    daylight, no people in grief) with the page's primary heading and one
    CTA. Not yet wired into any real page in this phase -- Phase 2 applies
    it to the homepage.

    Convention matches button.blade.php/card.blade.php: @props([...]) with
    defaults, classes composed once in a single PHP block, one
    $attributes->merge() on the root element.

    Props:
      image   (string, required) — path to a real cemetery/garden photo.
      heading (string, required) — the page's primary heading text.
      cta     (array, required) — ['label' => string, 'href' => string],
              rendered as a single primary <x-mk.button>. design-system.md
              §2.3 DO: exactly one primary action per view.

    The image is deliberately decorative (empty alt) -- it sets
    atmosphere, never conveys information the heading doesn't already
    carry, matching this repo's existing decorative-image convention.
--}}
@props([
    'image' => null,
    'heading' => null,
    'cta' => null,
])

@php
    if ($heading === null) {
        throw new \InvalidArgumentException('<x-mk.hero> requires a heading.');
    }

    $classes = 'relative overflow-hidden rounded-lg';
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    @if ($image)
        <img src="{{ $image }}" alt="" class="w-full h-64 md:h-96 object-cover" />
    @endif

    <div class="p-6 md:p-8 flex flex-col gap-4 bg-primary-50">
        <h1 class="text-2xl md:text-3xl font-semibold text-neutral-900">{{ $heading }}</h1>

        {{ $slot }}

        @if ($cta)
            <div>
                <x-mk.button variant="primary" size="lg" :href="$cta['href']">
                    {{ $cta['label'] }}
                </x-mk.button>
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
sudo docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint /bin/sh ghcr.io/andrianm28/makam-app@sha256:299cae72c342cf33dadb6ea976f0a97a58329419f55bfd0aec8cf2436423f97d \
  -c "php vendor/bin/phpunit tests/Feature/View/Components/MkHeroTest.php"
```

Expected: PASS, 3/3.

- [ ] **Step 6: Verify docs gates (no hardcoded design values)**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/mk/hero.blade.php tests/Feature/View/Components/MkHeroTest.php
git commit -m "feat(design): add <x-mk.hero> primitive for the brand visual refresh"
```

---

### Task 5: Document the nav-card pattern (no new component)

**Files:**
- Modify: `docs/design/design-system.md` (§3, component primitives section)

**Interfaces:**
- Consumes: `<x-mk.card interactive as="a">` (existing, already used by `MarketplaceIndex`'s product grid).

- [ ] **Step 1: Confirm the existing component already supports the pattern**

Read `app/Livewire/Public/Marketplace/MarketplaceIndex.php`'s Blade view (the product grid) to confirm the exact usage shape: `<x-mk.card as="a" :href="...">` wrapping an icon/image + label, whole card focusable as one link. This already exists — this task documents it as the sanctioned pattern for primary navigation, it does not build anything new.

- [ ] **Step 2: Add a documented usage example to design-system.md §3**

Add a subsection (e.g. "3.x Card as navigation") showing the exact Blade shape for a nav-card grid, citing `MarketplaceIndex`'s product grid as the existing precedent, and noting: this is the pattern Phase 2 (homepage) and later phases use for primary navigation/service choices — matching the `kamboja.co.id` benchmark's service-card layout per the design spec, without inventing a new primitive.

- [ ] **Step 3: Verify and commit**

```bash
bash ci/verify-docs.sh
git add docs/design/design-system.md
git commit -m "docs(design): document the card-as-navigation pattern for the brand refresh"
```

---

## What this plan does NOT cover

- Applying `<x-mk.hero>` or the nav-card pattern to any real page — that's Phase 2 (homepage), a separate follow-up plan once this phase is reviewed and merged.
- Sourcing the actual photography for the hero pattern — flagged in the spec (§7) as an implementation-time decision for whoever builds Phase 2, not this phase.
- Any change to `success`/`warning`/`danger`/`info` colors, or to Leaf's structural "never a fill" cage.
- Admin/vendor Filament panel visual changes (out of scope per the spec).

## Verification

| What | How | Pass condition |
|---|---|---|
| Task 1's ramp values | `php docs/design/brand/generate-ramp.php` for both anchors | Output matches this plan's recorded values; every `600`-and-darker shade clears 4.5:1 white-text contrast |
| Task 2's tokens.css change | `ci/verify-docs.sh` | All gates pass |
| Task 2's Filament regeneration | `php artisan design:verify-filament-palette` | No drift reported |
| Task 3's docs update | `ci/verify-docs.sh` (Gate 7: traceability rows still resolve) | All gates pass |
| Task 4's new component | `php vendor/bin/phpunit tests/Feature/View/Components/MkHeroTest.php` | 3/3 passing |
| No regressions | Full `php artisan test` suite | Stays green (this phase touches no application logic, only tokens/docs/one new presentational component) |

## Execution notes

Superpowers SDD, worktree-isolated, task-scoped review then whole-branch review, one PR for this whole phase (matching how the E2E-MKT suite landed as one PR despite spanning 4 tasks). Security/authorization review is not required (no security-sensitive code touched) — but per this session's own established discipline, watch the first real CI run rather than assuming local verification is sufficient, and get the user's own visual sign-off on the actual rendered colors (screenshot or live host check) before considering this phase truly done, since a contrast-passing color can still look wrong to a human eye in ways no automated check catches.
