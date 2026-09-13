# Booking wizard: plot picker cross-package data leak

## Context

Product owner report, 8 Sep 2026 (screenshot from `dev.makam.co.id`): after picking a TPU/TPS and a package/kelas in the DISCOVERY step, the floor/block map showed a block with "Kapasitas 6 · 0 tersedia" and all 6 plots rendered as booked ("Dipesan") — "data plot msh salah, tidak sesuai tpu/tps dan kelasnya" (plot data doesn't match the selected TPU/TPS and its class).

## Root cause

`BookingWizard::pickerBlocks()` scoped its `CemeteryBlock` query by `cemetery_id` only. `CreateCemeteryBlock` generates every block wholly against one package (`$cemeteryPackageId` applies to every plot the call creates), so a cemetery with more than one package has multiple, disjoint blocks — one per package. The unscoped query returned every block for the cemetery regardless of which package the customer had actually selected (`$pickerCemeteryPackageId`), so a customer choosing "Kelas A" could be shown "Kelas B"'s block: wrong availability count, wrong plots on the grid, and (via `holdPlotForDiscovery()`, which had the same gap) no server-side check stopping a hold on a plot from the wrong package.

## Change

- `BookingWizard::pickerBlocks()` — when `$pickerCemeteryPackageId` is set, blocks with no plot in that package are excluded (`whereHas('plots', ...)`), and the eager-loaded `plots` relation is filtered to that package too. No filter is applied when the property is null (a cemetery whose blocks were generated without a package) — unchanged, pre-existing behaviour.
- `BookingWizard::holdPlotForDiscovery()` — added a server-side check that the fetched plot's `cemetery_package_id` matches the caller-supplied `$cemeteryPackageId`; mismatches are rejected with a `plot` validation error instead of allowing a hold on a plot from the wrong class.

## Test

`tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php`: added a fixture with two packages, each with its own block/plot, and four new tests proving (a) selecting package A only shows block A/plot A, (b) selecting package B only shows block B/plot B, (c) attempting to hold a plot from the non-selected package is rejected with no reservation created, (d) holding a plot from the selected package still succeeds. Confirmed via mutation testing: reverting the `BookingWizard.php` fix while keeping the new tests makes exactly the 3 package-scoping assertions fail (block count mismatch ×2, missing validation error ×1); restoring the fix turns all green again.

## Verification

- `vendor/bin/pint --test` — PASS
- `vendor/bin/phpstan analyse --no-progress` — no errors (baseline count bumped 3→5 for the pre-existing `pickerBlocks()` undefined-method-on-test-double pattern, matching the 2 new call sites)
- `bash ci/verify-docs.sh` — all 14 gates PASS
- `tests/Feature/Livewire/Public/Booking/` (177 tests) — PASS, against real PostgreSQL 18 + Redis 8.2 in Docker

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01V5HEWU9oWnDfM1kQTB9762
