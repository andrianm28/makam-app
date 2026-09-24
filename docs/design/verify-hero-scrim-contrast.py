#!/usr/bin/env python3
"""ADR-0045 (resolves OQ-K5) — verifies --mk-hero-scrim's WORST-CASE contrast
guarantee: white heading/CTA text over the scrim's own darkest stop must
still clear WCAG AA (4.5:1), even if the photo behind it is pure white (the
hardest case for a dark overlay to still work against).

This is deliberately NOT a verify-contrast.py-style token-pair check. The
kamboja design-language plan's own §4.3 correction (13 Sep 2026) is explicit
that a real photo is not a colour token, so a PAIRS-style entry would pass
without proving anything about a real image. This script instead computes
the actual alpha-composited colour a viewer would see, mathematically, for
the theoretical worst case (a pure white #FFFFFF background), and asserts
WCAG contrast against that computed colour — a real, runnable floor, not an
assertion left to a comment.

Usage: python3 docs/design/verify-hero-scrim-contrast.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

NEED_TEXT = 4.5  # WCAG 2.1 AA, normal text (1.4.3)
WHITE = (255, 255, 255)
SCRIM_RGB = (0, 0, 0)  # --mk-hero-scrim's colour stops are pure black


def srgb_to_linear(c: float) -> float:
    c = c / 255
    return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4


def luminance(rgb: tuple[float, float, float]) -> float:
    r, g, b = rgb
    return 0.2126 * srgb_to_linear(r) + 0.7152 * srgb_to_linear(g) + 0.0722 * srgb_to_linear(b)


def contrast(rgb1: tuple[float, float, float], rgb2: tuple[float, float, float]) -> float:
    l1, l2 = luminance(rgb1), luminance(rgb2)
    if l1 < l2:
        l1, l2 = l2, l1
    return (l1 + 0.05) / (l2 + 0.05)


def composite_over(scrim_rgb: tuple[int, int, int], alpha: float, bg_rgb: tuple[int, int, int]) -> tuple[float, float, float]:
    """Standard alpha compositing: result = fg*alpha + bg*(1-alpha), per channel."""
    return tuple(
        scrim_rgb[i] * alpha + bg_rgb[i] * (1 - alpha)
        for i in range(3)
    )


def extract_darkest_scrim_alpha(tokens_css_path: Path) -> float:
    """Reads --mk-hero-scrim from tokens.css and returns its highest (darkest)
    alpha value among the gradient's stops — the worst-case-for-readability
    stop is the one this guarantee must hold at, since that's where text
    is placed."""
    text = tokens_css_path.read_text()
    match = re.search(r"--mk-hero-scrim:\s*linear-gradient\(([^;]*)\);", text)
    if not match:
        print("FAIL: --mk-hero-scrim not found in tokens.css")
        sys.exit(1)

    gradient_body = match.group(1)
    alphas = [float(a) for a in re.findall(r"rgb\(\s*0\s+0\s+0\s*/\s*([\d.]+)\s*\)", gradient_body)]
    if not alphas:
        print("FAIL: no rgb(0 0 0 / <alpha>) stops found inside --mk-hero-scrim")
        sys.exit(1)

    return max(alphas)


def main() -> int:
    tokens_css_path = Path(__file__).resolve().parent.parent.parent / "resources" / "css" / "tokens.css"
    alpha = extract_darkest_scrim_alpha(tokens_css_path)

    worst_case_composited = composite_over(SCRIM_RGB, alpha, WHITE)
    ratio = contrast((255, 255, 255), worst_case_composited)

    passed = ratio >= NEED_TEXT
    status = "PASS" if passed else "FAIL"
    print(
        f"{status}  white text on --mk-hero-scrim's darkest stop (alpha={alpha}), "
        f"worst case (pure white #FFFFFF photo behind it): {ratio:.2f} (min {NEED_TEXT})"
    )

    if not passed:
        print(
            "  --mk-hero-scrim's darkest alpha is not dark enough to guarantee "
            "AA contrast against a worst-case bright photo. Increase the alpha."
        )
        return 1

    print(f"RESULT: PASS — --mk-hero-scrim's worst-case guarantee holds ({ratio:.2f} >= {NEED_TEXT})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
