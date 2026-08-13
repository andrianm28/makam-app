# Fix: Booking wizard Step 5 → Step 6 handoff (dead-end)

**Date:** 13 Aug 2026
**Root cause:** After completing Step 4, the wizard lands on Step 5 (Ringkasan). The summary section had no forward control, and `canReachStep(CUSTOMER_DATA)` returned `false` (default branch) until Step 6 was already in `completed_steps` — which could never happen first. Result: user stranded on Step 5, unable to reach Steps 6-9. Reproduced in a real browser on dev (Steps 1-4 work, Step 5 renders only summary + Kembali, zero form inputs). Reported by live user.

## Fix (root cause, not symptom)
1. `app/Livewire/Public/Booking/BookingWizard.php` — `canReachStep()`: add `CUSTOMER_DATA => in_array(SERVICES, $this->completedSteps, true)` so Step 6 is reachable once the journey decisions (Step 4) are complete, mirroring the existing SUMMARY/`SERVICES` pattern. Update docblock.
2. `resources/views/livewire/public/booking/wizard.blade.php` — Step 5 SUMMARY section: add a primary "Lanjut ke Data Pemesan" button (`goToStep(CUSTOMER_DATA)`) beside the existing Kembali button.

## Regression tests
`tests/Feature/Livewire/Public/Booking/BookingWizardStepFiveToSixHandoffTest.php` — 3 tests: summary offers forward path; goToStep(6) advances; Step 6 renders customer-data form.

## Verification
- Booking suite: 69 passed (66 existing + 3 new)
- Full suite: 2184 passed / 63 skipped / 0 failures
- pint clean, phpstan clean on touched file
