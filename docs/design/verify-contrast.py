#!/usr/bin/env python3
"""
Makam.co.id — WCAG 2.1 contrast verifier for the design-token palette.

Parses resources/css/tokens.css (the single source of truth) and asserts every
colour pair the design system documents as AA-compliant.

Usage:
    python3 docs/design/verify-contrast.py
    python3 docs/design/verify-contrast.py --tokens path/to/tokens.css

Exit code 0 = all pairs pass. Non-zero = at least one pair regressed.

CI: run this in the frontend job. Any colour token change that breaks an
asserted pair MUST fail the build (docs/design/design-system.md §9.5).
No external dependencies — standard library only.
"""

import argparse
import colorsys
import pathlib
import re
import sys

NEED_TEXT = 4.5  # WCAG 1.4.3 normal text
NEED_LARGE = 3.0  # WCAG 1.4.3 large text (>=18.66px bold or >=24px)
NEED_NONTEXT = 3.0  # WCAG 1.4.11 non-text contrast (control boundaries)


# --------------------------------------------------------------------------- #
# Colour maths
# --------------------------------------------------------------------------- #
def _channel(value: int) -> float:
    c = value / 255
    return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4


def luminance(hex_colour: str) -> float:
    h = hex_colour.lstrip("#")
    if len(h) == 3:
        h = "".join(ch * 2 for ch in h)
    r, g, b = (int(h[i : i + 2], 16) for i in (0, 2, 4))
    return 0.2126 * _channel(r) + 0.7152 * _channel(g) + 0.0722 * _channel(b)


def contrast(fg: str, bg: str) -> float:
    la, lb = luminance(fg), luminance(bg)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)


def hue(hex_colour: str) -> float:
    h = hex_colour.lstrip("#")
    r, g, b = (int(h[i : i + 2], 16) / 255 for i in (0, 2, 4))
    return colorsys.rgb_to_hls(r, g, b)[0] * 360


# --------------------------------------------------------------------------- #
# Token extraction
# --------------------------------------------------------------------------- #
TOKEN_RE = re.compile(r"--(color-[a-z]+-\d+)\s*:\s*(#[0-9A-Fa-f]{3,8})\s*;")


def load_tokens(path: pathlib.Path) -> dict:
    text = path.read_text(encoding="utf-8")
    tokens = {name: value.upper() for name, value in TOKEN_RE.findall(text)}
    if not tokens:
        sys.exit(f"ERROR: no --color-*-<shade> tokens found in {path}")
    return tokens


# --------------------------------------------------------------------------- #
# Assertions — mirror design-system.md §6.1
# --------------------------------------------------------------------------- #
WHITE = "#FFFFFF"

# (label, fg_token_or_hex, bg_token_or_hex, minimum_ratio)
PAIRS = [
    # Body / heading text on light surfaces
    ("text-strong on surface-raised", "color-neutral-900", WHITE, NEED_TEXT),
    ("headings on surface-raised", "color-neutral-800", WHITE, NEED_TEXT),
    ("text-default (body) on surface-raised", "color-neutral-700", WHITE, NEED_TEXT),
    ("text-muted on surface-raised", "color-neutral-600", WHITE, NEED_TEXT),
    ("text-placeholder on surface-raised", "color-neutral-500", WHITE, NEED_TEXT),
    ("text-default on surface-page", "color-neutral-700", "color-neutral-50", NEED_TEXT),
    ("text-default on surface-warm", "color-neutral-700", "color-primary-50", NEED_TEXT),
    ("text-strong on secondary-100", "color-neutral-900", "color-secondary-100", NEED_TEXT),
    # Homepage visual refresh (19 Aug 2026): Cara Kerja's full-bleed band is
    # secondary-50 with its own section heading/copy sitting directly on it
    # (not inside a white card) — a genuinely new usage, not covered by the
    # existing secondary-700-on-secondary-50 pair above (that one asserts
    # green text on the tint; this is ordinary body/heading text on it).
    ("text-default on secondary-50", "color-neutral-700", "color-secondary-50", NEED_TEXT),
    ("text-strong on secondary-50", "color-neutral-900", "color-secondary-50", NEED_TEXT),
    ("text-disabled on surface-disabled", "color-neutral-500", "color-neutral-100", NEED_NONTEXT),
    # Solid button fills — white label on 600, and hover 700
    ("white on primary-600", WHITE, "color-primary-600", NEED_TEXT),
    ("white on primary-700 (hover)", WHITE, "color-primary-700", NEED_TEXT),
    ("white on success-600", WHITE, "color-success-600", NEED_TEXT),
    ("white on warning-600", WHITE, "color-warning-600", NEED_TEXT),
    ("white on danger-600", WHITE, "color-danger-600", NEED_TEXT),
    ("white on danger-700 (hover)", WHITE, "color-danger-700", NEED_TEXT),
    ("white on info-600", WHITE, "color-info-600", NEED_TEXT),
    # Soft badge / alert: 700-on-50 and intent 800-on-100
    ("primary-700 on primary-50", "color-primary-700", "color-primary-50", NEED_TEXT),
    ("primary-800 on primary-100", "color-primary-800", "color-primary-100", NEED_TEXT),
    ("success-700 on success-50", "color-success-700", "color-success-50", NEED_TEXT),
    ("success-800 on success-100", "color-success-800", "color-success-100", NEED_TEXT),
    ("warning-700 on warning-50", "color-warning-700", "color-warning-50", NEED_TEXT),
    ("warning-800 on warning-100", "color-warning-800", "color-warning-100", NEED_TEXT),
    ("danger-700 on danger-50", "color-danger-700", "color-danger-50", NEED_TEXT),
    ("danger-800 on danger-100", "color-danger-800", "color-danger-100", NEED_TEXT),
    ("info-700 on info-50", "color-info-700", "color-info-50", NEED_TEXT),
    ("info-800 on info-100", "color-info-800", "color-info-100", NEED_TEXT),
    ("secondary-700 on secondary-50", "color-secondary-700", "color-secondary-50", NEED_TEXT),
    ("secondary-800 on secondary-100", "color-secondary-800", "color-secondary-100", NEED_TEXT),
    # Links and inline error text
    ("text-link on surface-raised", "color-primary-600", WHITE, NEED_TEXT),
    ("text-link-hover on surface-raised", "color-primary-700", WHITE, NEED_TEXT),
    ("text-link on surface-page", "color-primary-700", "color-neutral-50", NEED_TEXT),
    ("error text on surface-raised", "color-danger-600", WHITE, NEED_TEXT),
    # Non-text: interactive control boundaries must hold on all three surfaces
    ("border-interactive on surface-raised", "color-neutral-450", WHITE, NEED_NONTEXT),
    ("border-interactive on surface-page", "color-neutral-450", "color-neutral-50", NEED_NONTEXT),
    ("border-interactive on surface-warm", "color-neutral-450", "color-primary-50", NEED_NONTEXT),
    ("focus ring on surface-raised", "color-primary-600", WHITE, NEED_NONTEXT),
    ("focus ring on surface-page", "color-primary-600", "color-neutral-50", NEED_NONTEXT),
    # Homepage visual refresh (19 Aug 2026): the hero and CS-CTA panel put
    # focusable elements (buttons, links) directly on surface-warm
    # (primary-50) for the first time — previously only text and non-focus
    # borders were asserted there.
    ("focus ring on surface-warm", "color-primary-600", "color-primary-50", NEED_NONTEXT),
    ("focus ring inverse on primary-600", "color-primary-300", "color-primary-600", NEED_NONTEXT),
    ("border-error on surface-raised", "color-danger-600", WHITE, NEED_NONTEXT),
    ("urgent border on urgent bg", "color-warning-600", "color-warning-50", NEED_NONTEXT),
    # Large text
    ("primary heading on surface-raised", "color-primary-600", WHITE, NEED_LARGE),
    ("white on primary-500 (large only)", WHITE, "color-primary-500", NEED_LARGE),
    # Inverse surfaces: footer, inverse header
    ("white on surface-inverse", WHITE, "color-primary-900", NEED_TEXT),
    ("primary-100 on surface-inverse", "color-primary-100", "color-primary-900", NEED_TEXT),
    ("primary-200 on surface-inverse", "color-primary-200", "color-primary-900", NEED_TEXT),
    ("white on neutral-900", WHITE, "color-neutral-900", NEED_TEXT),
]

# Semantic families whose 600 hues must stay perceptually distinct.
# primary/success/info carry different meanings and must never be confusable.
HUE_MIN_SEPARATION = 30.0
HUE_FAMILIES = ["primary", "success", "info", "danger"]

# The Sandstone/warning hue exception retired with Sandstone (ADR-0034):
# `secondary` is now "Leaf" (hue ~132°, re-anchored to the real logo 21 Aug
# 2026). Leaf is caged — never a fill, badge, button, or status chip (see
# tokens.css §1.2 and design-system.md §2.2) — so it needs no hue exception
# even though it sits only ~14° from `success` (146°), a comparable-magnitude
# collision to the one the old Sandstone had with `warning`, not a smaller
# one. The ≥30° rule stands unchanged for the actual status families:
# primary/success/info/danger.
HUE_EXCEPTIONS: set[tuple[str, str]] = set()


def resolve(tokens: dict, ref: str) -> str:
    if ref.startswith("#"):
        return ref.upper()
    if ref not in tokens:
        sys.exit(f"ERROR: token --{ref} not found in tokens.css")
    return tokens[ref]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "--tokens",
        default=str(pathlib.Path(__file__).resolve().parents[2] / "resources/css/tokens.css"),
    )
    ap.add_argument("--quiet", action="store_true", help="only print failures")
    args = ap.parse_args()

    path = pathlib.Path(args.tokens)
    if not path.is_file():
        sys.exit(f"ERROR: tokens file not found: {path}")
    tokens = load_tokens(path)

    failures = []
    print(f"WCAG contrast verification — {path}")
    print(f"{len(tokens)} colour tokens parsed, {len(PAIRS)} pairs asserted\n")

    for label, fg_ref, bg_ref, need in PAIRS:
        fg, bg = resolve(tokens, fg_ref), resolve(tokens, bg_ref)
        ratio = contrast(fg, bg)
        ok = ratio >= need
        if not ok:
            failures.append((label, fg, bg, ratio, need))
        if not args.quiet or not ok:
            print(f"{'PASS' if ok else 'FAIL'}  {ratio:6.2f}  (min {need})  {label}  {fg} on {bg}")

    print("\nHue separation of semantic families (600 shade):")
    hues = {}
    for family in HUE_FAMILIES + ["secondary", "warning"]:
        key = f"color-{family}-600"
        if key in tokens:
            hues[family] = hue(tokens[key])
            print(f"  {family:10s} {hues[family]:6.1f} deg")

    for i, a in enumerate(HUE_FAMILIES):
        for b in HUE_FAMILIES[i + 1 :]:
            if a not in hues or b not in hues:
                continue
            if tuple(sorted((a, b))) in {tuple(sorted(e)) for e in HUE_EXCEPTIONS}:
                continue
            delta = abs(hues[a] - hues[b])
            delta = min(delta, 360 - delta)
            if delta < HUE_MIN_SEPARATION:
                failures.append(
                    (f"hue separation {a}/{b}", tokens[f"color-{a}-600"],
                     tokens[f"color-{b}-600"], delta, HUE_MIN_SEPARATION)
                )
                print(f"FAIL  {delta:6.1f}  (min {HUE_MIN_SEPARATION} deg)  hue separation {a}/{b}")

    print()
    if failures:
        print(f"RESULT: FAIL — {len(failures)} assertion(s) regressed")
        for label, fg, bg, ratio, need in failures:
            print(f"  - {label}: {ratio:.2f} < {need} ({fg} on {bg})")
        return 1
    print(f"RESULT: PASS — all {len(PAIRS)} pairs meet WCAG 2.1 AA")
    return 0


if __name__ == "__main__":
    sys.exit(main())
