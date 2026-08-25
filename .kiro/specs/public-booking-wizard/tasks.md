# Tasks — Public Booking Wizard

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [x] Seed five launch regions. _Requirements: 2_ — shipped (S4-T4/T4-batch, then re-seeded by the procedural example-data generator, lane `admin-master-data`): `CemeteryPublicQuery::launchCities()` derives all five `LaunchCityCode::KNOWN_CODES` unconditionally, backed by seeded city/cemetery rows (`CemeteryExampleData`; `RenewalStartTest::test_all_five_launch_cities_are_offered_in_the_canonical_order` proves the same set the wizard offers).
- [x] Build nine-step shell and progress. _Requirements: 1, 14_ — shipped 13 Aug 2026 (L6): `App\Livewire\Public\Booking\BookingWizard` renders `<x-mk.stepper>` with the canonical nine labels; steps 1–9 all have screens (`BookingWizardStep::LAST_IMPLEMENTED = CONFIRMATION`; `BookingWizardRouteTest::test_the_nine_step_stepper_is_always_shown`).
- [ ] Implement cemetery selection with Google Maps URL. _Requirements: 3_ — **partial**: Step 2's cemetery selection is shipped (published directory, package/class choice, per-city empty/loading states — `BookingWizardStepTwoPackagesTest`), and the model-side navigation derivation exists (`Cemetery::googleMapsUrl()`: operator URL wins, else coordinate search URL, never blocking the textual address). But the wizard's Step 2 card renders **name, type, and package/class only** — no photo, address, Google Maps navigation link, facilities, price, or availability (`wizard.blade.php` Step 2 block) — so AC3's full field set is not yet shown in the wizard. Corrected 13 Aug 2026: previously unchecked with no note at all.
- [x] Implement service-type conditional rules. _Requirements: 4_ — shipped: Step 3 conditional rendering per `BookingServiceType` (`BookingServiceTypeTest`), Urgent branch with explicit availability label.
- [x] Implement canonical service catalog selection. _Requirements: 5_ — shipped: Step 4 reads real `ServiceDefinition` rows (catalogue names, not raw enum codes — `BookingWizardStepsFourAndFiveTest::test_step_4_offers_every_basic_and_additional_service`, `::test_step_4_labels_each_service_with_its_real_catalogue_name`).
- [x] Implement quote summary/version check. _Requirements: 6_ — shipped: Step 5 renders a computed summary with real totals, honest about missing prices (`BookingWizardStepsFourAndFiveTest::test_step_5_shows_the_real_computed_total_from_test_owned_prices`, `::test_summary_marks_a_missing_price_honestly_instead_of_fabricating_a_total`); the durable quote/version *acceptance* half lives in `booking-and-order-orchestration` (`IssueQuote`/`AcceptQuote`), per the Boundary table.
- [x] Implement customer and deceased forms. _Requirements: 7, 8_ — shipped 13 Aug 2026 (L6): Steps 6–7 forms with server-side field validation (`BookingWizardStepsSixToNineEndToEndTest`).
- [ ] Implement secure document upload. _Requirements: 8_ — **NOT built, deliberately**: Step 7's document fields exist in the schema but `SaveBookingDraftStep` refuses any caller-supplied document path outright ("Unggahan dokumen belum tersedia pada langkah ini" — no legitimate caller exists; upload belongs to `platform-document-vault`'s quarantine seam, and the wizard's Step 7 renders an informative "no documents needed yet" notice instead). Do not read the step-7 screen as the upload.
- [x] Implement online/manual payment modes. _Requirements: 9_ — shipped: Step 8 renders per server-resolved `PaymentMode` — manual coordination while `G-PAY-01` is closed (never removes the step; `BookingWizardStepsSixToNineEndToEndTest::test_completing_step_8_advances_to_step_9_confirmation`, payment-mode assertions in `BookingWizardEndToEndTest`).
- [ ] Implement confirmation and notification status. _Requirements: 10, 15_ — **partial**: Step 9 is shipped and renders the truthful pending state ("Menunggu diproses" — explicitly never styled success while no order/payment exists), per-channel delivery state from `WhatsAppMode` (WhatsApp only promised when the mode is `WhatsApp`; never claims "Terkirim" without a delivery record), the order summary, and the contact channel (`wizard.blade.php` Step 9 block; `BookingWizardStepsSixToNineEndToEndTest`). **Not yet rendered:** order reference, status, invoice state, next step, and support reference — no order record exists in this lane (`OrderReadModel` is built in `booking-and-order-orchestration` but not wired into Step 9), so AC10's full field set stays open. Corrected 13 Aug 2026: previously unchecked with no note.
- [x] Add autosave/resume and conflict handling. _Requirements: 11, 12, 13_ — shipped: inline `aria-live` autosave affordance with `saved`/`failed` states (`BookingWizard::$autosaveState`), session-bound draft resume via `/pemesanan-makam/draft/{draftId}` (`BookingWizardDraftBindingTest`), optimistic-concurrency version conflict detection (`BookingWizardSaveIntegrityTest::test_a_draft_changed_in_another_tab_is_reported_and_reloaded_instead_of_overwritten`).
- [ ] Add browser tests for all branches and failures. _Requirements: 14_ — **NOT TESTED as browser tests**: Livewire-level coverage exists for every step branch and the ten states (`BookingWizardEndToEndTest`, `BookingWizardAccessibilityTest`, `BookingWizardSaveIntegrityTest`, `BookingWizardStepsSixToNineEndToEndTest`), but no browser (Dusk/Playwright) harness exists in this repository, so the browser-level item stays open.

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

- [x] Reference tokens for all colour/spacing/type; zero hardcoded values. — done 13 Aug 2026 (L6), machine-enforced: `ci/verify-docs.sh` GATE 2/GATE 3 scan `resources/` and `app/` for hardcoded hex/px/ms/shadow and Tailwind arbitrary values, and passed with all nine wizard screens in the tree.
- [x] Build the stepper per §3.9: compact mobile (`Langkah N dari 9` + progress bar), full rail at `--breakpoint-md`. — done: `<x-mk.stepper>` renders the canonical nine labels on every wizard screen (`BookingWizardRouteTest::test_the_nine_step_stepper_is_always_shown`; the label-source assertion was corrected from `BookingWizardStep::LABELS` to the primitive's own canonical labels, per that test's doc block).
- [ ] Implement every state in the per-step table above. — **partial**: loading/empty/validation/duplicate/support and the payment-step §6.9 banner are implemented and CI-green across the nine steps (`BookingWizardEndToEndTest`, `BookingWizardStepsSixToNineEndToEndTest`); **§6.7 document upload states are not implemented** because Step 7 has no upload (see the document-upload task above), and the online-payment pending/failed paths are unreachable while `G-PAY-01` is closed (manual mode only).
- [x] Implement the autosave affordance as an inline `aria-live` region, not a toast. — done: `BookingWizard::$autosaveState` (`idle`/`saving`/`saved`/`failed`) rendered inline near the stepper with `aria-live` (`BookingWizardStepsFourAndFiveTest::test_the_autosave_indicator_shows_saved_after_a_successful_step_save`, `::test_the_autosave_indicator_shows_failed_after_a_rejected_step`).
- [x] Resolve all status badges through the single `StatusIntent` helper (§3.7) — no `match` on enums in Blade. — done: `App\Support\Design\StatusIntent`'s order-lifecycle family is the only resolver (`tests/Unit/Support/Design/StatusIntentTest.php`).
- [x] Read `PaymentMode` / `PreNeedMode` from the **server** for Step 8 (§6.9); a front-end flag is insufficient. — done: Step 8 and the confirmation surface read `ModeResolver` (`paymentMode()`, `whatsAppMode()`, `preNeedMode()`) server-side; `SaveBookingDraftStep`'s payment validation reads the same authority.
- [ ] Verify accessibility (§7): 16 px inputs, 44 px targets, focus order, keyboard-only completion of all nine steps. — **NOT TESTED**: `BookingWizardAccessibilityTest` covers the colour-only and structural affordances at the Livewire level, but no browser/headless harness exists, so no rendered geometry, focus order, or keyboard completion was measured.
