# Plot picker: don't hide unsegmented plots when a package is selected

## Context

Real UAT against `dev.makam.co.id` (8 Sep 2026), following the redeploy of PR #274 (the plot-picker package-scoping fix). Selecting "Kelas A" for TPU Jakarta Menteng showed "Belum ada plot terdaftar untuk TPU/TPS ini" — no plots at all, where before the fix the block at least rendered (with the wrong booked/available mix, the original bug).

## Root cause

PR #274's fix scoped `pickerBlocks()`/`holdPlotForDiscovery()` to plots whose `cemetery_package_id` exactly matched the selected package. Inspecting the real `dev` database showed TPU Jakarta Menteng's block was generated with `cemetery_package_id = NULL` on every plot, even though the cemetery has 4 named packages — a cemetery whose granular inventory was never segmented by package/class at all. That is a normal, common shape (an operator can define packages for pricing/listing purposes before ever splitting the physical block into per-class sections), not an edge case. The strict `=` comparison treated every one of those plots as "belongs to a different package" the moment ANY package was selected, hiding the entire block — a worse regression than the cross-package leak PR #274 fixed.

## Change

`pickerBlocks()` and `holdPlotForDiscovery()` both now match a plot when its `cemetery_package_id` equals the selected package **or is null** — package-agnostic plots stay visible/holdable regardless of which package the customer picked, while a plot explicitly tagged with a *different* package is still excluded (the actual leak PR #274 closed stays closed).

## Test

New `BookingWizardPlotPickerTest::test_picker_blocks_still_shows_unsegmented_plots_regardless_of_selected_package` reproduces the exact real-data shape (a package selected, a block whose plots all have `cemetery_package_id = null`) and proves the block/plot stays visible and holdable. Mutation-tested: reverting to the strict `=` comparison makes this new test fail with the exact "actual size 0" assertion UAT observed; restoring the fix turns it green. All 30 tests in the file, and all 178 across `tests/Feature/Livewire/Public/Booking/`, pass.

## Verification

- `vendor/bin/pint --test` — PASS
- `vendor/bin/phpstan analyse --no-progress` — no errors (baseline count bumped 5→6 for the pre-existing `pickerBlocks()` test-double pattern, matching the one new call site)
- `bash ci/verify-docs.sh` — all 14 gates PASS
- `tests/Feature/Livewire/Public/Booking/` (178 tests) — PASS against real PostgreSQL 18 + Redis 8.2 in Docker
- Manually re-verified against `dev.makam.co.id`'s actual TPU Jakarta Menteng data after this fix deploys (see follow-up deploy note)

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01V5HEWU9oWnDfM1kQTB9762
