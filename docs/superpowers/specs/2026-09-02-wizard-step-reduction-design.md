# Wizard Step Reduction — Design Spec

## Context

`docs/superpowers/specs/2026-08-29-wizard-screen-consolidation-design.md`
(merged as PR #218, live on dev/beta since 31 Aug 2026) regrouped the
booking wizard's 9 steps into 4 screens and the renewal wizard's 6 steps
into 3, without changing the underlying step count, `SaveBookingDraftStep`
validation, or the stepper's documented step labels — that spec's own
"Two approaches considered and rejected" section explicitly ruled out a
stepper redesign specifically because `design-system.md` §9.2 MUST NOT 9
and `AGENTS.md` §Mandatory MVP UX ("Booking exposes Steps 1–9 exactly as
documented") forbade it at the time.

The user has now asked for the actual step count to come down too, for
both wizards (this session, 2 Sep 2026: *"bisakah yang dipotong bukan
hanya screennya tapi stepsnya jg untuk keduanya?"* — "can we cut not just
the screens but the steps themselves too, for both?"). This spec exists
because that request directly conflicts with the prior spec's own
constraint, which is itself sourced from `AGENTS.md`'s source-precedence
chain (`RKS K23–K35 → docs/product/mvp-scope.md → approved ADR/specs →
approved benchmark extensions`) — `RKS` sits above everything else in this
repo, and the actual RKS document is not in the repository
(`docs/planning/kiro-specs-analysis.md`, `docs/planning/ekspektasi-vs-
specs.md` both record this as a standing "conformance to RKS content:
BLOCKED" gap). The user was shown this finding directly and confirmed
explicit authority to proceed regardless (this session: "yes proceed").
This spec, and the `AGENTS.md`/`design-system.md` amendments it requires,
exist as the recorded evidence of that decision — not a silent drift.

## Problem statement

Reduce the true number of validated, sequenced steps in both wizards —
not just their screen/page-turn grouping — while keeping every existing
field, its validation, and `SaveBookingDraftStep`'s server-enforced
ordering intact for the steps that remain. Booking goes from 9 steps to
5; renewal from 6 steps to 3. The customer-facing stepper is decoupled
from the internal step count entirely — the decisions below establish
why and how.

**Follow-up in the same session, after the first round of decisions
below was confirmed:** the user asked "apakah bisa dipotong lebih pendek
lagi?" (can it be cut even shorter?). Two further booking merges were
proposed and, after the trade-off for the second was explicitly
re-flagged, both were confirmed ("lebih pendek lagi" — even shorter):
Lokasi+TPU/TPS merge into one step, and — despite the abandonment-risk
trade-off raised twice (a mistake anywhere in a combined step blocks the
whole submission instead of being caught per-step) — Data Pemesan+Data
Almarhum also merge into one step. This is a deliberate, informed
override of that caution, not an oversight; Decisions 7–8 below record
it.

## Decisions (confirmed with the user this session — do not re-litigate)

1. **Booking Step 5 "Ringkasan" is cut**, not merged. It becomes a
   persistent, read-only sidebar rendered alongside the two steps that
   follow it, with no save action and no step number of its own — it was
   already rendered as a summary card within Screen 2 under the prior
   redesign, so this removes a step that was never really a data-entry
   step to begin with.
2. **Booking Steps 3 (Jenis Layanan) + 4 (Pilih Layanan) merge** into one
   step — picking a service type and the specific service is one
   conceptual decision for the customer, validated and saved together.
3. **Renewal Steps 1 (Kota) + 2 (TPU/TPS) + 3 (Cari Makam) collapse into
   one step.** These are already one screen (`RenewalStart`) under the
   prior redesign; this makes the step count match.
4. **Renewal Steps 4 (Biaya) + 5 (Pembayaran) collapse into one step.**
   Already one screen (`RenewalPayment`, the merge of the former
   `RenewalFee` + `RenewalPayment`) under the prior redesign. The explicit
   "Terima Tarif" accept click stays exactly as it is today — an in-step
   action, not a step boundary. `GuardRenewalPaymentOpening`'s four
   conditions and `OpenRenewal`'s trigger are unchanged.
5. **The stepper tracks screens, not the new step count.** Booking's
   stepper shows 4 dots (unchanged from the prior redesign — it was
   already screen-count-based in spirit, this makes it literal); renewal's
   shows 3. Internally, `BookingWizardStep`/`RenewalJourneyStep` keep a
   full step vocabulary for `SaveBookingDraftStep` validation/autosave
   granularity — the stepper is a presentation layer reading a *separate,
   smaller* enum, not the save-step count.
6. **In-flight drafts under the old step numbering are treated as
   unresumable, not migrated.** Any `booking_drafts`/renewal-equivalent
   row with a `current_step` value that doesn't correspond to the new
   numbering is shown a "your session has expired, please start again"
   message on resume attempt — no data migration, no dual-numbering
   compatibility layer.
7. **Booking Steps 1 (Lokasi) + 2 (TPU/TPS) merge** into one step —
   same reasoning as renewal's Kota+TPU/TPS+Cari Makam merge (Decision 3):
   city selection filters the cemetery list in place, one combined
   save/validate unit once a cemetery (and its plot, for granular-tier
   cemeteries) is chosen.
8. **Booking Steps 4 (Data Pemesan) + 5 (Data Almarhum) also merge**
   (renumbered from the original 6-step plan's 4/5 — see the table
   below), despite the trade-off flagged twice during this session
   (combining "your info" and "the deceased's info" into one long form
   raises abandonment risk, since a mistake anywhere blocks the whole
   thing instead of being caught per-step). Confirmed as a deliberate,
   informed choice, not a default.

## Solution

### Booking: `BookingWizardStep` 9 → 5

`app/Domain/Booking/BookingWizardStep.php` (currently `LOCATION=1` through
`CONFIRMATION=9`, `LABELS` array, `count()`/`isKnown()`/`assertKnown()`/
`label()`) is renumbered:

| New constant | New value | Old value(s) | Label |
|---|---|---|---|
| `LOCATION_AND_CEMETERY` | 1 | 1 (`LOCATION`) + 2 (`CEMETERY`) | Pilih Lokasi & TPU/TPS |
| `SERVICES` | 2 | 3 (`SERVICE_TYPE`) + 4 (`SERVICES`) | Pilih Layanan |
| `CUSTOMER_AND_DECEASED_DATA` | 3 | 6 (`CUSTOMER_DATA`) + 7 (`DECEASED_DATA`) | Data Pemesan & Data Almarhum |
| `PAYMENT` | 4 | 8 | Pembayaran |
| `CONFIRMATION` | 5 | 9 | Konfirmasi |

`LOCATION`, `CEMETERY`, `SERVICE_TYPE`, `SUMMARY`, `CUSTOMER_DATA`, and
`DECEASED_DATA` are removed as standalone constants — every field they
governed still exists, just re-attached to one of the 5 remaining step
constants above.

**`app/Livewire/Public/Booking/BookingWizard.php`** (currently 1511
lines):
- `saveStep1(string $cityCode)` (line 354) and `saveStep2(string
  $cemeteryId, ?int $cemeteryPackageId = null)` (line 394) merge into one
  `saveStep1(string $cityCode, string $cemeteryId, ?int
  $cemeteryPackageId = null)` against `LOCATION_AND_CEMETERY`. The UI
  keeps its existing two-part interaction (pick city → cemetery list
  filters in place, including the granular-tier plot picker) — only the
  save/validate call at the end, once a cemetery is chosen, changes from
  two calls to one. Confirm the exact current call shape (whether the
  live component already defers the city save until a cemetery is picked,
  or writes an intermediate draft state after step 1 alone) during
  implementation before finalizing this merge's payload shape.
- `saveStep3(string $serviceType)` (line 560) and `saveStep4(array
  $selectedServices)` (line 568) merge into one `saveStep2(string
  $serviceType, array $selectedServices)` against the new `SERVICES`
  constant. `continueFromStep4()` (line 585) is renamed
  `continueFromServices()` and calls the new merged method.
- `saveStep6()` (line 590) and `saveStep7()` (line 603) merge into one
  `saveStep3()` against `CUSTOMER_AND_DECEASED_DATA`, combining both
  payloads (customer fields + deceased fields) into a single
  `saveStepOrShowErrors()` call. Both sub-forms remain visually distinct
  sections on the page (this is a validation/save-unit merge, not a
  request to visually blend "your info" and "the deceased's info" into
  one undifferentiated form) — only the submit boundary and error
  surfacing become shared, per Decision 8's accepted trade-off.
- `saveStep8()` (line 636) becomes `saveStep4()`; its manual/online
  payment branching logic is unchanged.
- `screenFor()` (line ~1085) is updated to compare against the new
  constant values. The 4-screen grouping itself is UNCHANGED: Screen 1
  "Cari & Pilih" = steps 1–2 (Lokasi & TPU/TPS, then Layanan), Screen 2
  "Detail Pemesanan" = step 3 alone (Data Pemesan & Almarhum, now the
  screen's only step — with the Ringkasan sidebar always visible
  alongside it, not gated behind a step save), Screen 3 "Pembayaran" =
  step 4, Screen 4 "Konfirmasi" = step 5.
- The Ringkasan sidebar: currently rendered as part of Screen 2's content
  flow keyed off `$currentStep <= SUMMARY` reveal logic (`wizard.blade.php`
  comments at lines ~1306, ~1341 reference this). It becomes an
  unconditional sidebar partial rendered for the whole of Screen 2,
  reading the draft's already-saved totals — no new data source, just
  always-visible instead of reveal-gated.

**`app/Domain/Booking/Actions/SaveBookingDraftStep.php`** (644 lines):
`validateStepSequencing()` (line 239) and the `current_step` advance logic
(line 171, currently `min($step + 1, BookingWizardStep::LAST_IMPLEMENTED +
1)`) work against the renumbered constants with no structural change — the
sequencing rule itself ("you cannot save step N without step N−1 already
saved") is unchanged, only the numbers it compares.

### Renewal: `RenewalJourneyStep` 6 → 3

`app/Domain/Renewal/RenewalJourneyStep.php` renumbered:

| New constant | New value | Old value(s) | Label |
|---|---|---|---|
| `SEARCH` | 1 | 1 (`CITY`) + 2 (`CEMETERY`) + 3 (`GRAVE_SEARCH`) | Cari Makam |
| `FEE_AND_PAYMENT` | 2 | 4 (`FEE`) + 5 (`PAYMENT`) | Biaya & Pembayaran |
| `CONFIRMATION` | 3 | 6 | Konfirmasi |

**`app/Livewire/Public/Renewal/RenewalStart.php`** (423 lines, already the
merge target for the former `GraveSearch` under the prior redesign): its
internal city/cemetery/search save logic already runs as one Livewire
component lifecycle — no code path currently writes three separate
`current_step` values for these three concerns (confirm exact save-call
shape during implementation; if it currently does write three, collapse
to one `saveSearchStep()` call against the new `SEARCH` constant,
mirroring booking's `saveStep3()` merge above).

**`app/Livewire/Public/Renewal/RenewalPayment.php`** (363 lines, already
the merge of the former `RenewalFee` + `RenewalPayment`): same shape — its
fee-then-payment flow already runs as one component; collapse to one save
call against `FEE_AND_PAYMENT` if it currently writes two `current_step`
values, following the exact pattern its own class doc block (lines ~38–51)
already documents for the screen merge. `GuardRenewalPaymentOpening`'s
four conditions (unchanged, out of scope per Global Constraints below) and
the explicit "Terima Tarif" click stay exactly as implemented today —
this only affects `current_step` bookkeeping, not the payment-opening
guard.

**`app/Livewire/Public/Renewal/RenewalConfirmation.php`** (50 lines):
unchanged except reading `RenewalJourneyStep::CONFIRMATION` as its new
value (3, not 6).

Renewal's screens and steps now converge to the same 3 — the
screen/step distinction the prior redesign introduced effectively
disappears for renewal (each screen IS a step).

### Stepper: decoupled from the step count entirely

`resources/views/components/mk/stepper.blade.php` (§3.9) needs **no
component-contract change** — its own doc block already establishes that
step count/labels are entirely derived from whatever `labels` array is
passed in, re-keyed to a contiguous 1..N sequence, with the 9 booking
labels only as the prop's *default value*. This spec adds two new,
small label-vocabulary classes (not database-backed, same `final class` +
`const` shape as `BookingWizardStep`/`RenewalJourneyStep`):

- `App\Domain\Booking\BookingWizardScreen`: 4 entries — "Cari & Pilih",
  "Detail Pemesanan", "Pembayaran", "Konfirmasi" (the exact 4 screen names
  the prior redesign already established).
- `App\Domain\Renewal\RenewalWizardScreen`: 3 entries — "Cari Makam",
  "Biaya & Bayar", "Konfirmasi" (same).

`BookingWizard`'s Blade view passes `:labels="BookingWizardScreen::labels()"`
instead of omitting `labels` (which would now incorrectly default to the
old 9-item array — `stepper.blade.php`'s doc block is explicit that
omitting `labels` renders the booking default, so booking's own screen
MUST now pass its own array where it previously relied on the default).
Renewal's screens already pass their own `labels` array under the prior
redesign (`RenewalJourneyStep::LABELS`, 6 items) — this changes to
`RenewalWizardScreen::labels()` (3 items) instead.

The stepper's `$n` (dot number) advances based on `screenFor($currentStep)`
(booking) or the renewal component's own screen-mapping — both already
exist from the prior redesign and only need their comparison values
updated for the renumbered steps.

## `design-system.md` / `AGENTS.md` amendments (required, not optional)

- `AGENTS.md` §Mandatory MVP UX: `"Booking exposes Steps 1–9 exactly as
  documented"` → `"Booking exposes Steps 1–5 exactly as documented."` A
  new line is added directly beneath it recording this as a deliberate,
  owner-approved departure from the step count implied by RKS K23–K35 (see
  Context above), dated 2 Sep 2026, not a silent drift — matching how this
  repository already records other RKS-adjacent decisions (e.g. the
  Filament-panel brand-system reversal in `design-system.md` §8.3).
- `design-system.md` §3.9 ("The nine-step default is normative" /
  "Booking Steps 1–9" heading) is rewritten for the new 5-step booking
  vocabulary and the stepper's new screen-tracking role; the renewal
  paragraph ("six visible steps") is rewritten for 3. §9.2 MUST NOT 9 is
  rewritten from "hide/reorder/rename a booking step" to the equivalent
  rule for the new 5/3 vocabularies, plus an explicit carve-out
  permitting `labels` to reflect a wizard's SCREEN vocabulary (not just
  the sanctioned renewal exception it names today).
- `docs/product/booking-wizard-fields.md` (`BookingWizardStep`'s own doc
  block cites this as the step-heading source) needs its heading list
  updated to match the new 5 headings.
- `.kiro/specs/renewal-and-grave-registry` AC1 (`RenewalJourneyStep`'s own
  doc block cites this as its step-count source) — this is a `.kiro` spec,
  outside this repo's own `docs/superpowers/` convention; flag for the
  plan to confirm whether it is editable in place or needs a superseding
  note instead, per whatever convention this repo already uses for
  amending a `.kiro` spec (check `docs/planning/kiro-specs-analysis.md`
  for precedent before the plan is written).

## Global Constraints (binding on every task in the eventual plan)

- No change to `SaveBookingDraftStep`'s actual validation RULES (only
  which step number each rule attaches to), `GuardRenewalPaymentOpening`'s
  four conditions, `OpenRenewal`'s trigger, `PaymentMode`/`ModeResolver`
  gating, or any other domain Action's business logic — this spec is a
  step-count/numbering change and a stepper-decoupling change, not a
  validation or payment-logic change.
- Every existing field collected, in the order it's collected within its
  (possibly now-merged) step, stays identical — nothing is dropped from
  either wizard.
- Real Postgres 18 (never SQLite) for every task's tests, via the pinned
  CI image, per this repository's own established practice for this area
  of the code.
- Every existing booking/renewal feature test must keep passing UNCHANGED
  in behavior; tests asserting old step NUMBERS (e.g. `current_step ===
  8`) need updating to the new numbers — this is expected, not a
  regression, and the plan should call out exactly which existing test
  files assert numeric step values so they aren't missed.
- `declare(strict_types=1);` on every new/modified PHP file.
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse
  --memory-limit=1G` clean throughout.

## Out of scope

- Any further screen restructuring beyond what PR #218 already
  established — this spec only changes step *counts and boundaries*
  within the existing 4/3 screen structure, not the screens themselves.
- Fixing any of the unrelated bugs/findings from this session's parallel
  visual/UX UAT sweep (e.g. the hardcoded cemetery-badge-color bug, the
  wrong booking-wizard container-width class, the marketplace index price
  rendering bug) — tracked separately, not part of this spec.
- The customer-facing specific-plot picker's actual existence in the
  public wizard (flagged by the visual UAT sweep as apparently not wired
  in yet) — separate, pre-existing gap, not caused or fixed by this spec.
- Data migration/backward-compatibility for in-flight drafts under the old
  numbering (Decision 6 above — explicitly not supported).

## Testing / Verification

- Every existing booking test file asserting a numeric `current_step`
  value, a `saveStep3`/`saveStep4`/`saveStep6`/`saveStep7`/`saveStep8`
  method name, or `BookingWizardStep::SERVICE_TYPE`/`SUMMARY` needs its
  own update — the implementation plan must enumerate these files (grep
  `BookingWizardStep::` and `saveStep` across `tests/`) rather than
  discover them ad hoc during implementation.
- New tests: `BookingWizardStep::count() === 5`, `RenewalJourneyStep::
  count() === 3`, the merged `saveStep2()` validates both service-type and
  service-selection payload keys together, and the merged `saveStep3()`
  validates both customer-data and deceased-data payload keys together, `validateStepSequencing()`
  still refuses e.g. step 4 before step 3, an in-flight draft at old
  `current_step = 8` is treated as unresumable (not silently mapped to
  new step 4).
- Stepper: a new test asserting `BookingWizard`'s Blade view now passes an
  explicit 4-item `labels` array (regression test for the "omitting
  `labels` falls back to the OLD 9-item default" trap this spec
  introduces) and that renewal's screens pass the new 3-item array.
- Full booking + renewal feature suites green against real Postgres 18/
  Redis, plus the whole-repo suite, matching the verification bar the
  prior wizard-screen-consolidation branch already established.
