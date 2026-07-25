# Tasks — Public Booking Wizard

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Seed five launch regions. _Requirements: 2_
- [ ] Build nine-step shell and progress. _Requirements: 1, 14_
- [ ] Implement cemetery selection with Google Maps URL. _Requirements: 3_
- [ ] Implement service-type conditional rules. _Requirements: 4_
- [ ] Implement canonical service catalog selection. _Requirements: 5_
- [ ] Implement quote summary/version check. _Requirements: 6_
- [ ] Implement customer and deceased forms. _Requirements: 7, 8_
- [ ] Implement secure document upload. _Requirements: 8_
- [ ] Implement online/manual payment modes. _Requirements: 9_
- [ ] Implement confirmation and notification status. _Requirements: 10, 15_
- [ ] Add autosave/resume and conflict handling. _Requirements: 11, 12, 13_
- [ ] Add browser tests for all branches and failures. _Requirements: 14_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

This is the highest-risk surface in the product: nine steps, a money path, and private documents. The design contracts below are not stylistic preferences.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Nine-step progress | `<x-mk.stepper>` §3.9 | `--mk-stepper-dot` (28 px dot, **44 px hit area**), `--mk-stepper-track`, `--mk-progress-track`, `--mk-progress-fill` |
| Wizard container | layout §4.2 | `--container-form` (768 px) — capped **even on wide desktop**; a 1280 px form raises error rates on the deceased-data step |
| Form fields | `<x-mk.field>` §3.2 | `--mk-border-interactive`, `--mk-control-h-md`, `--text-base` (**exactly 16 px** — iOS auto-zooms below this and breaks the layout mid-entry), `--mk-field-gap` |
| Field error | §3.2 error state | `--mk-border-error`, `--color-danger-700`, `aria-invalid`, `aria-describedby` |
| Cemetery cards (Step 2) | `<x-mk.card>` §3.3 cemetery variant | `--radius-lg`, `--shadow-sm`; availability badge per §3.7 |
| Service rows (Step 4) | `<x-mk.card>` §3.3 service variant | unavailable item = `--mk-intent-neutral-*` on `--color-neutral-50`, **not** `danger` (it is not an error) |
| Quote summary (Step 5) | `<x-mk.card>` + `<x-mk.table>` §3.5 | currency `text-right tabular-nums`, `--font-mono`; total `--font-weight-bold` |
| Sticky `Lanjutkan` | `<x-mk.button variant=primary size=lg full>` §3.1 | `--mk-control-h-lg`, `--mk-z-sticky-cta` (**below** `--mk-z-bottomnav`), `--mk-safe-bottom` |
| `Kembali` | `<x-mk.button variant=tertiary>` §3.1 | must never lose data (AC12) |
| Step transition | motion §5 | `--mk-duration-slow`, `--ease-emphasized`, **opacity crossfade only — no horizontal slide** |
| Confirmation (Step 9) | `<x-mk.card>` + badges | order reference `--font-mono`, copyable |

### Required UI states — per step

All ten states apply — design-system.md **§6**. Screen IDs from [`screen-inventory.md`](../../../docs/product/screen-inventory.md).

| Screen | Required state design |
|---|---|
| PUB-010 Step 1 | loading §6.1 · empty "no city" §6.2 · populated |
| PUB-011 Step 2 | loading skeleton cards · **empty/no-result** §6.2 with `Reset filter` · indicative price = `neutral` badge + `"Perlu konfirmasi"`, **never `success`** |
| PUB-012 Step 3 | conditional (Makam Tumpang only when supported) · **gated** §6.4 explanatory state, never a dead option · Urgent uses `--mk-intent-urgent-*` **with an explicit availability label** |
| PUB-013 Step 4 | unavailable item stays visible with reason + alternative (`neutral`, not `danger`) |
| PUB-014 Step 5 | valid quote · **changed price** → explicit reconfirmation, new version · **expired quote** → `--mk-intent-neutral-*` (`KEDALUWARSA` is factual, not alarming) |
| PUB-015 Step 6 | validation §6.3 inline + summary alert; focus moves to summary, not first field; **never clear entered data** |
| PUB-016 Step 7 | upload `idle → uploading` (determinate, cancellable) `→ scanning` (`pending`) `→ accepted` (`success`) `→ rejected` (`danger` + reason + retry) — §6.7. **A quarantined file is never previewable, downloadable, or thumbnailed.** Surface the 5-minute signed-URL validity |
| PUB-017 Step 8 | online · **manual fallback** (`intent=info` banner, §6.9) · `pending` · failed §6.5. **Never a dead end on the payment step.** Never mark paid from browser return |
| PUB-018 Step 9 | success §6.8 **quiet** — no confetti, no animated check. Three distinct delivery visuals: `success` "Terkirim" / `pending` "Sedang dikirim" / `neutral` "WhatsApp belum tersedia" |
| all steps | duplicate/retry-safe §6.6 — double-tap blocked by `loading` state, not a client flag; refreshing Step 9 re-renders the same reference |
| all steps | support §6.10 — contextual support link in every step footer |
| all steps | responsive §4.3 — 320 / 360 / 768 / 1024 / 1280 px |

### Autosave affordance (AC11)

Inline and quiet near the stepper — **never a toast** (design-system.md §3.9):

- saving → `--mk-text-muted` + spinner, "Menyimpan…"
- saved → `--color-success-700` + check, "Tersimpan 14:32"
- failed → `--color-danger-700` + icon, retry button

Wrap in `aria-live="polite"`. Never block the form on autosave.

### Stepper is a presentation contract

Urgent and Pre-Need may branch internally (AC14), but the stepper still reads **1–9**. Do not renumber per branch. See `booking-wizard-fields.md` §Branching.

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Build the stepper per §3.9: compact mobile (`Langkah N dari 9` + progress bar), full rail at `--breakpoint-md`.
- [ ] Implement every state in the per-step table above.
- [ ] Implement the autosave affordance as an inline `aria-live` region, not a toast.
- [ ] Resolve all status badges through the single `StatusIntent` helper (§3.7) — no `match` on enums in Blade.
- [ ] Read `PaymentMode` / `PreNeedMode` from the **server** for Step 8 (§6.9); a front-end flag is insufficient.
- [ ] Verify accessibility (§7): 16 px inputs, 44 px targets, focus order, keyboard-only completion of all nine steps.
