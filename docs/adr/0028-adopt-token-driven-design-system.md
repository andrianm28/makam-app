# ADR-0028: Adopt Token-Driven Design System

## Status

Proposed — 25 July 2026. Not yet accepted by a human reviewer.

## Context

The repository holds no application code, so every design value — colour, spacing, type, radius, shadow, z-index, motion, control size — is still unfixed. Public Blade/Livewire views, Filament 5 panels, and printed documents each resolve style differently, and multiple parallel contributors writing the same screens will invent divergent values unless one file owns them. Colour contrast in particular cannot be settled by opinion; the palette carries status meaning on the payment screens, where ambiguity is most expensive.

## Decision

Adopt [`resources/css/tokens.css`](../../resources/css/tokens.css) as the single source of truth for every design value, with [`docs/design/design-system.md`](../design/design-system.md) as the component and state contract, enforced mechanically rather than by review taste.

1. **Two-layer token model.** Tailwind 4 `@theme` primitives generate utilities *and* `:root` custom properties; a semantic `--mk-*` layer expresses intent and covers concerns Tailwind has no namespace for — z-index layers, motion, touch targets, status intents.
2. **`tokens.css` is the only place a raw hex, px, ms, or shadow literal may be introduced.** Tailwind arbitrary design values (`text-[#12545E]`, `p-[13px]`, `z-[9999]`) are forbidden; `var()` references to semantic tokens are the sole exception.
3. **Accessibility is verified, not asserted.** [`docs/design/verify-contrast.py`](../design/verify-contrast.py) asserts 46 colour pairs against WCAG 2.1 AA. Executed on 25 July 2026 it parsed 79 colour tokens and passed 46/46 with exit 0. It is intended as a hard CI gate; weakening an assertion to make a build pass is an accessibility regression.
4. **All ten required UI states** (design-system §6) apply to every transactional screen, per [`AGENTS.md`](../../AGENTS.md) and [`screen-inventory.md`](../product/screen-inventory.md) §D.
5. **Status → visual intent resolves through a single `StatusIntent` helper.** Components must not switch on enum strings.

The gate earned its place during authoring by catching two real AA failures, both fixed in `tokens.css`: `warning-600` `#A66B00` measured 4.44:1 for a white label — below the 4.5 threshold — and was darkened to `#9A6300` (5.05:1); `neutral-300` (1.71:1) and `neutral-400` (2.67:1) both fail WCAG 1.4.11 as input borders, so `neutral-450` `#7F8787` (3.67:1) was added as the mandatory interactive-border token.

## Consequences

### Positive

- one place to change a design value, and every consumer follows;
- accessibility regressions fail the build instead of reaching human review;
- parallel contributors cannot drift, because the gates are mechanical rather than stylistic.

### Negative

- every contributor must learn the token vocabulary before writing a view;
- Filament resolves colours in PHP and cannot read CSS variables, so the palette is currently duplicated in a PHP array and must be **generated** from `tokens.css` rather than hand-edited — tracked as open question OQ-09 (design-system §11).

## NOT TESTED

Nothing has been built. The 46/46 contrast result is the only executed claim in this ADR. No Tailwind build has run, so the wiring in design-system §8.2 is asserted from the documented Tailwind 4 API rather than observed, and §8.3 (Filament 5 theming) is the least reliable section in that document. The brand primary itself is still open (OQ-01): Petrol teal was chosen over green so that `success` green stays unambiguous, and reversing it would require re-verifying all 46 contrast pairs.
