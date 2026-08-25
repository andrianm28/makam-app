# Brand Identity Adoption Implementation Plan

## HANDOFF STATUS — 17 Aug 2026

**Stage: PLANNING COMPLETE — execution NOT started.** Paused by user choice ("handoff" at the execution-mode question), not by an error. Nothing is in flight: no worktree exists, no subagents were ever dispatched, no code or token file has been modified for this work.

**Durable artifacts (committed to `docs/design-system-and-planning`):**
- Spec: `docs/superpowers/specs/2026-08-17-brand-identity-design.md` — commit `00cd3a6` (written user review passed: "reviewed, proceed").
- This plan — commit `8696671` (+ the handoff-section amendment commit on top).

**Blocking input owed by the stakeholder:** the logo source PNG at `docs/design/brand/source/logo.png` (transparent background preferred; white-background render acceptable — the pipeline white-keys it and stays PROVISIONAL under OQ-12). Task 1 can start without it (documented candidate-value fallback); **Task 3 hard-blocks** until the file exists.

**Pending decision on resume:** execution mode — subagent-driven (recommended) vs inline. The user has NOT chosen; "handoff" answered instead.

**Locked decisions — do NOT re-litigate on resume** (full rationale in the spec and ADR-0034's Task-1 brief):
1. Full palette rebase: Earth brown primary, Petrol retired (resolves OQ-01, reverses design-system §1.2a); resolves OQ-02 (official identity adopted).
2. Brand values derived from the render, ALL flagged PROVISIONAL (new OQ-12 tracks official hexes + vector source).
3. Poppins 600 = `--font-display` (h1/h2, hero, wordmark) via `@fontsource/poppins`; Inter stays body/UI/h3/h4; new `--font-document` keeps Source Serif 4 for documents; Filament stays all-Inter.
4. Raster assets, not SVG — ext-gd pipeline (`BrandAssetBuilder` + artisan wrapper + plain-CLI driver; neither sharp nor Pillow exists on the tooling path; GD incl. WebP verified present).
5. Leaf green = `secondary-*` under the existing restricted-usage cage, re-justified against success proximity; `--mk-surface-warm` re-points to `primary-50`.
6. Danger hue tuned −11° (≈352°) to restore the verifier's ≥30° primary/danger separation — fix the token, never the assertion (§9.4).
7. Planning-time deviations from spec (recorded in Global Constraints): no print.css/document views exist → token + doc annotation only; fontsource instead of hand-written `@font-face`; `icon-192/512` dropped (no PWA manifest); favicon.ico via embedded PNG-in-ICO writer.

**Resume instructions (exact):**
1. Read this plan's Global Constraints, then the spec — both are already committed; never regenerate them.
2. Confirm the PNG prerequisite (above) with the stakeholder; if still absent, only Tasks 1–2 may run.
3. Get the execution-mode answer; default recommendation: subagent-driven.
4. Create the worktree: `.worktrees/brand-identity` branched from current `origin/docs/design-system-and-planning` tip (re-check base freshness — sibling lanes merge often; the handoff-skill's stale-base check applies).
5. Ledger execution at `.superpowers/sdd/brand-identity/progress.md` inside the worktree (git-ignored).
6. Start at **Task 1 Step 1**. Tasks are ordered T1→T7; T3 requires the PNG; T4 requires T3's assets; T5 requires T1's tokens.

---

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adopt the official Makam.co.id brand identity (figure-8/8-leaf mark, Earth brown + Leaf green, Poppins) across tokens, public web, Filament panels, and favicons — with all 46 contrast pairs re-verified and every product contract untouched.

**Architecture:** Values-only rebase of `resources/css/tokens.css` Layer 1 (token names and every Layer-2 `--mk-*` alias unchanged), a pure-PHP/GD brand-asset builder (mirroring `FilamentPaletteGenerator`'s framework-free precedent) emitting committed raster assets, a rewritten `<x-mk.logo>` consuming them, and derived surfaces (Filament palette, panel branding) regenerated — never hand-edited.

**Tech Stack:** Laravel 13 / PHP 8.5 (CI), Tailwind CSS 4.1 `@theme`, Livewire 4, Filament 5, ext-gd (asset pipeline), python3 stdlib (contrast verifier).

**Spec:** `docs/superpowers/specs/2026-08-17-brand-identity-design.md` (read it first — the plan argues from it).

## Global Constraints

- **Runtime pins** (technology-baseline): PHP 8.5, Laravel 13, Livewire 4, Filament 5, PostgreSQL 18, Redis 8.2, Node 24 LTS (`.nvmrc`). Commit `package-lock.json` when `package.json` changes; no unconstrained dependency updates.
- **Token governance** (design-system.md §9): every design value lives in `resources/css/tokens.css` only; no hardcoded hex/px/ms/shadow elsewhere; no Tailwind arbitrary values except `var()` references; no raw `z-index`; no new token without the ADR this plan ships; re-run `python3 docs/design/verify-contrast.py` after any colour change and **fix the token, never the assertion**.
- **Product contracts frozen**: no label, route, nav-order, homepage-order, booking-step, or status-enum change; `StatusIntent` and the §3.7 mapping untouched; no dark mode; status never by colour alone.
- **Honesty rules** (AGENTS.md): never report `PASS` for a check not executed; use `BLOCKED` / `NOT TESTED` explicitly. Every brand value derived from the render is flagged `PROVISIONAL` (OQ-12) until official values arrive.
- **This host's limits** (verified 17 Aug 2026): PHP CLI is **8.3.6** while `composer.lock` requires **≥ 8.5** → `php artisan` and PHPUnit **cannot run locally**; they are CI-run only, and every PHP test step below says so. Node is **22.23.2** vs pinned 24 → a local `npm run build` is smoke-level evidence only; CI (`.nvmrc`) is authoritative. `python3` stdlib ✓, plain `php` CLI with **ext-gd incl. WebP** ✓ (for framework-free scripts, the `FilamentPaletteGenerator` precedent).
- **SDD flow** (AGENTS.md): execute in an isolated worktree under `.worktrees/brand-identity` branched from `docs/design-system-and-planning` AFTER this plan is committed; ledger execution state at `.superpowers/sdd/brand-identity/progress.md` inside the worktree; one PR back to `docs/design-system-and-planning`.
- **Prerequisite input**: the stakeholder drops the logo source at `docs/design/brand/source/logo.png` (transparent background preferred). Task 1 has a documented fallback (candidate values from the chat render, PROVISIONAL); **Task 3 hard-blocks without the file** — stop and ask.
- **Spec deviations already resolved at planning time** (recorded in ADR-0034): (a) no `print.css` or printable document views exist yet — `--font-document` is added and design-system §8.5 annotated, but no document-header logo ships this phase; (b) asset tool is **ext-gd** (neither sharp nor Pillow exists on the tooling path); (c) Poppins ships via the `@fontsource/poppins` npm package, matching how Inter already ships (no hand-written `@font-face`, no manual preload); (d) `icon-192/512.png` dropped — no PWA manifest exists to consume them (YAGNI; OQ-12 follow-up).

---

### Task 1: ADR-0034 + palette revalue + verifier sync

**Files:**
- Create: `docs/adr/0034-adopt-makam-brand-identity.md`
- Create: `docs/design/brand/sample-logo-colours.php` (framework-free GD sampler)
- Modify: `resources/css/tokens.css` (§1.1 primary ramp, §1.2 secondary ramp, §1.4 danger ramp, §2.1 `--mk-surface-warm`, file header v0.2)
- Modify: `docs/design/verify-contrast.py` (surface-warm pairs, stale hue-exception comment)
- Test: `docs/design/verify-contrast.py` IS the test (exit 0)

**Interfaces:**
- Consumes: `docs/design/brand/source/logo.png` (stakeholder-supplied; fallback below).
- Produces: new `--color-primary-*`, `--color-secondary-*`, `--color-danger-*` values consumed by Tasks 2/4/5/6; ADR-0034 number reserved.

- [ ] **Step 1: Sample the brand colours (or invoke the documented fallback)**

Create `docs/design/brand/sample-logo-colours.php` — pure PHP + ext-gd, no framework (runs on this host's PHP 8.3 CLI):

```php
<?php
declare(strict_types=1);
// Usage: php docs/design/brand/sample-logo-colours.php docs/design/brand/source/logo.png
// Prints the average brown (hue 15–40°) and green (hue 90–160°) pixel colours.
$src = $argv[1] ?? null;
if ($src === null || !is_file($src)) { fwrite(STDERR, "source PNG missing: {$src}\n"); exit(1); }
$im = imagecreatefrompng($src) ?: exit(1);
imagepalettetotruecolor($im);   // imagecolorat on a palette PNG returns an index, not RGBA
imagealphablending($im, false); imagesavealpha($im, true);
$w = imagesx($im); $h = imagesy($im);
$buckets = ['brown' => [0,0,0,0], 'green' => [0,0,0,0]];
for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
    $rgba = imagecolorat($im, $x, $y);
    if ((($rgba >> 24) & 0x7F) === 127) continue;             // fully transparent
    $r = ($rgba >> 16) & 0xFF; $g = ($rgba >> 8) & 0xFF; $b = $rgba & 0xFF;
    $mx = max($r,$g,$b); $mn = min($r,$g,$b);
    if ($mx > 245 && $mn > 235) continue;                    // background
    if ($mx - $mn < 20) continue;                            // grey/flat
    $d = $mx - $mn; $l = ($mx + $mn) / 510;
    if ($mx === 0) continue;
    if ($d === 0) continue;
    if ($mx === $r)     $hue = 60 * fmod((($g - $b) / $d) + 6, 6);
    elseif ($mx === $g) $hue = 60 * (($b - $r) / $d + 2);
    else                $hue = 60 * (($r - $g) / $d + 4);
    $key = ($hue >= 15 && $hue <= 40) ? 'brown' : (($hue >= 90 && $hue <= 160) ? 'green' : null);
    if ($key) { $buckets[$key][0] += $r; $buckets[$key][1] += $g; $buckets[$key][2] += $b; $buckets[$key][3]++; }
}
foreach ($buckets as $name => [$r, $g, $b, $n]) {
    if ($n === 0) { fwrite(STDERR, "no {$name} pixels found\n"); exit(1); }
    printf("%s #%02X%02X%02X  (%d px)\n", $name, intdiv($r,$n), intdiv($g,$n), intdiv($b,$n), $n);
}
```

Run: `php docs/design/brand/sample-logo-colours.php docs/design/brand/source/logo.png`
Expected: two lines, e.g. `brown #5D3A1F …` / `green #2E7D32 …`.

**Fallback if the source PNG is absent:** use the candidates `brown #5D3A1F` / `green #2E7D32` (derived from the stakeholder-supplied render reviewed in chat 17 Aug 2026), mark every derived token PROVISIONAL, and record in the ADR that sampling is deferred to OQ-12. Either way the sampled/observed values go into the task report.

- [ ] **Step 2: Build a candidate tokens file and iterate against the verifier BEFORE touching the real one**

```bash
cp resources/css/tokens.css /tmp/tokens-candidate.css
```

Edit `/tmp/tokens-candidate.css` with the candidate ramps below (600 shades = Step 1's sampled values; the other shades are the constant-hue starting curve — adjust only as the verifier requires, recording every change):

```
primary "Earth":  50 #FAF5EF · 100 #F2E8DC · 200 #E2CDB6 · 300 #CDA882 · 400 #B08458
                  500 #9A6F42 · 600 <sampled brown> · 700 #4D3019 · 800 #3E2713
                  900 #2F1D0E · 950 #1E1208
secondary "Leaf": 50 #F0F7F0 · 100 #DCEDDD · 200 #BADBBB · 300 #8FC692 · 400 #5FA964
                  500 #3F8A46 · 600 <sampled green> · 700 #27682B · 800 #205423
                  900 #19431C · 950 #0D2810
```

Danger ramp: rotate every shade's hue by **−11°** (3° → ~352°), keeping HLS lightness/saturation, via:

```bash
python3 - <<'PY'
import colorsys
shades = {50:"#FDF2F1",100:"#FBDFDC",200:"#F5BEB9",300:"#EC948C",400:"#DE6459",
          500:"#C63F33",600:"#A32A24",700:"#87211D",800:"#6E1B18",900:"#5B1815",950:"#310A09"}
for k,v in shades.items():
    r,g,b = (int(v[i:i+2],16)/255 for i in (1,3,5))
    h,l,s = colorsys.rgb_to_hls(r,g,b)
    r2,g2,b2 = colorsys.hls_to_rgb((h-11/360)%1.0,l,s)
    print(k, '#%02X%02X%02X' % (round(r2*255),round(g2*255),round(b2*255)))
PY
```

Expected: 600 ≈ `#A32435`. If any danger pair then fails, raise that shade's lightness minimally until it passes and record the tune (fix the token, never the assertion — §9.4).

Run: `python3 docs/design/verify-contrast.py --tokens /tmp/tokens-candidate.css`
Expected: `RESULT: PASS` (46 pairs) **and** hue-separation lines showing primary/danger ≥ 30° (expected ≈ 34°). Iterate only the token values until both hold.

- [ ] **Step 3: Apply to the real `resources/css/tokens.css`**

Apply the verified candidate values to `resources/css/tokens.css`:
1. §1.1 PRIMARY block — new Earth values; rewrite the comment: name **"Earth"**, hue from the sampled value, rationale citing ADR-0034 and the *Filosofi Logo* (earth/calm/stability/warmth), explicitly stating the §1.2a teal decision is **reversed** (OQ-01 resolved) and green is no longer the brand CTA colour — that ambiguity argument is now satisfied structurally (brand fills are brown; green is artwork + caged secondary). Append `/* PROVISIONAL — derived from logo render, awaiting official brand values (OQ-12) */` to the 600 line (and 700/800/900 if the curve was tuned).
2. §1.2 SECONDARY block — replace Sandstone values with Leaf values; rewrite the comment: name **"Leaf"**, cage unchanged (50–200 surface tints, 300–400 decorative, 700–900 text-on-tints, never fill/badge/button/alert) re-justified against **success** (146°) proximity instead of warning proximity; cite the philosophy's green = life/sustainability.
3. §1.4 DANGER block — new rotated values; comment notes the hue tune restoring ≥30° primary/danger separation, per ADR-0034.
4. §2.1 — change `--mk-surface-warm: var(--color-secondary-50);` to `--mk-surface-warm: var(--color-primary-50);` with a comment (warm-cream role now lives in the Earth family).
5. File header — Version `v0.1` → `v0.2 (brand identity adopted — ADR-0034)`.

- [ ] **Step 4: Verify the real file**

Run: `python3 docs/design/verify-contrast.py`
Expected: `RESULT: PASS — all 46 pairs meet WCAG 2.1 AA` + separation lines. Paste the full output into the task report (it becomes design-system.md §7.1/§12 evidence in Task 6).

- [ ] **Step 5: Sync the verifier's stale references**

In `docs/design/verify-contrast.py`:
1. Pair `"text-default on surface-warm"` — change bg ref `"color-secondary-50"` → `"color-primary-50"` (follows the `--mk-surface-warm` re-point).
2. Pair `"border-interactive on surface-warm"` — same change.
3. Replace the stale exception comment + entry (`HUE_EXCEPTIONS = {("secondary", "warning")}` — dead code, since the loop only pairs `HUE_FAMILIES`) with `HUE_EXCEPTIONS: set[tuple[str, str]] = set()` and a comment: the Sandstone/warning exception retired with Sandstone; Leaf (≈128°) is caged (never fill/status), so it needs no hue exception; the ≥30° rule stands unchanged for primary/success/info/danger.
4. Keep pair `"text-strong on secondary-100"` and both secondary tint pairs — they now guard the Leaf cage's allowed text-on-tint usage.

Run: `python3 docs/design/verify-contrast.py`
Expected: `RESULT: PASS`.

- [ ] **Step 6: Write ADR-0034**

Create `docs/adr/0034-adopt-makam-brand-identity.md` following the existing ADR shape (context / decision / consequences / alternatives-rejected). Must state: the OQ-02 resolution (stakeholder-supplied identity + philosophy, 17 Aug 2026); the OQ-01 reversal with the philosophy quoted as authority; Earth ramp + Leaf cage; the danger-hue tune and its measured separation; the warning-proximity reviewed-with-mitigation note; Poppins-as-display and `--font-document`; the raster-asset decision incl. the inverse-variant fallback; every PROVISIONAL flag; the four planning-time deviations from the spec (Global Constraints list); and the pointer to `verify-contrast.py` output as evidence. Use only relative links that resolve (ci/verify-docs.sh GATE 4).

- [ ] **Step 7: Run the doc gates**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 8: Commit**

```bash
git add docs/adr/0034-adopt-makam-brand-identity.md docs/design/brand/sample-logo-colours.php docs/design/verify-contrast.py resources/css/tokens.css
git commit -m "feat(brand): Earth/Leaf palette rebase + ADR-0034 (OQ-01/OQ-02 resolved)"
```

---

### Task 2: Poppins display font + `--font-document`

**Files:**
- Modify: `package.json`, `package-lock.json` (add `@fontsource/poppins`)
- Modify: `resources/css/tokens.css` (`--font-display` value, new `--font-document`)
- Modify: `resources/css/app.css` (font import, h1/h2 base rule)
- Modify: `resources/views/design-system-smoke-test.blade.php` (add `font-display`)
- Modify: `.github/workflows/ci.yml` (add `font-display` to the §8.2 utility grep list)
- Test: build-based (below); no PHPUnit deliverable — local host limits apply (Global Constraints)

**Interfaces:**
- Consumes: Task 1's tokens.css (same file, later edit — no conflict).
- Produces: `--font-display` = Poppins stack consumed by Task 4's wordmark (`font-display` utility) and the base h1/h2 rule; `--font-document` reserved for the future print.css.

- [ ] **Step 1: Install the font package**

Run: `npm install "@fontsource/poppins@^5" --no-audit --no-fund`
Then pin the subset file path:

```bash
ls node_modules/@fontsource/poppins/latin-600.css 2>/dev/null && echo USE latin-600.css || echo USE 600.css
```

Record which file exists in the task report; the import below uses `latin-600.css` if present, else `600.css` (decision rule, not a choice). Commit `package.json` + `package-lock.json`.

- [ ] **Step 2: Token edits in `resources/css/tokens.css` §1.6**

```css
  --font-display: "Poppins", "Inter var", "Inter", ui-sans-serif, system-ui,
                  sans-serif;
  /* Documents (certificate/agreement/invoice print, design-system.md §8.5)
   * keep serif gravitas and NEVER inherit the brand display face. Consumed
   * by print.css when that file is built — declared now so no document can
   * silently pick up Poppins (ADR-0034). */
  --font-document: "Source Serif 4", "Lora", ui-serif, Georgia, Cambria, serif;
```

Replace the existing `--font-display` line and its comment (the old "Source Serif 4 display serif" comment moves, adapted, onto `--font-document`).

- [ ] **Step 3: Wire `resources/css/app.css`**

After the Inter import at the bottom:

```css
/* Poppins — brand display face (ADR-0034): h1/h2, hero, logo wordmark.
 * Self-hosted via npm package, same pattern as Inter above; latin subset,
 * weight 600 only (headings are semibold; §4.6 weight budget). */
@import "@fontsource/poppins/latin-600.css"; /* or 600.css per Step 1 */
```

Inside `@layer base`, extend the existing `h1, h2` rule so it also carries `font-family: var(--font-display);` (h3/h4 deliberately stay Inter — Poppins' wide geometry hurts at small sizes).

- [ ] **Step 4: Prove the utility generates**

Add `font-display` to the class list in `resources/views/design-system-smoke-test.blade.php`'s single div, and add `font-display` to the `for cls in …` list in `.github/workflows/ci.yml`'s "Assert every §8.2 utility actually compiled" step.

- [ ] **Step 5: Build + budget measurement (smoke-level on this host — node 22 vs pinned 24; CI is authoritative)**

Run: `npm ci --no-audit --no-fund && npm run build`
Expected: build succeeds; `public/build/assets/` contains Poppins woff2 file(s).
Then measure and record in the task report against §4.6 (≤ 60 KB initial font payload):

```bash
ls -la public/build/assets/ | grep -i woff2
grep -o "Poppins" public/build/assets/app-*.css | head -1
grep -o "font-display" public/build/assets/app-*.css | head -1
```

If the combined initial payload exceeds 60 KB, the documented lever is tightening the Inter var subset — report the overflow and the chosen lever in the task report; never silently ship heavier pages.

- [ ] **Step 6: Gates + commit**

Run: `bash ci/verify-docs.sh` → `ALL DOC GATES PASS`.

```bash
git add package.json package-lock.json resources/css/tokens.css resources/css/app.css resources/views/design-system-smoke-test.blade.php .github/workflows/ci.yml
git commit -m "feat(brand): Poppins display face + --font-document (ADR-0034)"
```

---

### Task 3: Brand asset builder + raster outputs + favicon set

**HARD DEPENDENCY:** `docs/design/brand/source/logo.png` must exist (stakeholder-supplied). If absent: **stop and ask the user** — no fallback exists for artwork.

**Files:**
- Create: `app/Support/Design/BrandAssetBuilder.php` (pure PHP + ext-gd, zero Illuminate dependency — the `FilamentPaletteGenerator` precedent)
- Create: `app/Console/Commands/BuildBrandAssets.php` (artisan wrapper, `design:build-brand-assets` — CI/8.5 environments)
- Create: `docs/design/brand/build.php` (plain-CLI driver for this host, mirroring the generator's "exercised directly with the plain php CLI" precedent)
- Create (generated, committed): `public/brand/mark-96.png`, `public/brand/mark-96.webp`, `public/brand/mark-inverse-96.png`, `public/brand/mark-inverse-96.webp`, `public/brand/lockup-320.png`, `public/brand/lockup-320.webp`, `public/brand/lockup-640.png`, `public/brand/lockup-640.webp`, `public/favicon.ico` (replaces the empty placeholder), `public/apple-touch-icon.png`
- Test: `tests/Unit/Design/BrandAssetBuilderTest.php` (CI-run; locally NOT RUN — host PHP 8.3 < 8.5; say so)

**Interfaces:**
- Consumes: `docs/design/brand/source/logo.png`; Task 1's sampled hues (the recolor mask's brown range).
- Produces: the exact asset paths above, consumed by Task 4 (`<x-mk.logo>`, layout `<head>`) and Task 5 (`brandLogo`).

- [ ] **Step 1: Write the failing test (CI-run; local label: NOT RUN)**

`tests/Unit/Design/BrandAssetBuilderTest.php` — generates its own synthetic fixture with GD (no binary fixture committed): a 200×300 PNG, opaque brown ellipse top, green circle cluster mid, an ≥8 px fully-white horizontal band across the lower third, dark text-like block below. Then:

```php
/** @requires extension gd */
public function test_builds_the_full_manifest_deterministically(): void
{
    $src = $this->makeSyntheticLogo();            // writes tmp PNG via GD
    $out = sys_get_temp_dir().'/brand-'.uniqid();
    $brand = $out.'/brand'; $root = $out.'/public';
    $manifest1 = BrandAssetBuilder::build($src, $brand, $root, whiteKey: true);
    $bytes1 = file_get_contents($brand.'/mark-96.png');
    $manifest2 = BrandAssetBuilder::build($src, $brand, $root, whiteKey: true);
    $this->assertSame($manifest1, $manifest2);
    $this->assertSame($bytes1, file_get_contents($brand.'/mark-96.png')); // deterministic
    foreach (['mark-96.png','mark-inverse-96.png','lockup-320.png','lockup-640.png'] as $f) {
        $this->assertFileExists($brand.'/'.$f);
    }
    [$w,$h] = getimagesize($brand.'/mark-96.png'); $this->assertSame(96, $h);
    $ico = file_get_contents($root.'/favicon.ico');
    $this->assertSame("\x00\x00\x01\x00", substr($ico,0,4));            // ICO magic
    $this->assertSame(3, unpack('v', substr($ico,4,2))[1]);             // 3 sizes
    $this->assertFileExists($root.'/apple-touch-icon.png');
    [$aw] = getimagesize($root.'/apple-touch-icon.png'); $this->assertSame(180, $aw);
}
```

Plus: white-keyed corner pixel of `mark-96.png` carries alpha (transparent), and `build()` on a missing source throws `RuntimeException`. Mark every test `@requires extension gd` (CI's php job gains `gd` in Step 5; hosts without it skip, not fail).

- [ ] **Step 2: Run the test — expected FAIL locally impossible; label honestly**

Local run is BLOCKED (host PHP 8.3.6 vs required 8.5 — Global Constraints). `php -l tests/Unit/Design/BrandAssetBuilderTest.php` for syntax only. Expected in CI: FAIL with "class BrandAssetBuilder not found". Record: `NOT RUN locally (platform), CI evidence pending`.

- [ ] **Step 3: Implement `BrandAssetBuilder`**

`final class BrandAssetBuilder` with `public static function build(string $sourcePng, string $brandOutDir, string $publicRootDir, bool $whiteKey): array` — the eight brand files go to `$brandOutDir` (`public/brand`), the two web-convention root files (`favicon.ico`, `apple-touch-icon.png`) to `$publicRootDir` (`public/`); two explicit targets, no implicit parent-dir writes. Returns the manifest = sorted list of emitted paths relative to `$publicRootDir`. Every decoded image is normalized first: `imagepalettetotruecolor($im)` (palette PNGs return indices from `imagecolorat`, not RGBA) + `imagealphablending($im, false); imagesavealpha($im, true);`. Non-obvious parts, specified:

```php
// 1) White-key (only when $whiteKey): per pixel, r,g,b all > 248 → fully
//    transparent (GD alpha 127). Two-threshold softness is NOT done — the
//    PROVISIONAL flag (OQ-12) covers edge fringe until official art lands.

// 2) Mark/wordmark split: per-row opaque-pixel counts over the keyed alpha;
//    find the widest zero-opaque row band inside the bottom 40% of height;
//    split there (mark above, wordmark row-band below). Fail closed:
if ($bandHeight < 8) {
    throw new RuntimeException('no separator band found — supply a mark-only source');
}

// 3) Inverse mark: per opaque pixel, convert RGB→hue; hue ∈ [10°,50°] and
//    saturation > 0.15 → replace with #FFFFFF, preserve alpha. Green leaves
//    (hue ≈ 128°) untouched by construction.

// 4) Exports: imagescale() with IMG_BICUBIC fixed; WebP via imagewebp()
//    (gd_info WebP Support verified true on this host — assert
//    function_exists('imagewebp') and skip WebP silently NEVER: throw if
//    false, the manifest contract requires both formats).

// 5) favicon.ico — PNG-in-ICO (valid since Vista, accepted by every modern
//    browser): ICONDIR + three ICONDIRENTRYs wrapping the 16/32/48 PNG blobs:
$dir  = pack('vvv', 0, 1, 3);                      // reserved, type=icon, count
$off  = 6 + 16 * 3;
foreach ([16, 32, 48] as $size) {
    $blob = $pngBlobs[$size];
    $dir .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($blob), $off);
    $off += strlen($blob);
}
file_put_contents($outDir.'/favicon.ico', $dir . implode('', $pngBlobs));

// 6) apple-touch-icon.png: 180×180, flattened onto #FFFFFF (opaque — Apple
//    ignores alpha), mark centred at 80% size.
```

Manifest (exact, sorted): `apple-touch-icon.png`, `favicon.ico`, `lockup-320.png`, `lockup-320.webp`, `lockup-640.png`, `lockup-640.webp`, `mark-96.png`, `mark-96.webp`, `mark-inverse-96.png`, `mark-inverse-96.webp`. Lockups are cropped tight to content bounding box + 4% padding, then scaled to width 320/640. `mark-96` = mark region scaled to height 96.

- [ ] **Step 4: Artisan wrapper + local driver**

`app/Console/Commands/BuildBrandAssets.php`: signature `design:build-brand-assets {--source=docs/design/brand/source/logo.png} {--no-key}` (white-key on by default; `--no-key` for a transparent source), calls `BrandAssetBuilder::build($source, public_path('brand'), public_path(), $whiteKey)` — output targets are fixed by web convention, not options. Prints the manifest. `php -l` it locally (artisan itself is CI-only).

`docs/design/brand/build.php` (plain CLI, this host):

```php
<?php
declare(strict_types=1);
require __DIR__.'/../../../app/Support/Design/BrandAssetBuilder.php';
$noKey = in_array('--no-key', $argv, true);
$m = App\Support\Design\BrandAssetBuilder::build(
    __DIR__.'/source/logo.png',
    __DIR__.'/../../../public/brand',
    __DIR__.'/../../../public',
    whiteKey: !$noKey);
echo implode("\n", $m), "\n";
```

- [ ] **Step 5: CI gets ext-gd for the php job**

Edit `.github/workflows/ci.yml` php job: `extensions: pdo_pgsql, pgsql, redis, zip, intl, bcmath` → append `, gd`. Comment: brand-asset builder tests require it.

- [ ] **Step 6: Build the real assets locally and eyeball them**

Run: `php docs/design/brand/build.php` (add `--no-key` if the supplied source is already transparent).
Expected: the ten-file manifest prints. Then **visually inspect** `public/brand/mark-96.png`, `mark-inverse-96.png`, `public/apple-touch-icon.png` (Read tool reads images) — mark fully visible, leaves green, inverse mark white-on-transparent, no white halo around the keyed edges beyond acceptable fringe. If the inverse recolor or keying looks wrong: iterate the thresholds in `BrandAssetBuilder`, NOT the outputs by hand. If it cannot be made clean, ship the documented fallback (light chip behind the full-colour mark) and record the choice for the ADR — do not ship ugly artwork silently.

- [ ] **Step 7: Gates + commit**

`php -l` the three PHP files; `bash ci/verify-docs.sh` → PASS (GATE 2's hex grep must stay clean — the builder contains exactly one literal, `#FFFFFF`, which is a hardcoded colour outside tokens.css: **precedent check** — generated-artwork recolour targets are not design decisions; add the file path to GATE 2's exclusion list in `ci/verify-docs.sh` with a comment, the same way `app/Support/Design/generated/` is already excluded. Do NOT weaken the pattern itself.)

```bash
git add app/Support/Design/BrandAssetBuilder.php app/Console/Commands/BuildBrandAssets.php \
        docs/design/brand/build.php public/brand/ public/favicon.ico public/apple-touch-icon.png \
        tests/Unit/Design/BrandAssetBuilderTest.php .github/workflows/ci.yml ci/verify-docs.sh
git commit -m "feat(brand): raster logo asset pipeline + favicon set (ext-gd, deterministic)"
```

---

### Task 4: `<x-mk.logo>` rewrite + header/footer/favicon wiring

**Files:**
- Modify: `resources/views/components/mk/logo.blade.php` (full rewrite)
- Modify: `resources/views/components/mk/header.blade.php` (two logo call sites)
- Modify: `resources/views/layouts/app.blade.php` (`<head>` favicon links, footer brand row, docblock)
- Test: `tests/Feature/View/Components/MkLogoTest.php` (create), `tests/Feature/View/BrandIdentityTest.php` (create) — both CI-run; locally `php -l` only (Global Constraints)

**Interfaces:**
- Consumes: Task 3's committed assets (`brand/mark-96.{png,webp}`, `brand/mark-inverse-96.{png,webp}`, `favicon.ico`, `apple-touch-icon.png`); Task 2's `font-display` utility.
- Produces: `<x-mk.logo>` contract (`size`, `variant`, `wordmark`) — final, nothing downstream consumes more.

- [ ] **Step 1: Write the failing tests (CI-run)**

`tests/Feature/View/Components/MkLogoTest.php` — follow `MkFieldWireAttributeTest`'s `Blade::render` pattern:

```php
public function test_default_renders_mark_wordmark_and_alt_contract(): void
{
    $html = Blade::render('<x-mk.logo />');
    $this->assertStringContainsString('brand/mark-96.png', $html);
    $this->assertStringContainsString('type="image/webp"', $html);
    $this->assertStringContainsString('makam.co.id', $html);        // lowercase wordmark
    $this->assertStringContainsString('alt=""', $html);             // mark decorative beside wordmark
    $this->assertStringContainsString('font-display', $html);
    $this->assertStringContainsString('text-primary-800', $html);
}

public function test_inverse_variant_swaps_mark_and_wordmark_colour(): void
{
    $html = Blade::render('<x-mk.logo variant="inverse" />');
    $this->assertStringContainsString('brand/mark-inverse-96.png', $html);
    $this->assertStringContainsString('text-neutral-0', $html);
}

public function test_wordmark_false_makes_the_mark_the_accessible_name(): void
{
    $html = Blade::render('<x-mk.logo :wordmark="false" />');
    $this->assertStringContainsString('alt="makam.co.id"', $html);
}

public function test_unknown_variant_throws(): void
{
    $this->expectException(InvalidArgumentException::class);
    Blade::render('<x-mk.logo variant="neon" />');
}
```

`tests/Feature/View/BrandIdentityTest.php` — against `GET /` (homepage exists):

```php
public function test_public_shell_carries_the_real_brand(): void
{
    $html = $this->get('/')->assertOk()->getContent();
    $this->assertStringContainsString('brand/mark-96.png', $html);          // header
    $this->assertStringContainsString('brand/mark-inverse-96.png', $html);  // footer
    $this->assertStringContainsString('rel="icon"', $html);
    $this->assertStringContainsString('favicon.ico', $html);
    $this->assertStringContainsString('apple-touch-icon.png', $html);
    $this->assertStringNotContainsString('M9 22V10.5', $html);              // old placeholder SVG gone
}
```

- [ ] **Step 2: Run — FAIL expected in CI (`brand/mark-96.png` not yet referenced); locally `php -l` + record NOT RUN**

- [ ] **Step 3: Rewrite `resources/views/components/mk/logo.blade.php`**

```blade
{{--
    <x-mk.logo> — official Makam.co.id brand mark (ADR-0034, OQ-02 resolved).
    Raster assets from public/brand/ (Task-3 pipeline; PROVISIONAL until OQ-12
    official artwork). The wordmark is LIVE TEXT (Poppins 600 via font-display,
    lowercase per the brand render) — never baked pixels: crisp, accessible,
    token-coloured. Props: size (px), variant (normal|inverse — closed list,
    throws like <x-mk.badge>'s intent), wordmark (bool).
--}}
@props([
    'size' => 32,
    'variant' => 'normal',
    'wordmark' => true,
])

@php
    if (! in_array($variant, ['normal', 'inverse'], true)) {
        throw new InvalidArgumentException("x-mk.logo: unknown variant [{$variant}]");
    }
    $mark = $variant === 'inverse' ? 'brand/mark-inverse-96' : 'brand/mark-96';
@endphp

<span class="inline-flex items-center gap-2">
    <picture>
        <source srcset="{{ asset($mark.'.webp') }}" type="image/webp">
        <img src="{{ asset($mark.'.png') }}" width="{{ $size }}" height="{{ $size }}"
             alt="{{ $wordmark ? '' : 'makam.co.id' }}"
             @if ($wordmark) aria-hidden="true" @endif
             {{ $attributes->merge(['class' => 'shrink-0']) }}>
    </picture>
    @if ($wordmark)
        <span class="font-display font-semibold {{ $variant === 'inverse' ? 'text-neutral-0' : 'text-primary-800' }}">makam.co.id</span>
    @endif
</span>
```

- [ ] **Step 4: Wire header + layout**

`header.blade.php`: at both call sites delete the hardcoded `Makam.co.id` text node after `<x-mk.logo … />` (the component now renders the wordmark). Keep the anchors' `text-lg`/`text-xl` (the wordmark span inherits size; weight/colour are its own). Update the file's docblock logo mention.

`layouts/app.blade.php`:
1. `<head>`, after the `<title>` line:
```blade
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
```
2. Footer: insert a brand row as the FIRST child of the footer's inner div (above the nav):
```blade
            <a href="/" class="inline-flex items-center gap-2" aria-label="makam.co.id — beranda">
                <x-mk.logo variant="inverse" :size="28" />
            </a>
```
3. Update the layout docblock: the footer gains the brand row (an *addition* — IA §3 item 9 content unchanged), and note the favicon links.

- [ ] **Step 5: Verify what this host can verify, label the rest**

`php -l` both test files. `bash ci/verify-docs.sh` → PASS. Blade compile safety is CI's `blade:verify-content-survival` — record `NOT RUN locally (platform)`.

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/mk/logo.blade.php resources/views/components/mk/header.blade.php \
        resources/views/layouts/app.blade.php tests/Feature/View/Components/MkLogoTest.php \
        tests/Feature/View/BrandIdentityTest.php
git commit -m "feat(brand): real logo in header/footer + favicon links (placeholder monogram retired)"
```

---

### Task 5: Filament palette regeneration + panel branding

**Files:**
- Modify (generated, never by hand): `app/Support/Design/generated/FilamentPalette.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`, `app/Providers/Filament/VendorPanelProvider.php` (brandLogo + docblock note)
- Test: `tests/Feature/Filament/PanelBrandingTest.php` (create; CI-run)

**Interfaces:**
- Consumes: Task 1's tokens.css; Task 3's `public/brand/mark-96.png`.
- Produces: panels render the Earth palette and the real mark.

- [ ] **Step 1: Regenerate the palette (framework-free path for this host)**

```bash
php -r '
require "app/Support/Design/FilamentPaletteGenerator.php";
use App\Support\Design\FilamentPaletteGenerator;
$p = FilamentPaletteGenerator::parseTokens("resources/css/tokens.css");
file_put_contents("app/Support/Design/generated/FilamentPalette.php", FilamentPaletteGenerator::render($p));
echo "regenerated\n";'
php -r '
require "app/Support/Design/FilamentPaletteGenerator.php";
use App\Support\Design\FilamentPaletteGenerator;
$diff = FilamentPaletteGenerator::diff(
    FilamentPaletteGenerator::parseTokens("resources/css/tokens.css"),
    require "app/Support/Design/generated/FilamentPalette.php");
echo $diff === [] ? "no drift\n" : implode("\n", $diff)."\n";'
```

Expected: `regenerated` then `no drift`; the generated file now carries the Earth hexes. (The generator already drops `secondary` for Filament — correct under the Leaf cage; no generator change.) CI's `design:verify-filament-palette` is the authoritative check.

- [ ] **Step 2: Confirm the Filament API before using it**

```bash
grep -rn "function brandLogo" vendor/filament/ | head -3
grep -rn "function brandLogoHeight\|function getBrandLogo" vendor/filament/ | head -3
```

Expected: signatures on a `HasBrandLogo`-style concern. If the names differ on the pinned v5.7.3, use what the vendor source actually exposes and record the correction in the task report (reading vendor source is how this repo verified `discoverResources`/`default()` before).

- [ ] **Step 3: Write the failing test (CI-run)**

`tests/Feature/Filament/PanelBrandingTest.php`:

```php
public function test_panels_carry_the_brand_mark_and_generated_palette(): void
{
    foreach (['admin', 'vendor'] as $id) {
        $panel = \Filament\Facades\Filament::getPanel($id);
        $logo = $panel->getBrandLogo();
        $this->assertNotNull($logo, "{$id} panel has no brand logo");
        $this->assertStringContainsString('brand/mark-96.png', (string) ($logo instanceof \Illuminate\Contracts\Support\Htmlable ? $logo->toHtml() : $logo));
        $this->assertSame('2rem', $panel->getBrandLogoHeight());
    }
}
```

- [ ] **Step 4: Wire both providers**

In both panel providers, immediately after `->colors($this->filamentColors())`:

```php
            // ADR-0034: official mark; stacked lockup reads badly at 2rem, so
            // the panel carries the mark — a horizontal lockup is OQ-12 scope.
            ->brandLogo(asset('brand/mark-96.png'))
            ->brandLogoHeight('2rem')
```

`->font('Inter var')` stays (panels are all-Inter by decision). Add one line to each provider's class docblock noting the brand wiring.

- [ ] **Step 5: Verify + commit**

`php -l` the providers + test; `bash ci/verify-docs.sh` → PASS; PHPUnit/artisan NOT RUN locally — CI evidence.

```bash
git add app/Support/Design/generated/FilamentPalette.php app/Providers/Filament/AdminPanelProvider.php \
        app/Providers/Filament/VendorPanelProvider.php tests/Feature/Filament/PanelBrandingTest.php
git commit -m "feat(brand): regenerate Filament palette from Earth tokens + panel brand marks"
```

---

### Task 6: design-system.md + CHANGELOG

**Files:**
- Modify: `docs/design/design-system.md` (sections enumerated below)
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Task 1's verifier output (paste real numbers), Tasks 2–5's shipped reality.
- Produces: documentation matching the code — the repo's rank-2 artefact re-synced to rank 1.

- [ ] **Step 1: Edit `docs/design/design-system.md`**

Make exactly these edits, no others:
1. §0.3 status: append that v0.2 adopted the official identity (ADR-0034), OQ-01/OQ-02 resolved.
2. §1.2 table: Primary → **"Earth"** with the sampled 600 hex + measured hue; Secondary → **"Leaf"** (restricted, hue ≈128°); danger row notes the hue tune to ≈352°.
3. §1.2 decision (a): rewrite — teal was the *provisional* choice under "no prior identity"; the official identity mandates Earth brown (philosophy quoted: earth/calm/stability/warmth/humanist); the green-CTA-vs-success-badge ambiguity is avoided structurally (brand fills are brown; Leaf never fills); OQ-01 resolved.
4. §1.2 decision (b): rewrite — Sandstone retired; Leaf inherits the cage, re-justified against success (146°), never fill/badge/button/alert.
5. §1.4 typography: `--font-display` = Poppins (OFL, self-hosted via `@fontsource/poppins`, latin 600 only, §4.6 budget measured — paste the number from Task 2's report); add the `--font-document` row (Source Serif 4, documents only, consumed by future print.css); note h1/h2 consume Poppins, h3/h4 stay Inter, Filament stays Inter.
6. §7.1: paste Task 1's real verifier output (ratios table replaced with measured values; keep the two historical findings, add the danger-hue tune as a third finding).
7. §8.3: replace the literal-hex `colors()` snippet with the generator reference (values are generated from tokens.css — never hand-maintained); keep the OQ-09-resolved note.
8. §8.5: annotate — when print.css is built, its body font is `var(--font-document)`, NOT `--font-display`.
9. §9.2 MUST-NOT #7: reword — `secondary` (**Leaf**) as a fill/badge/button/alert; the rule stands, the family changed.
10. §10 quick reference: COLOUR block updated (Earth/Leaf/danger note).
11. §11: OQ-01 → **Resolved (Earth, ADR-0034)**; OQ-02 → **Resolved (official identity adopted 17 Aug 2026)**; OQ-03 → amended (Poppins added as display; Inter/Source Serif 4 retained per role); add **OQ-12** — official brand hexes + vector source + horizontal lockup; until then all brand values/artwork are PROVISIONAL (default in force: derived values).
12. §12: move the new verifier run into "Verified — executed, with evidence"; logo/favicon/rendered-palette rows updated to their true state (homepage renders the real mark — verified by `BrandIdentityTest` in CI; visual/real-device rows stay NOT TESTED).
13. §13 adoption checklist: item 1 checked (OQ-01/OQ-02 resolved).

- [ ] **Step 2: CHANGELOG entry**

Add under the newest section: brand identity adopted (Earth/Leaf palette, Poppins display, real logo + favicons, Filament sync) — ADR-0034; provisional pending OQ-12 official values.

- [ ] **Step 3: Gates + commit**

Run: `bash ci/verify-docs.sh` → `ALL DOC GATES PASS` (GATE 4 link resolution covers the edited doc).

```bash
git add docs/design/design-system.md CHANGELOG.md
git commit -m "docs(design): design-system v0.2 — identity adopted, OQ-01/02 resolved, OQ-12 opened"
```

---

### Task 7: Whole-branch verification + review

**Files:** none new — this task is the gate.

- [ ] **Step 1: Local gate sweep (this host)**

```bash
python3 docs/design/verify-contrast.py     # PASS
bash ci/verify-docs.sh                     # ALL DOC GATES PASS
npm ci --no-audit --no-fund && npm run build   # succeeds (smoke-level: node 22 vs pinned 24 — label it)
git status --short                         # only intended files
```

- [ ] **Step 2: Push and collect CI evidence**

Push the branch; confirm the full CI matrix green: docs gates, verify-versions, php (PHPUnit incl. the new brand tests + `design:verify-filament-palette` + `blade:verify-content-survival`), frontend (build + `font-display` assertion). Paste the run URL into the ledger (`.superpowers/sdd/brand-identity/progress.md`) — never claim green without it.

- [ ] **Step 3: Two-tier review**

Per AGENTS.md SDD: each task was reviewed against its brief before the next began; now the whole-branch review runs once (superpowers:requesting-code-review), findings triaged Critical/Important/Minor with one bounded fix wave.

- [ ] **Step 4: PR**

One PR to `docs/design-system-and-planning` via superpowers:finishing-a-development-branch. PR body: spec + plan links, ADR-0034 link, CI run link, before/after token table, the PROVISIONAL/OQ-12 callout, and the explicit NOT-TESTED list (visual/real-device/screen-reader).

---

## Self-Review (run by the plan author before handoff)

- **Spec coverage:** spec §2 items 1–7 → Tasks 1, 2, 3+4, 4, 5, (print → deviation (a) in Global Constraints), 6 → Tasks 1/6; spec §6 verification → Task 7; spec §7 tasks T1–T7 map 1:1. No gaps.
- **Placeholder scan:** every code step carries real code/commands; the two genuine environment forks (font subset file name; inverse-variant quality) carry explicit decision rules, not choices.
- **Type/path consistency:** asset names identical across Tasks 3/4/5 (`mark-96`, `mark-inverse-96`, `lockup-320/640`, root `favicon.ico`, `apple-touch-icon.png`); component props (`size`, `variant`, `wordmark`) identical between contract, tests, and call sites; `font-display` identical across tokens, app.css, smoke file, ci.yml, component.
