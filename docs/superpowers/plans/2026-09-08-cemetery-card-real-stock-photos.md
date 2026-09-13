# TPU/TPS cards: real stock photos instead of generic illustrations

## Context

Product owner request, 8 Sep 2026 (relayed via WhatsApp): "tambahkan foto ke semua data yang ada fotonya" — add photos to every TPU/TPS record.

This reverses two earlier, deliberate decisions in the codebase: `2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php` used generic illustration SVGs for the 10 fictional example cemeteries to avoid fabricating a photo of a place that doesn't exist, and `2026_08_24_100000_backfill_photo_and_maps_url_for_real_cemeteries.php` used the same SVGs for the 4 real, named cemeteries to avoid misattributing a photo of a different real place to a specific one. Both risks were surfaced to the product owner directly; the explicit direction was to use real stock photos for all 14 rows anyway, accepting both risks.

## Change

- Four new neutral cemetery/garden stock photos (Pexels, same photographer as the homepage hero, Pexels License — daylight, no people, no religious iconography, per design-system.md §2.2) at `public/images/cemeteries/photo-0{1,2,3,4}-*.jpg`, ~960px wide, each under GATE 14's 300KB general image budget.
- `CemeteryExampleData::EXAMPLE_PHOTOS` now points at the four new JPGs instead of the four illustration SVGs — same round-robin cycling by index as before.
- New migration `2026_09_08_100000_backfill_real_photos_for_real_and_example_cemeteries.php` sets the same four photos (round-robin) on the 4 real named cemeteries, and re-runs `CemeteryExampleData::applyBackfill()` so an already-migrated environment's 10 fictional rows pick up the new paths too (a fresh environment already gets them from the updated constant via the original seed migration).
- Fixed the `alt="Ilustrasi {name}"` text on all four render sites (`home-page.blade.php`, `directory/index.blade.php`, `directory/detail.blade.php`, `booking/wizard.blade.php`) to `alt="Foto {name}"` — the old wording was accurate for an SVG illustration and became wrong once a real photo replaced it.

## Test

- `CemeterySeedTest`'s existing dummy-photo assertion updated from `.svg` to `.jpg`.
- New `BackfillRealPhotosForRealAndExampleCemeteriesTest`: proves `up()` sets a real `.jpg` path for the 4 real slugs, proves it corrects an already-`.svg` fictional row back to `.jpg` (isolating the migration's own effect from the seed migration's now-updated baseline, which would otherwise mask a no-op `up()`), and proves `down()` restores the original per-index illustration cycling for both groups. Mutation-tested: commenting out the `applyBackfill()` call makes the fictional-row test fail with the exact expected assertion.
- `BookingWizardStepTwoCardContentTest`'s alt-text assertion updated to match.

## Verification

- `vendor/bin/pint --test` — PASS
- `vendor/bin/phpstan analyse --no-progress` — no errors (baseline regenerated fresh; diff is only new entries for the new test file's anonymous-migration-class pattern, matching the existing convention for `BackfillPhotoAndMapsUrlForRealCemeteriesTest`)
- `bash ci/verify-docs.sh` — all 14 gates PASS
- 110 tests across the affected directory/booking/home-page/migration suites — PASS, against real PostgreSQL 18 + Redis 8.2 in Docker

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01V5HEWU9oWnDfM1kQTB9762
