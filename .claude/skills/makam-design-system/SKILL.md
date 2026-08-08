---
name: makam-design-system
description: The enforceable design rules for any UI in this repository — token-only design values, the mk primitives, the ten mandatory screen states, StatusIntent as the single status resolver, and server-read gate banners. Use whenever building or changing a Blade view, a Livewire page, a Filament resource, or anything that renders to a user; and before claiming a screen is finished.
---

# Design system

`docs/design/design-system.md` is the single source of truth and `resources/css/tokens.css` holds every design value. This skill is a pointer and a working summary — **the document governs**, and §9.2 is written to be enforceable, so read it rather than trusting a paraphrase.

Four of the six governance gates block merges from `ci/verify-docs.sh` (GATE 1–3, 11–12); the sixth runs in CI's `php` job.

## The rules that get broken most

From §9.2 — 9 MUST and 12 MUST NOT. The ones that actually bite:

- **Reference a token for every design value.** No hex, `rgb()`, `hsl()`, px, duration, or shadow outside `tokens.css`. No Tailwind arbitrary values (`text-[#12545E]`, `p-[13px]`, `z-[9999]`, `duration-[250ms]`). The one permitted exception is a `var()` reference to a semantic token with no utility, e.g. `bg-[var(--mk-surface-overlay)]`.
- **Use the `<x-mk.*>` primitives. Extend them, never fork them.** Hand-copying a primitive's class recipe is forking.
- **Never write a raw `z-index`** — use the named layer utilities.
- **Never `outline: none`** without a replacement focus ring, and never `opacity-50` for disabled (it silently breaks contrast).
- **Never convey status by colour alone.**
- **Never rename, reorder, or hide a product label, route, menu item, or booking step.**
- **Never style a pending state as success**, and never claim a notification delivery without delivery state.
- **Never preview, thumbnail, or link a quarantined document.**
- **No `dark:` utilities** until OQ-07 is resolved.

## The ten states are mandatory

§6. A screen missing one is incomplete, not "shipped": loading · empty · validation error · authorization failure · provider unavailable · duplicate/retry-safe · pending · success · support escape hatch · responsive-at-320px-first.

Two that are consistently underestimated:

**Empty (§6.2) has three parts — what is empty · why · what to do next.** Never a bare "Tidak ada data". And *privacy-limited* is a distinct state from *not found*: when a gate restricts the field projection, say so, rather than implying the record does not exist. On this product that distinction is the difference between "we cannot show you this" and telling a family their relative's grave does not exist.

**Pending (§6.7) is the most common state in this product and the easiest to get wrong.** State what is being waited on, who acts next, and the window if known. Never style it as success.

## `StatusIntent` is the only status resolver

`app/Support/Design/StatusIntent.php`, mandated by §3.7. **Never `match` on a status enum inside a Blade view or a Filament closure.** It already carries the order-lifecycle and vendor-processing tables.

Filament needs a bridge, not the intent name: `StatusIntent::filamentColor()` maps intent → Filament's own colour key, and they are not the same vocabulary — `pending` → `warning`, `neutral` → `gray`. Passing a raw intent to Filament's `->color()` is a bug.

If a status vocabulary is not in one of §3.7's tables, do not invent a mapping — that is a design decision. `FaqArticleStatusBadge` is the precedent for a scoped local mapper with a documented reason.

## Icons

`<x-dynamic-component :component="'icon.' . $icon">` resolves to `resources/views/components/icon/<name>.blade.php`. A missing file throws `InvalidArgumentException` at render — this is finding **N-15** and it has already broken CI once.

Before rendering any icon, confirm the component file exists. Icons are real Heroicons v2 outline glyphs, 24×24, `stroke-width="1.5"` (design-system OQ-05's documented assumed default — OQ-05 itself is still open). Never draw or improvise path data.

## Gate banners read the server

§6.9. A front-end flag is never sufficient. Read the mode through `ModeResolver` — `PaymentMode`, `WhatsAppMode`, `PreNeedMode`, `GraveSearchMode`, `UrgentMode` — and render `<x-mk.gate-closed-banner>` or `<x-mk.gate-closed-page>`.

Placement is below the header, above `<main>`. **Dismissible only for informational modes** — never for one that changes how a user must pay. A closed gate produces an explanatory page, never a generic 404 (IA §4).

And the rule that outranks all of it: a closed gate **never removes a required MVP step**. Step 8 stays; only what it offers changes.

## Available primitives

`alert · badge · button · card · field · gate-closed-banner · gate-closed-page · header · logo · modal · spinner · stepper · table`

Read the target file's `@props([...])` before using one — several take an `intent` that must be a value that primitive actually supports. To change or extend one, load `makam-blade-primitive`; they are single-writer shared files.

`<x-mk.button>` is safe inside Livewire views — verified 8 Aug 2026 against real Laravel 13.22.0 + Livewire 4.3.3, all variants including `loading`. The older hand-written markup in existing views is a leftover of finding N-14, not a rule.

## Definition of done

§9.3 has 12 checkboxes. Do not claim a UI change is finished without walking them — including the last two, which are the ones that quietly get skipped: the screen is added or updated in `screen-inventory.md` **with its states**, and traceability is marked `Covered` only when a test exists and passes.

Realistically, on this host, responsive/keyboard/zoom verification will come back `NOT TESTED` — there is no browser. Say so; do not tick it. See `makam-verify`.

## Changing a token

§9.4: open an ADR, edit `tokens.css` only, re-run `verify-contrast.py`, and if a pair regresses **fix the token, not the assertion**. Then regenerate the Filament palette (`php artisan design:generate-filament-palette`) — CI gate 6 fails if you forget.
