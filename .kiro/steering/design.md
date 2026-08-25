---
inclusion: fileMatch
fileMatchPattern: ["resources/**", "app/Filament/**", "app/Livewire/**", "docs/design/**"]
---

# Makam.co.id — Design System Steering

Part of the v0.6 steering set. Loads only when working inside UI-relevant paths (Blade/CSS/Filament/Livewire, or the design docs themselves) — see `governance.md` for why this file, specifically, benefits from conditional loading.

1. `../../docs/design/design-system.md`
2. `../../resources/css/tokens.css`

`design-system.md` is the single source of truth for component contracts and the ten required UI states; `tokens.css` is the single source of truth for every design value. Never hardcode a hex, px, ms, or shadow, and never use a Tailwind arbitrary value for a design decision.

## Verified state (25 Jul 2026, Sprint 2 Batches 2.1–2.5)

- Every utility design-system.md §8.2 documents is CI-verified to actually compile (`resources/views/design-system-smoke-test.blade.php` + a dedicated CI step) — no longer "asserted but unbuilt."
- All 9 `<x-mk.*>` primitives exist under `resources/views/components/mk/`. `button.blade.php` is the convention reference — read it first before adding a new one: single `@php` composition block, static PHP arrays for intent-keyed classes (never interpolate `$intent`/`$variant` into a Tailwind arbitrary-value string — `card.blade.php`'s own header comment documents a real bug from doing exactly that), `neutral-0` instead of Tailwind's built-in `white`.
- `StatusIntent` (`app/Support/Design/StatusIntent.php`) is the single, mandatory status→intent resolver §3.7 requires. Never `match` on a status enum inside a view or a Filament column closure — call `StatusIntent::intent()`/`::icon()`/`::label()`/`::filamentColor()` instead.
- Filament theming (`app/Providers/Filament/AdminPanelProvider.php`, `resources/css/filament/admin/theme.css`) boots successfully against the real, pinned `filament/filament` v5.7.3 in CI — §8.3's "least-verified section" flag is resolved for the boot itself. Two specific, documented deviations remain unverified: `LocalFontProvider` (no confirmed FQCN — self-hosted `@font-face`/`@fontsource-variable/inter` used instead) and the `discoverResources()`/`discoverPages()`/`discoverWidgets()` calls (omitted — the directories they'd scan don't exist yet under the current empty `app/Filament/Admin/` scaffold).
- All six §9.5 CI governance gates are live and blocking (`ci/verify-docs.sh` GATE 1–3 and GATE 11–12, plus `design:verify-filament-palette` in `.github/workflows/ci.yml`'s `php` job).
