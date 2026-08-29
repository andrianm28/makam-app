# Wizard Screen Consolidation — Design Spec

## Context

Both public wizards ship real, live business logic today: the 9-step
booking wizard (`/pemesanan-makam`, `App\Livewire\Public\Booking\
BookingWizard`) and the 6-step renewal wizard (`/perpanjangan`, five
separate Livewire components/routes under `App\Livewire\Public\Renewal\`).

The TPU/TPS operator dashboard roadmap deployed to `dev`/`beta` on 29 Aug
2026 (PRs #209-216) was almost entirely admin/operator-facing; the one
customer-visible change (the granular-tier plot picker on booking's step 2)
requires a cemetery to already be in granular tier, which none were at
deploy time. The user UAT'd `/pemesanan-makam` and `/perpanjangan` on
`dev.makam.co.id`, correctly observed nothing had visibly changed, and
asked for both wizards to be shortened "like the hotel booking system."

Clarified via a direct question (this session, 29 Aug 2026): "shorter"
means fewer **screens** / page-turns, not fewer logically-distinct steps.
`docs/design/design-system.md` §9.2 MUST-NOT-9 forbids hiding, reordering,
or renaming any of the 9 documented booking steps or the 6 documented
renewal steps — `<x-mk.stepper>`'s own doc header states booking's step
labels are its DEFAULT prop value specifically so no booking screen can
override them, and every renewal screen already passes the one sanctioned
`labels` override (the six `RenewalJourneyStep::LABELS`). This spec keeps
both step counts and both stepper contracts exactly as they are; only
screen/page-turn count changes.

A dedicated research pass (this session) produced a full field-by-field
inventory of both wizards' current implementation, cited throughout below.

## Problem statement

Reduce the number of full page-turns a customer experiences completing
each wizard, without changing what data is collected, in what order, under
what validation, or what the stepper displays — and, for renewal
specifically, without leaving in place a pre-existing gap the research
surfaced: there is currently no live UI path from step 3's search results
to step 4's fee screen at all (`GraveRecordProjection` deliberately carries
no `id`, so `RenewalFee`'s `?makam=` query param is only reachable via a
hand-built URL today).

## Solution

### Booking: 9 steps → 4 screens, progressive reveal in the existing component

Booking is already one Livewire component (`BookingWizard`) with one Blade
file, one `$currentStep` int, one `BookingDraft` row saved incrementally
per step. This is template restructuring, not an architecture change.

| Screen | Steps folded in | Why together |
|---|---|---|
| 1. Cari & Pilih | Lokasi, TPU/TPS (incl. plot picker), Jenis Layanan, Pilih Layanan | Pure discovery/selection, no long-form text. City reveals cemeteries; cemetery reveals the granular-tier plot picker where applicable; then service type; then services. Mirrors a hotel's dates→room→extras selection page. |
| 2. Detail Pemesanan | Ringkasan (as a persistent summary card, not its own page), Data Pemesan, Data Almarhum + Dokumen | Fill customer + deceased details with the running total visible alongside — the hotel-checkout "guest details + price summary" pattern. |
| 3. Pembayaran | Pembayaran alone | Kept standalone: too much conditional branching (online/manual/sandbox/session-recovery states) to merge safely into anything else. |
| 4. Konfirmasi | Konfirmasi alone | Terminal, unchanged. |

Within screen 1, each section reveals only once its predecessor is valid
and saved (city → cemetery/plot → service type → services) — this mirrors
a real, already-server-enforced dependency chain (`SaveBookingDraftStep`
already refuses e.g. a cemetery outside the chosen city), so progressive
reveal is not a new rule, only a new *rendering* of an existing one. The
stepper's current-step dot advances 1→2→3→4 as each section is saved,
exactly as it does today, with zero page navigation between them.

Screen 2 similarly reveals Data Pemesan only once Ringkasan is showable
(step 4 complete) and Data Almarhum only once Data Pemesan is saved (step
6 complete) — same existing sequencing rule
(`SaveBookingDraftStep::validateStepSequencing()`), same rendering change.

Two approaches considered and rejected:
- **A stepper redesign** (grouped pills instead of 9 flat dots) reads more
  dramatically "shorter" but requires a real component-contract change to
  `<x-mk.stepper>` for no functional benefit over progressive reveal, and
  risks drifting from the "exact wording, exact order" contract §9.2 sets.
- **All of screen 1's fields shown at once**, no progressive gating, was
  rejected because the underlying data dependencies are real (you cannot
  legally pick a cemetery before a city) — this would either produce a
  confusing screen with disabled/greyed sections until upstream fields are
  filled (i.e., progressive reveal rebuilt worse) or would require relaxing
  a validation rule that exists for a reason.

### Renewal: 6 steps → 3 screens, component merge

Renewal is five separate routed Livewire components threading state
through URL query params (`?tpu=` → `?makam=` → `?perpanjangan=`), not one
persisted draft row. Consolidating requires merging components, which is
materially more work than booking's template-only change.

| Screen | Steps folded in | The work |
|---|---|---|
| 1. Cari Makam | Kota, TPU/TPS, Cari Makam | Merge `GraveSearch` into `RenewalStart` (which already merges Kota+TPU/TPS into one component today). |
| 2. Biaya & Bayar | Biaya, Pembayaran | Merge `RenewalFee` into `RenewalPayment`. |
| 3. Konfirmasi | Konfirmasi alone | Unchanged. |

**Bookmarkability is preserved, not lost.** Each merged component keeps
`#[Url]`-bound properties (`kota`, `tpu`, search criteria, `perpanjangan`)
exactly as the current components do — merging components does not require
merging routes into one, and does not require abandoning Livewire's
existing URL-binding mechanism. A shared/bookmarked search-results link
continues to work exactly as documented in the research
("A URL-only arrival with already-populated params treats it as a real
search").

**The step 3→4 link gap is closed as a byproduct of the merge, not
patched over.** Today `RenewalFee` needs a grave `id` in the URL because it
is a *different* component reached by navigation; `GraveRecordProjection`
deliberately omits an `id` for privacy reasons, so no such link exists.
Once search and fee-quoting live in the same component, the resolved
`GraveRecord` (or its id) can be held in that component's own server-side
state and revealed as the fee section directly — no id is ever added to
the URL or the client-visible projection, closing the gap without
reopening the privacy tradeoff that caused it.

**The explicit "accept the fee" action is preserved as its own click,
inside the merged screen, not auto-fired.** `OpenRenewal` (the only write
in the entire renewal journey) currently fires only from
`terimaDanLanjutkan()`'s explicit click, deliberately never from `mount()`
or a GET — merging the screens does not change this. The merged screen
shows the computed fee, waits for an explicit "Terima Tarif" click (same
as today), and only then reveals the payment section — progressive reveal
again, not a data-model change.

## Scope

**In scope:**
- Booking: `wizard.blade.php` template restructuring into 4 progressive-reveal screens; `BookingWizard.php` gains a computed screen boundary (which of the 4 screens a given `$currentStep` belongs to — a pure function of the existing property, not new stored state), but no change to `saveStepN()` signatures, validation, or sequencing rules.
- Renewal: merge `GraveSearch` into `RenewalStart`; merge `RenewalFee` into `RenewalPayment`; update `routes/web.php` (two fewer route registrations); the id-less fee-reveal fix described above.
- Feature tests updated/added for both (see Testing Decisions).
- `docs/product/screen-inventory.md` and `docs/domain/traceability-matrix.md` updated to reflect the new screen boundaries (the underlying steps/ACs are unchanged, only which screen renders which step).

**Out of scope:**
- No change to `OrderTransition`, `RenewalStatus`, or any domain Action's signature or behavior.
- No change to what data is collected, its validation rules, or the order fields are collected in.
- No change to payment-provider integration, `GuardRenewalPaymentOpening`'s four conditions, or `PaymentMode`/`ModeResolver` gating.
- No `design-system.md` amendment — both wizards keep their documented step counts, dot order, and exact labels.
- The plot-picker UX itself (Phase E, already shipped) is unchanged, only rendered earlier in a longer continuous scroll.
- Actually reducing the *step* count (the other branch of the user's own sequencing decision, not chosen) — if wanted later, that needs a `design-system.md` §9.2 amendment first and is a separate, later decision.

## Implementation decisions

- **Ringkasan lives in screen 2, not screen 1** — it recomputes from screen 1's selections (cemetery/package pricing context + selected services), so it can only render meaningfully once screen 1 is complete; placing it as a running summary next to Data Pemesan matches the hotel "guest details + price summary" pattern directly.
- **Screen boundaries are `$currentStep` ranges, not a new independent variable** — `BookingWizard` computes which of the 4 screens to show from the existing `$currentStep` (e.g. steps 1-4 → screen 1), so there is exactly one source of truth for progress, not two that could drift.
- **Renewal ends up with three Livewire components, not one** (`RenewalStart`+`GraveSearch` merged; `RenewalFee`+`RenewalPayment` merged; `RenewalConfirmation` untouched) rather than collapsing all six steps into a single mega-component — mirrors booking's screen-boundary logic (a screen is a natural cluster of steps that share one write/read cycle) without forcing renewal into a persisted-draft model it does not otherwise need.
- **No new database column or migration** — both changes are presentation-layer; `Renewal`/`BookingDraft` schemas are untouched.

## Testing decisions

- Every existing per-step validation/sequencing test continues to pass unchanged — the underlying `saveStepN()`/`SaveBookingDraftStep`/`OpenRenewal`/`GuardRenewalPaymentOpening` behavior is not modified, only what wraps it visually.
- New booking tests: a section does not render until its predecessor is valid and saved (progressive reveal, both within screen 1 and within screen 2); the stepper's active dot still advances 1..9 in the correct order as sections reveal within a screen, not just between screens.
- New renewal tests: a search-then-fee flow completes end to end within the merged component with no `?makam=` id ever appearing in a request URL or response body; a bookmarked/shared search-results URL (`?kota=&tpu=&nama=` etc.) still resolves directly to results on load, matching current behavior; `OpenRenewal` still fires only on the explicit "Terima Tarif" click, never on mount.
- Existing plot-hold two-connection concurrency tests (`HoldPlotForDraft`, `ConvertDraftHoldToOrderReservation`) are unaffected and re-run unchanged as regression coverage — no change to their code paths.

## Further notes

This spec is deliberately screen-count-only. If the user later wants an
actual reduction in the number of documented steps (not just screens),
that is a different, larger decision requiring a `design-system.md` §9.2
amendment first, and should be brainstormed as its own follow-up rather
than folded into this one.
