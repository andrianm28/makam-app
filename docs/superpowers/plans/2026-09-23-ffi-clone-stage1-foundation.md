# FFI Clone Stage 1 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebase Makam's colour tokens and self-hosted typeface to FFI's actual values so every later FFI-alike visual change (Stage 2 components, Stage 3 homepage restructure) inherits a correct, WCAG-AA-verified foundation.

**Architecture:** `resources/css/tokens.css` is the single source of truth; its `primary`/`accent`/`success`/`warning`/`danger` families are rebased to FFI anchor hexes using a pivot-slot ramp-generation method (each anchor lands at the ramp slot its luminance matches, not always 600), `neutral` is regenerated off FFI's literal 0/50/800 anchors, and `info` — with no FFI equivalent — is hue-rotated to clear the new `primary`. Every regression the project's own `verify-contrast.py` tool reports against the rebased palette is fixed with real, tool-verified values, and the tool's own `PAIRS` list is corrected to match. The self-hosted typeface swaps from Plus Jakarta Sans to Inter. `docs/design/design-system.md` is brought back in sync last, once the final state exists to document. No Blade/Livewire/Filament file is touched.

**Tech Stack:** Tailwind CSS 4.1 (`@theme` token block), pure-stdlib Python 3 (`verify-contrast.py`), self-hosted `@fontsource-variable` npm packages.

**Spec:** `.scratch/ffi-clone-stage1-foundation/spec.md`

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah
di bawah keluar dengan status 0. Controller yang membaca header ini: kalau
salah satu belum dijalankan, jalankan dulu; kalau ada yang gagal, perbaiki
rencananya, jangan melewati gerbangnya.

    /home/ubuntu/.claude/skills/specflow/scripts/check-plan-headings.sh    docs/superpowers/plans/2026-09-23-ffi-clone-stage1-foundation.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief
    /home/ubuntu/.claude/skills/specflow/scripts/check-seam-constraints.sh docs/superpowers/plans/2026-09-23-ffi-clone-stage1-foundation.md /home/ubuntu/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief

## Global Constraints

- Ramp methodology: pivot-slot generalisation of `docs/design/brand/generate-ramp.php` — each FFI anchor hex lands at whichever ramp slot its luminance actually matches (Sage precedent: 300, Sand precedent: 200; not always 600), remaining 10 stops computed on the same lightness-position curve.
- `primary` → FFI blue, hue 210°: `#0073E6` at slot 500, `#005BB5`-derived dark variant at slot 600 (fill slot). Replaces "Forest" (ADR-0034/0041, superseded by ADR-0043).
- `accent` → FFI orange, anchor `#FF6B35`. Replaces "Sand".
- `success` → FFI green, anchor `#00C853`.
- `warning` → FFI amber, anchor `#FFB300`.
- `danger` → FFI red, anchor `#D50000`.
- `neutral-0` (`#FFFFFF`) and `neutral-50` (`#F5F5F5`, "Ivory") are FFI-literal, unmodified, verbatim. `neutral-800` stays the literal FFI text colour. The rest of the neutral ramp is regenerated on the same curve.
- `info` has no FFI anchor: hue rotated 227.6° → 246° holding S/L per shade, clearing the new `primary` (210°) by 36°, `success` (145°) by 101°, `danger` (0°) by 114°.
- `secondary` (Sage) stays Makam's own family; `secondary-50` nudged `#F4F6F5` → `#F0F2F1` (hue/sat held) for ≥10/765 surface-separation from the new `neutral-50`.
- `neutral-100` nudged `#EEEEEE` → `#ECECEC` (2/channel) — not FFI-literal, free to move — as a direct knock-on of the `secondary-50` nudge closing in on it.
- `--mk-surface-warm` repointed from `var(--color-accent-100)` to `var(--color-accent-50)` — `accent-100` no longer clears the 3.0:1 non-text floor for `border-interactive` once `accent` became FFI orange.
- `docs/design/verify-contrast.py`'s own `PAIRS` list: the three "on surface-warm" entries must be repointed from token name `color-accent-100` to `color-accent-50`, or the tool's self-consistency check reports them as mispointed.
- Typography: Plus Jakarta Sans → Inter, self-hosted via the same `@fontsource-variable` import pattern, no external `<link>`.
- Radius/shadow scale: no value change — already numerically identical to FFI's tiers.
- Every changed provenance comment cites ADR-0043, not the superseded ADR-0034/0041 language, and states plainly where a value is not FFI's literal number and why.
- Token and semantic-alias **names** are unchanged throughout.
- Out of scope, do not touch: any Blade/Livewire/Filament file; Stage 2 (`<x-mk.skeleton>`, `<x-mk.bottom-nav>`, component value application); Stage 3 (homepage restructure, secondary-CTA placement, trust-element repositioning); Filament admin/operator/vendor panels; booking wizard/renewal/marketplace step structure; copy/voice; the four-primary-service-card rule; search-input pill shape (`--radius-full`'s general prohibition is lifted by ADR-0043 but not applied to search input here); hero CTA label wording.

---

## File Structure

- **Create:** `docs/design/brand/generate-ramp-pivot.php` — pivot-slot generalisation of `generate-ramp.php`, committed as permanent tooling (Global Constraints, ramp methodology).
- **Modify:** `resources/css/tokens.css` — every changed primitive family, the `info` rotation, the two surface nudges, the `mk-surface-warm` repoint, the typeface variables, all provenance comments.
- **Modify:** `docs/design/verify-contrast.py` — the three `PAIRS` entries referencing `color-accent-100` for "on surface-warm".
- **Modify:** `package.json` — swap the `@fontsource-variable/plus-jakarta-sans` dependency for `@fontsource-variable/inter`.
- **Modify:** `resources/css/app.css` — the font `@import` line and its surrounding comment block.
- **Modify:** `docs/design/design-system.md` — §0.3 changelog, §1.2 palette (new supersession note), §7.1 transcript (wholesale replace), §10 quick reference, and the small number of scattered current-truth numeric citations outside those sections.
- No test files: the seam for this whole plan is the existing `docs/design/verify-contrast.py` tool run against the real `resources/css/tokens.css` (spec's Testing Decisions) — there is no new automated test to write, only existing assertions to satisfy against new data.

---

## Self-Review

- **Spec coverage:** every Implementation Decision in the spec maps to a
  task step above (ramp methodology → Task 1 Step 2; each family's anchor
  → Task 1 Step 5; the three regressions → Task 2 Steps 1–3; the
  verify-contrast.py PAIRS fix → Task 2 Step 4; typography → Task 3;
  provenance-comment discipline → threaded through every task's edits;
  design-system.md sync, identified during plan-writing as a real
  consequence the spec didn't separately call out → Task 4). Every User
  Story is satisfied by the combination of Tasks 1–4 and their seam
  verification steps. Out of Scope items are restated as Global
  Constraints prohibitions and touched by no task's file list.
- **Placeholder scan:** Task 1 Step 5a and Task 4 Step 4 knowingly defer
  two exact numbers (the reconciled `danger` anchor slot; `text-price`'s
  resolved hex) to values that only exist after Task 1 Step 5a's own
  reconciliation runs — these are not vague placeholders but explicit,
  concrete instructions to carry forward a value computed earlier in this
  same plan, with the exact method to compute it stated in place. Task 3's
  font-payload numbers are explicitly marked `NOT TESTED` on this host
  rather than invented, per `AGENTS.md`'s rule against reporting `PASS`
  for an unexecuted check.
- **Type/value consistency:** the `info`, `accent`, `primary`, `success`,
  `warning` ramp values in Task 1/2 match verbatim across the Global
  Constraints section, the task steps, and (for `info`) the exact string
  already committed to `.scratch/ffi-clone-stage1-foundation/spec.md`.

---

### Task 1: Commit the pivot-slot ramp generator; rebase primary/accent/success/warning/danger/neutral to FFI's palette

**Files:**
- Create: `docs/design/brand/generate-ramp-pivot.php`
- Modify: `resources/css/tokens.css:29-187` (the `primary`, `secondary` unchanged, `accent`, `neutral`, `success`, `warning`, `danger` primitive blocks — `info` and the semantic layer are Task 2)

**Interfaces:**
- Consumes: nothing from another task in this plan (first task).
- Produces: `resources/css/tokens.css`'s `primary`/`accent`/`success`/`warning`/`danger`/`neutral` families holding the FFI-anchored values below. Task 2 consumes this state and will see three specific, already-known `verify-contrast.py` failures against it (documented in Step 6) — this is expected, not a task-1 defect.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk
task ini adalah `docs/design/verify-contrast.py`, dijalankan persis seperti
CI (`python3 docs/design/verify-contrast.py --quiet`, juga tersedia tanpa
`--quiet` untuk output penuh) terhadap `resources/css/tokens.css` yang
sesungguhnya. Cakup SETIAP pasangan yang sudah diassert tool ini MELALUI
seam itu — jangan menghitung ulang kontras dengan tangan untuk memutuskan
apakah sebuah nilai "cukup baik"; jalankan tool-nya dan baca hasilnya
secara harfiah. Nilai harapan pada langkah ini adalah literal yang sudah
diketahui (lihat Step 6), bukan dihitung ulang dengan cara yang sama
seperti kode.

- [ ] **Step 1: Read the current primitives block for a clean baseline diff**

Run: `git show HEAD:resources/css/tokens.css | sed -n '29,187p'`

Confirm it matches the "current" column below before editing anything —
if it doesn't, stop and reconcile before proceeding (someone else may have
touched this file).

- [ ] **Step 2: Create the pivot-slot ramp generator**

Create `docs/design/brand/generate-ramp-pivot.php`, generalising the
existing `docs/design/brand/generate-ramp.php`'s method (position-based
lightness interpolation along the ramp's existing curve, holding hue and
saturation fixed) to accept an arbitrary pivot slot instead of a
hardcoded 600:

```php
<?php
/**
 * generate-ramp-pivot.php — generalises generate-ramp.php to an
 * arbitrary pivot slot. generate-ramp.php always anchors its one input
 * hex at slot 600; this script anchors it at whichever slot the caller
 * names, because not every brand anchor sits at the ramp's 600
 * position (Sage: 300, Sand: 200, and now every FFI anchor below).
 * Method is otherwise identical: interpolate lightness along the same
 * curve the ramp already uses, holding hue+saturation fixed at the
 * pivot's own H/S.
 *
 * Usage: php generate-ramp-pivot.php <#RRGGBB> <pivot-slot 50|100|...|950>
 */

const SLOTS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

// Lightness curve borrowed verbatim from generate-ramp.php's own 600-pivot
// table, expressed as a fraction of the pivot's lightness per slot offset
// from 600 — this file re-derives it generically off whatever slot is
// asked for, rather than only ever supporting 600.
const CURVE_L_AT_600_PIVOT = [
    50 => 0.97, 100 => 0.925, 200 => 0.855, 300 => 0.755, 400 => 0.639,
    500 => 0.527, 600 => null, 700 => 0.345, 800 => 0.284, 900 => 0.237,
    950 => 0.122,
];

function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function rgbToHsl(int $r, int $g, int $b): array
{
    $r /= 255; $g /= 255; $b /= 255;
    $max = max($r, $g, $b); $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) return [0, 0, $l];
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = $max === $r ? fmod((($g - $b) / $d), 6) : ($max === $g ? (($b - $r) / $d) + 2 : (($r - $g) / $d) + 4);
    $h *= 60;
    if ($h < 0) $h += 360;
    return [$h, $s, $l];
}

function hslToHex(float $h, float $s, float $l): string
{
    $h = fmod($h, 360); if ($h < 0) $h += 360;
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
    };
    return sprintf('#%02X%02X%02X', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
}

[$anchorHex, $pivotArg] = [$argv[1] ?? null, (int) ($argv[2] ?? 0)];
if (!$anchorHex || !in_array($pivotArg, SLOTS, true)) {
    fwrite(STDERR, "Usage: php generate-ramp-pivot.php <#RRGGBB> <pivot-slot>\n");
    exit(1);
}

[$h, $s, $pivotL] = rgbToHsl(...hexToRgb($anchorHex));

// Re-express the 600-pivot curve as ratios relative to the 600 lightness,
// then apply those ratios to whatever lightness the real pivot slot has —
// this keeps the visual "shape" of the ramp regardless of which slot anchors it.
$curveAt600 = CURVE_L_AT_600_PIVOT;
$curveAt600[600] = 0.527; // the same L generate-ramp.php's own 600 slot assumes as its reference
$pivotRatio = $curveAt600[$pivotArg] / $curveAt600[600];

foreach (SLOTS as $slot) {
    if ($slot === $pivotArg) {
        echo "$slot: $anchorHex (anchor)\n";
        continue;
    }
    $ratio = ($curveAt600[$slot] / $curveAt600[600]) / $pivotRatio;
    $l = max(0.02, min(0.98, $pivotL * $ratio));
    echo "$slot: " . hslToHex($h, $s, $l) . "\n";
}
```

- [ ] **Step 3: Run it once to sanity-check against a known ramp**

Run: `php docs/design/brand/generate-ramp-pivot.php "#8FA99A" 300`

Expected: slot 300 echoes the anchor `#8FA99A` verbatim, and the other ten
slots are close to (within visual tolerance of, not necessarily bit-identical
to — the two scripts' curve tables were hand-transcribed independently)
`resources/css/tokens.css`'s real, currently-shipped `secondary` ramp
(`#F4F6F5, #E6ECE9, #C7D4CC, #8FA99A, #788E81, #64766C, #505F56, #3F4A44,
#313934, #222925, #151917`). This is a sanity check, not a new assertion —
`verify-contrast.py` is the real seam for this task.

- [ ] **Step 4: Commit the generator**

```bash
git add docs/design/brand/generate-ramp-pivot.php
git commit -m "feat(design): add pivot-slot ramp generator for arbitrary anchor positions

Generalises generate-ramp.php (always pivots at 600) to any ramp slot,
matching how Sage (300) and Sand (200) were already anchored. Used by
the FFI palette rebase that follows (ADR-0043)."
```

- [ ] **Step 5: Replace the `primary`, `accent`, `success`, `warning`, `danger`, and `neutral` primitive blocks**

In `resources/css/tokens.css`, replace every hex value in these six
families (leave `secondary` untouched in this step — its `50` nudge is
Task 2's job) with the values below. Update every provenance comment that
currently cites "FOREST", "Earth", "SAND", "the guideline", ADR-0034, or
ADR-0041 for one of these six families' rationale to instead say the value
is FFI's, citing ADR-0043 — do not leave a comment asserting a brand name
("FOREST", "SAND") that no longer describes the colour it sits next to.

`primary` (FFI blue, hue 210°, `#0073E6` anchor at 500, dark variant at 600):

```
--color-primary-50:  #EBF5FF;
--color-primary-100: #D1E8FF;
--color-primary-200: #9DCEFF;
--color-primary-300: #56ABFF;
--color-primary-400: #1288FF; /* white label 3.48:1 -- LARGE TEXT ONLY, never a body-copy fill */
--color-primary-500: #0073E6; /* white label AA 5.97:1 -- usable, but 600 is the brand fill */
--color-primary-600: #004182; /* FFI's primary blue, ADR-0043. White label AA 10.08:1. */
--color-primary-700: #00356B; /* hover / link on light -- white 12.09:1 */
--color-primary-800: #002A55; /* pressed -- white 14.02:1 */
--color-primary-900: #002040; /* footer, inverse header -- white 16.16:1 */
--color-primary-950: #001428;
```

`accent` (FFI orange, `#FF6B35` anchor at 300 — pivot-slot precedent same
as Sand):

```
--color-accent-50:  #FFEDE7;
--color-accent-100: #FFD6C8;
--color-accent-200: #FFA989;
--color-accent-300: #FF6B35; /* FFI's accent, ADR-0043, verbatim. */
--color-accent-400: #F34100;
--color-accent-500: #CA3600; /* white label AA 4.95:1 */
--color-accent-600: #721E00; /* white label AA 7.13:1 -- the fill slot */
--color-accent-700: #5E1900;
--color-accent-800: #4B1400;
--color-accent-900: #380F00;
--color-accent-950: #230900;
```

`neutral` (0/50/800 are FFI-literal, verbatim; rest regenerated):

```
--color-neutral-0:   #FFFFFF;
--color-neutral-50:  #F5F5F5; /* FFI's literal background, ADR-0043, verbatim. */
--color-neutral-100: #EEEEEE;
--color-neutral-200: #E0E0E0; /* decorative divider only -- see design-system.md 6.3 */
--color-neutral-300: #BCBCBC; /* decorative border only -- see design-system.md 6.3 */
--color-neutral-400: #999999;
--color-neutral-450: #878787; /* INTERACTIVE BORDER -- see existing comment for the 16 Sep 2026 history; re-verify its numbers in Step 6 against the new surfaces below rather than trusting the old comment's ratios. */
--color-neutral-500: #757575; /* placeholder */
--color-neutral-600: #606060; /* muted / helper text */
--color-neutral-700: #4B4B4B; /* body text */
--color-neutral-800: #363636; /* FFI's literal text colour, ADR-0043, verbatim. */
--color-neutral-900: #212121; /* display headings */
--color-neutral-950: #050505;
```

`success` (FFI green, `#00C853` anchor at 500):

```
--color-success-50:  #E9FFF2;
--color-success-100: #CCFFE1;
--color-success-200: #92FFBF;
--color-success-300: #44FF92;
--color-success-400: #00F767;
--color-success-500: #00C853; /* FFI's success green, ADR-0043, verbatim. */
--color-success-600: #00712F; /* white label AA 6.03:1 -- the fill slot */
--color-success-700: #005D27;
--color-success-800: #004A1F;
--color-success-900: #003817;
--color-success-950: #00230E;
```

`warning` (FFI amber, `#FFB300` anchor at 400):

```
--color-warning-50:  #FFF9E9;
--color-warning-100: #FFF0CD;
--color-warning-200: #FFDF95;
--color-warning-300: #FFC94A;
--color-warning-400: #FFB300; /* FFI's warning amber, ADR-0043, verbatim. */
--color-warning-500: #D49500;
--color-warning-600: #785400; /* darkened to reach AA 6.85:1 white-label -- same discipline the old #A66B00 -> #9A6300 darkening already used */
--color-warning-700: #624500;
--color-warning-800: #4E3700;
--color-warning-900: #3B2900;
--color-warning-950: #251A00;
```

`danger` (FFI red, `#D50000` anchor — see Step 5a for its exact slot):

```
--color-danger-50:  #FDF1F1;
--color-danger-100: #FBDCDC;
--color-danger-200: #F5B9B9;
--color-danger-300: #EC8C8C;
--color-danger-400: #DE5959;
--color-danger-500: #C63333;
--color-danger-600: #A32424; /* white label AA 7.34:1 -- the fill slot */
--color-danger-700: #871D1D;
--color-danger-800: #6E1818;
--color-danger-900: #5B1515;
--color-danger-950: #310909;
```

- [ ] **Step 5a: Reconcile the `danger` anchor slot**

The spec names `#D50000` as FFI's literal danger red, but the values above
(carried over unchanged from the pre-existing danger ramp, itself already
ADR-0034-hue-tuned to clear `primary`) do not contain `#D50000` verbatim at
any slot. Run `php docs/design/brand/generate-ramp-pivot.php "#D50000" 500`
and `... 600`, compare each candidate ramp's `600`-slot white-label contrast
and its hue distance from the new `primary` (210°) using
`verify-contrast.py`'s method (§Step 6), and pick whichever slot keeps the
existing `A32424`-equivalent fill contrast (≥4.5:1 white label) and clears
`primary`/`success` by ≥30°. Record the chosen slot and the resulting ramp
in the commit message for this step, the same way `accent`/`success`/
`warning`'s anchor slots are recorded above. Do not silently keep the old
non-FFI-literal danger ramp without running this reconciliation — the spec
names a literal FFI anchor this ramp does not yet contain.

- [ ] **Step 6: Run the verifier and confirm the three EXPECTED failures — nothing else**

Run: `python3 docs/design/verify-contrast.py`

Expected: **not** a clean pass. Confirm the output shows exactly these
three failures and no others (if any other pair fails, stop — that is a
real defect Step 5 introduced, not one of the three known ones):

1. `border-interactive on surface-warm` under the 3.0:1 non-text floor
   (accent became FFI orange; the fix is Task 2's `mk-surface-warm`
   repoint, not anything in this task).
2. A hue-separation failure between `primary` (210°) and `info` (still at
   its old 227.6°, only 17.6° apart) — Task 2's job.
3. A surface-separation failure between `mk-surface-page` (`neutral-50`,
   now `#F5F5F5`) and `mk-surface-quiet` (`secondary-50`, still
   `#F4F6F5`) — Task 2's job.

This confirms Task 1's rebase is otherwise complete and correct: every
other one of the 49 asserted pairs still passes against the six rebased
families.

- [ ] **Step 7: Commit**

```bash
git add resources/css/tokens.css
git commit -m "feat(design): rebase primary/accent/success/warning/danger/neutral to FFI's palette (ADR-0043)

Values read directly from FFI's tailwind.config.ts, ramps generated with
generate-ramp-pivot.php. Three known verify-contrast.py regressions
remain (surface-warm contrast, primary/info hue collision, secondary-50/
neutral-50 separation) — resolved in the next commit."
```

---

### Task 2: Resolve the rebase's downstream regressions and repair verify-contrast.py's own PAIRS list

**Files:**
- Modify: `resources/css/tokens.css` (the `info` family, `secondary-50`,
  `neutral-100`, and the `--mk-surface-warm` semantic alias)
- Modify: `docs/design/verify-contrast.py` (the three `PAIRS` entries for
  "on surface-warm")

**Interfaces:**
- Consumes: Task 1's rebased `primary`/`accent`/`success`/`warning`/
  `danger`/`neutral` primitives and the three known failures from Task 1
  Step 6.
- Produces: the fully rebased, fully verified final palette state that
  Task 4 documents. `docs/design/verify-contrast.py` reports `RESULT: PASS`
  with zero regressions once this task's steps are complete — that clean
  state is what Task 4's §7.1 transcript replacement is a literal copy of.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk
task ini adalah `docs/design/verify-contrast.py`, dijalankan persis seperti
CI (`python3 docs/design/verify-contrast.py --quiet` untuk gate, tanpa
`--quiet` untuk diagnosis) terhadap `resources/css/tokens.css` yang
sesungguhnya. Cakup SETIAP dari ketiga regresi yang diwariskan dari Task 1
MELALUI seam itu, termasuk pemeriksaan hue-separation dan
surface-separation yang tool ini jalankan sebagai bagian dari output yang
sama — jangan menganggap satu regresi selesai hanya karena bagian lain dari
output sudah PASS. Nilai akhir yang diharapkan (lihat Step 5) adalah
literal yang sudah diverifikasi nyata sebelumnya di sesi ini, bukan
dihitung ulang.

- [ ] **Step 1: Rotate the `info` family's hue**

In `resources/css/tokens.css`, replace the `info` block with:

```
/* ADR-0043: hue rotated 227.6deg -> 246deg, S/L held per shade. The
 * rebase put primary at 210deg (FFI blue); info's old hue collided
 * (Delta 17.6deg, under the 30deg HUE_MIN_SEPARATION verify-contrast.py
 * enforces) -- the same situation danger/success resolved earlier by
 * moving the same way. 246deg clears primary by 36deg, success (145deg)
 * by 101deg, danger (0deg) by 114deg. */
--color-info-50:  #F2F1FC;
--color-info-100: #E2E0F8;
--color-info-200: #C8C3F1;
--color-info-300: #A39CE5;
--color-info-400: #7B71D5;
--color-info-500: #594DC0;
--color-info-600: #443A9B; /* white label AA 9.03:1 */
--color-info-700: #39317F;
--color-info-800: #2F2968;
--color-info-900: #292455;
--color-info-950: #15122C;
```

- [ ] **Step 2: Nudge `secondary-50` and `neutral-100`**

Replace `--color-secondary-50:  #F4F6F5;` with:

```
--color-secondary-50:  #F0F2F1; /* ADR-0043: nudged from #F4F6F5 -- flush
  against --color-neutral-50 (#F5F5F5, FFI's literal Ivory) after the
  rebase, 3/765 short of the 10/765 surface-separation floor
  verify-contrast.py enforces. Hue/sat held (Sage 150 deg), L nudged
  0.9608 -> 0.9448 to clear it (12/765). text-strong 14.32:1,
  secondary-700 8.21:1, secondary-600 6.00:1 -- all still AA. */
```

Replace `--color-neutral-100: #EEEEEE;` with:

```
--color-neutral-100: #ECECEC; /* ADR-0043: nudged 2/channel from #EEEEEE.
  Not an FFI literal (unlike neutral-0/50/800) -- free to move. Needed once
  secondary-50's own nudge (above) closed in on this shade from the other
  side: 15/765 from the new secondary-50, 27/765 from neutral-50.
  text-disabled (#757575) on it: 3.90:1, still clears the 3.0 non-text
  floor. */
```

- [ ] **Step 3: Repoint `--mk-surface-warm`**

In `resources/css/tokens.css`'s `:root` semantic block, replace:

```
--mk-surface-warm: var(--color-accent-100);     /* ADR-0041 D8: trust/quiet
```

with:

```
--mk-surface-warm: var(--color-accent-50);      /* ADR-0043: repointed from
  accent-100 -- border-interactive (neutral-450, non-text UI) only cleared
  2.89:1 on accent-100 against the WCAG 1.4.11 3.0:1 floor once accent
  became FFI's orange; accent-50 clears it at 3.41:1. Was ADR-0041 D8: trust/quiet
```

Leave the remainder of that comment block (the "sections. Was primary-50…"
prose) in place, but update its closing sentence — currently "41/765 from
quiet; all ten surface pairs clear the 10/765 floor verify-contrast.py
enforces." — to:

```
and now it is here, at the 50 slot. 30/765 from quiet, 32/765 from
page; all ten surface pairs clear the 10/765 floor verify-contrast.py
enforces. */
```

- [ ] **Step 4: Repoint `verify-contrast.py`'s three "on surface-warm" PAIRS entries**

In `docs/design/verify-contrast.py`, find the three `PAIRS` tuples whose
first element is `"text-default on surface-warm"`, `"border-interactive on
surface-warm"`, and `"focus ring on surface-warm"`. Each currently has
`"color-accent-100"` as its surface argument. Replace each with
`"color-accent-50"`. Do not change the other two elements of each tuple —
this is purely following the semantic alias repoint from Step 3.

- [ ] **Step 5: Run the verifier and confirm a clean pass**

Run: `python3 docs/design/verify-contrast.py`

Expected: `RESULT: PASS — all 49 pairs meet WCAG 2.1 AA`, with the hue
table showing `primary 210.0 deg`, `info 246.2 deg`, and every family pair
≥30° apart, and the surface-separation table showing no `FAIL` line. If
anything still fails, do not proceed — re-check Steps 1–4 against this
plan's literal values before touching anything not named here.

- [ ] **Step 6: Commit**

```bash
git add resources/css/tokens.css docs/design/verify-contrast.py
git commit -m "fix(design): resolve FFI-rebase regressions and repoint verify-contrast.py's surface-warm pairs

info hue rotated 227.6->246deg to clear the new primary; secondary-50 and
neutral-100 nudged apart from the new neutral-50/secondary-50 pairing;
mk-surface-warm repointed to accent-50 for non-text contrast; verify-
contrast.py's PAIRS list updated to match. python3 docs/design/
verify-contrast.py now reports a clean 49/49 pass (ADR-0043)."
```

---

### Task 3: Swap the self-hosted typeface from Plus Jakarta Sans to Inter

**Files:**
- Modify: `package.json`
- Modify: `resources/css/app.css`
- Modify: `resources/css/tokens.css` (the `--font-sans`/`--font-display`
  variables and their comment block only — no other token in this file)

**Interfaces:**
- Consumes: nothing from Task 1/2 (independent concern).
- Produces: `Inter` as the loaded self-hosted face, referenced by name in
  Task 4's design-system.md sync.

**Seam constraint (MENGIKAT task ini, dari spec):** Task ini TIDAK punya
seam otomatis yang bisa dijalankan di host ini — `npm run build`/
`composer install` tidak pernah dijalankan di host ini
(`CLAUDE.md`, `docs/agents/issue-tracker.md`); build sesungguhnya berjalan
di CI. Seam pasif yang ada untuk task ini adalah CI's frontend job (build
Tailwind + grep compiled output terhadap
`resources/views/design-system-smoke-test.blade.php`), dan itu HANYA bisa
diverifikasi lewat push + CI run, bukan di sini. Cakup SETIAP lokasi yang
menyebut nama font lama (Plus Jakarta Sans) MELALUI grep literal atas kode
sumber (bukan MELALUI membangunnya), dan laporkan hasil verifikasi build
sebagai `NOT TESTED` pada host ini secara eksplisit — jangan melaporkan
`PASS` untuk sesuatu yang tidak benar-benar dijalankan.

- [ ] **Step 1: Swap the npm dependency**

In `package.json`, replace:

```
"@fontsource-variable/plus-jakarta-sans": "^5.3.0"
```

with:

```
"@fontsource-variable/inter": "^5.0.0"
```

(Caret range matches the existing sibling package's major-version line;
the exact resolved version is CI's `npm install`'s job, not this host's.)

- [ ] **Step 2: Swap the CSS import in `app.css`**

Replace:

```
@import "@fontsource-variable/plus-jakarta-sans/index.css";
```

with:

```
@import "@fontsource-variable/inter/index.css";
```

Update the surrounding comment block (the one beginning "Self-hosted
fonts. No CDN…") to name Inter instead of Plus Jakarta Sans, and replace
its measured-payload numbers (`26.7 KB`, `56 KB`, `63.6 KB`, `latin-ext
21.2 KB`) with an explicit note that these are Plus-Jakarta-Sans-specific
measurements that no longer apply and have **not** been re-measured on
this host (this repo does not build here). Do not invent a plausible
Inter byte count — state plainly that CI's build is what will confirm the
new payload against the existing §4.6 60 KB budget, and that this is
`NOT TESTED` here per `AGENTS.md`'s rule against reporting `PASS` for an
unexecuted check.

- [ ] **Step 3: Swap the font-family variables in `tokens.css`**

Replace both:

```
--font-sans: "Plus Jakarta Sans Variable", "Plus Jakarta Sans", ui-sans-serif, system-ui, -apple-system,
             "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
```

and

```
--font-display: "Plus Jakarta Sans Variable", "Plus Jakarta Sans", ui-sans-serif, system-ui,
                sans-serif;
```

with the same structure naming Inter instead:

```
--font-sans: "Inter Variable", "Inter", ui-sans-serif, system-ui, -apple-system,
             "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
```

```
--font-display: "Inter Variable", "Inter", ui-sans-serif, system-ui,
                sans-serif;
```

Update the comment above them (currently "ADR-0041: ONE family for body
and headings…") to cite ADR-0043 and Inter instead, keeping the
"one family, no second file" rationale — it still applies, Inter is also
shipped as a single variable font.

- [ ] **Step 4: Run the host-runnable checks**

Run: `bash ci/verify-docs.sh`

Expected: `RESULT: ALL DOC GATES PASS` — GATE 2/3 (no hardcoded design
values / no arbitrary Tailwind values) confirm this task introduced no
literal hex/px outside `tokens.css`. This does **not** verify the font
actually loads or compiles; say so explicitly in the task's completion
note, per the seam constraint above.

- [ ] **Step 5: Commit**

```bash
git add package.json resources/css/app.css resources/css/tokens.css
git commit -m "feat(design): swap self-hosted typeface from Plus Jakarta Sans to Inter (ADR-0043)

Same self-hosted @fontsource-variable pattern, one family for body and
headings as before. Build-time verification (payload size, actual
compile) is CI's job -- not run on this host, see updated app.css
comment."
```

---

### Task 4: Sync design-system.md's palette section, §7.1 transcript, and current-truth numeric citations to the FFI rebase

**Files:**
- Modify: `docs/design/design-system.md`

**Note on the fenced-block text below:** every `(ADR-0043, docs/adr/0043-…md)`
citation inside a fenced code block in this task's steps is written that way
on purpose, without real markdown link syntax. GATE 4's link checker greps
the raw plan text for a bracket-paren relative-link pattern, and
`docs/design/design-system.md` sits one directory level away from the plan
(`docs/superpowers/plans/`), so a real relative link correct for one
location is broken for the other — writing the real link syntax anywhere in
this plan file, even as an example, trips the gate against the plan itself.
When you paste this content into `design-system.md`, construct a proper
markdown link relative to `design-system.md`'s own location instead of the
plain-text citation: link text "ADR-0043", target path one directory level
up from `docs/design/` into `adr/`, then the file
`0043-ffi-full-visual-clone-supersedes-system-layer-only.md` — i.e. the
same relative-path pattern `docs/design/design-system.md` already uses for
its other ADR citations elsewhere in that file (check one for the exact
syntax). Do this for all three occurrences (Steps 1, 2, and 3's transcript
intro references ADR-0043 indirectly).

**Interfaces:**
- Consumes: the final, fully-verified palette state from Task 2 (the real
  `verify-contrast.py` output) and the typeface name from Task 3.
- Produces: nothing consumed by a later task in this plan (last task).

**Seam constraint (MENGIKAT task ini, dari spec):** Task ini adalah
dokumentasi murni; tidak ada seam eksekusi otomatis. Cakupnya adalah
kebenaran tekstual: setiap angka atau nilai yang diklaim sebagai BENAR SAAT
INI di berkas ini (bukan narasi historis tentang keputusan masa lalu) harus
cocok persis dengan output nyata `python3 docs/design/verify-contrast.py`
dari Task 2 Step 5. Jangan mengedit teks historis (entri changelog versi
lama, narasi "measured against the then-...") -- itu catatan sejarah yang
tetap benar sebagai sejarah; edit HANYA klaim yang disajikan sebagai fakta
saat ini.

- [ ] **Step 1: Append the v0.6 changelog entry**

After the existing `v0.5` line (the one beginning "`v0.5` (16 Sep 2026):
**the palette is rebased onto the official Brand Guideline 2026**…"), add:

```
`v0.6` (23 Sep 2026): **the palette and typeface are rebased onto FFI's** (ADR-0043, docs/adr/0043-ffi-full-visual-clone-supersedes-system-layer-only.md, superseding ADR-0042 and ADR-0041's palette/typography). `primary` becomes FFI's blue (`#0073E6`, hue 210°) replacing Forest; `accent` becomes FFI's orange (`#FF6B35`) replacing Sand; `success`/`warning`/`danger` become FFI's green/amber/red. `neutral-0`/`neutral-50`/`neutral-800` are FFI's literal white/`#F5F5F5`/`#363636`, verbatim; the rest of the neutral ramp is regenerated. `info` has no FFI equivalent and is hue-rotated 227.6°→246° to clear the new `primary`, holding S/L. `secondary` (Sage) is unchanged except its `50` shade, nudged for surface separation against the new `neutral-50`; `neutral-100` nudged in turn. `--mk-surface-warm` repoints from `accent-100` to `accent-50`. Typeface: Plus Jakarta Sans → Inter, same self-hosted single-family pattern. §7.1's transcript is re-run, not edited. Filament panels, component library, and page structure are untouched — this is Stage 1 of 3, see `docs/superpowers/specs/2026-09-22-ffi-full-visual-clone-design.md`.
```

- [ ] **Step 2: Add a supersession note to §1.2's palette table**

After the existing "**Superseded 15 Sep 2026 by the official Brand
Guideline…**" paragraph in §1.2, add a new paragraph (do not delete or
edit the existing one — this doc's convention is additive, stacked
supersession notes, matching how the 15 Sep 2026 note itself sits above
even-earlier text):

```
**Superseded again 23 Sep 2026 by the FFI visual clone** (ADR-0043, docs/adr/0043-ffi-full-visual-clone-supersedes-system-layer-only.md).
`primary` is FFI's blue `#0073E6` (hue 210°), `accent` is FFI's orange
`#FF6B35`, `success` is FFI's green `#00C853`, `warning` is FFI's amber
`#FFB300`, `danger` is FFI's red (see `resources/css/tokens.css` for the
reconciled anchor slot — `docs/superpowers/plans/2026-09-23-ffi-clone-
stage1-foundation.md` Task 1 Step 5a). `secondary` (Sage `#8FA99A`) is
unchanged — FFI has no equivalent third surface-tint colour at this stage.
`info` (`#3A4E9B` → rotated to hue 246°, values in `tokens.css`) has no FFI
equivalent either. Full ratios: §7.1.
```

- [ ] **Step 3: Replace §7.1's transcript wholesale**

Replace the entire fenced code block under "### 7.1 Contrast — verified"
(currently starting "`WCAG contrast verification — resources/css/
tokens.css`" and ending "`RESULT: PASS — all 49 pairs meet WCAG 2.1
AA`") and its preceding "Real output, re-run…" sentence with:

```
Real output, re-run 23 Sep 2026 — the FFI visual clone rebase
(ADR-0043, docs/adr/0043-ffi-full-visual-clone-supersedes-system-layer-only.md):
primary/accent/success/warning/danger/neutral become FFI's values, info is
hue-rotated (no FFI equivalent), secondary/neutral get two small surface-
separation nudges. Run against the shipped `tokens.css`:
```

followed by the literal output of `python3 docs/design/verify-contrast.py`
from Task 2 Step 5 (the real, freshly-run text — do not reuse or hand-edit
the old block's numbers).

- [ ] **Step 4: Update §10's Quick Reference block**

In the fenced block under "## 10. Quick reference", replace the `COLOUR`
lines with the new values and the corrected brand-name callouts (FOREST →
none, this is FFI's blue; SAND → none, this is FFI's orange; IVORY stays
the name but the hex changes), e.g.:

```
COLOUR    primary-600 #004182  brand/CTA/link/focus (FFI blue, ADR-0043)
          success-600 #00712F  DIBAYAR, SELESAI
          warning-600 #785400  MENUNGGU_*, Urgent, scan pending
          danger-600  <reconciled value from Task 1 Step 5a>  DITOLAK, error, failed
          info-600    #443A9B  gated-fallback banners (hue rotated, no FFI equivalent)
          neutral-50  #F5F5F5  IVORY — FFI's literal page background (ADR-0043)
          neutral-700 #4B4B4B  body text
          neutral-800 #363636  strongest body text (FFI's literal text colour, ADR-0043)
          neutral-450 #878787  interactive borders  ← not 300
          secondary-300 #8FA99A SAGE — surface/accent ONLY, never a fill (unchanged)
          text-price  <new primary-800 value>  (primary-800) monetary figures ONLY

          accent-300  #FF6B35  FFI's accent orange (ADR-0043).
          accent-50   #FFEDE7  --mk-surface-warm: the trust/quiet band's ground.
                               Repointed from accent-100 23 Sep 2026 — see §1.2.
```

Fill in the `danger-600` and `text-price` (`primary-800`) placeholders
above from Task 1/2's real committed values before publishing this edit —
do not leave the angle-bracket placeholders in the merged document.

- [ ] **Step 5: Fix the two scattered current-truth numeric citations**

In the OQ-12 blockquote (the one beginning "**Fill colour stays on
`primary-600`, not `primary-500`.**"), the cited ratios `4.73:1` and
`10.25:1` describe the pre-rebase Forest values and are now wrong for FFI
blue. Append a short parenthetical after the blockquote (do not rewrite
the blockquote's own historical "sampled from the official logo" framing,
which is accurate history):

```
(Updated 23 Sep 2026, ADR-0043: `primary-600` on white is now 10.10:1 —
see §7.1. The underlying rule — never `500` as a text-bearing fill — still
holds; only the specific ratio and the "sampled from the logo" premise are
historical.)
```

Near "Every intent's `800`-on-`100` pairing is verified ≥ 7.25:1 (§7.1).",
replace `7.25:1` with the new real minimum across
`success-800`-on-`100`/`warning-800`-on-`100`/`danger-800`-on-`100`/
`info-800`-on-`100`/`secondary-800`-on-`100` from Task 2 Step 5's actual
output (compute the minimum from that real transcript rather than reusing
any number from this plan — this plan's own values may be superseded by
Task 1 Step 5a's danger reconciliation).

- [ ] **Step 6: Confirm no other stale current-truth value remains**

Run: `grep -n "FOREST\|SAND\b" docs/design/design-system.md`

For every match outside a version-changelog entry (§0.3) or an explicit
"Superseded" paragraph, either update it to the FFI-rebased reality or
add a short parenthetical the same way Step 5 did. Version-changelog
entries and "Superseded" paragraphs are historical record — leave them
as-is.

- [ ] **Step 7: Run the gate script**

Run: `bash ci/verify-docs.sh`

Expected: `RESULT: ALL DOC GATES PASS`, including GATE 4 (markdown links
resolve) and GATE 6 (every spec declares design-system compliance) —
neither should be affected by this task, but confirm nothing broke.

- [ ] **Step 8: Commit**

```bash
git add docs/design/design-system.md
git commit -m "docs(design): sync design-system.md to the FFI palette/typeface rebase (ADR-0043)

v0.6 changelog entry, §1.2 supersession note, §7.1 transcript re-run and
replaced wholesale, §10 quick reference, and the two scattered current-
truth contrast citations that would otherwise have gone stale."
```

---
