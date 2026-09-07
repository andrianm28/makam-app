# Booking wizard: scroll to the newly-revealed section on cemetery selection

## Context

Real customer report, 7 Sep 2026 (via WhatsApp, forwarded by the product owner): a customer on `makam.co.id` said the booking wizard "wasn't loading" after picking a TPU/TPS ("Yg makam aku klik blm bs loading yan ... Ke tahapan berikutnya"), that the plot data "didn't connect" to the cemetery ("data plot dengan makamnya tidak nyambung"), and that mobile users aren't aware they need to scroll ("kalo di screen mobile user tidak aware harus scroll ke bawah").

## Root cause (confirmed by live reproduction on makam.co.id)

Reproduced end to end on the live site: selecting Jakarta shows 7+ published cemetery cards in "Pilih TPU/TPS" (`TPS Jakarta 2`, `TPU Jakarta 1`, `TPU Karet Bivak`, `TPU Petamburan`, `TPU Pondok Kelapa`, `TPU Semper (Budi Dharma)`, and more). Clicking "Pilih TPS Jakarta 2" (near the top of that list) correctly sets `$cemeteryId` server-side — the button visibly flips to "Terpilih TPS Jakarta 2", and the Livewire request succeeds (HTTP 200) — but the "Pilih Jenis Layanan" section this reveals renders only AFTER the full cemetery card list, per `resources/views/livewire/public/booking/wizard.blade.php`'s progressive-reveal structure (`@if ($cemeteryId !== null)` block starting at the file's `discovery-service-type-heading` section). On a city with several cemeteries, that section can be many screens below the button the customer just pressed, with zero on-screen cue that anything happened.

Followed the flow all the way to a real draft (Step 2, `/pemesanan-makam/draft/{uuid}`) with no errors — the wizard itself is not broken. This is a pure discoverability/UX gap, not a technical defect, worse on a narrow (mobile) viewport where less of the page is visible per scroll.

This also explains the "data plot dengan makamnya tidak nyambung" complaint: because the customer never realized the click had done anything, the plot/package section they eventually found (after manually scrolling, likely well after giving up on the original click) reads as disconnected from the cemetery card above it.

## Fix

`BookingWizard::selectCemetery()` now dispatches a Livewire browser event, `booking-wizard-cemetery-selected`, after setting `$cemeteryId`/`$cemeteryPackageId` (covers both the direct "Pilih" button on a non-granular cemetery and the picker's plot-confirm path, both of which call `selectCemetery()`). The Blade view's root element gained a plain Alpine `x-on:booking-wizard-cemetery-selected.window` listener that scrolls `#discovery-service-type-heading` into view with `scrollIntoView({ block: 'start' })`, using `smooth` behavior unless the visitor's OS/browser reports `prefers-reduced-motion: reduce` (falls back to `auto` — the reveal itself is not motion, only the scroll animation is skipped, matching `design-system.md` §7.6/§9's existing reduced-motion discipline for this codebase).

No change to the DISCOVERY reveal logic itself (`design-system.md`'s progressive-disclosure pattern is unchanged), no change to validation, no change to what data is saved — this is a client-side scroll cue only.

## Scope note

Scoped to the cemetery→service-type transition specifically, since that's the one confirmed by live reproduction (the cemetery list is the only unbounded-length section in this screen; city buttons and the service-type→services-list transition sit close together already and were not reported or reproduced as a problem). If a future report surfaces the same gap elsewhere in this screen, the same `$this->dispatch(...)` + `x-on:...window` pattern generalizes directly.

## Test

`tests/Feature/Livewire/Public/Booking/BookingWizardProgressiveRevealTest.php` gained `test_choosing_a_cemetery_dispatches_the_scroll_to_service_type_event`, asserting `selectCemetery()` dispatches the new event — the furthest a PHPUnit test can reach into this fix, since the resulting `scrollIntoView()` call is a browser-side behavior. Mutation-tested: temporarily removed the `$this->dispatch(...)` call, confirmed the new test fails (`Failed asserting that an event [booking-wizard-cemetery-selected] was fired.`), restored it, confirmed green again.

## Verification

- `vendor/bin/pint --test` — PASS (3 touched files)
- `vendor/bin/phpstan analyse --no-progress` — no errors
- `bash ci/verify-docs.sh` — all 13 gates PASS
- `BookingWizardProgressiveRevealTest` (13 tests) + `BookingWizardEndToEndTest` — PASS, against real PostgreSQL 18 + Redis 8.2 in Docker (disposable containers, cleaned up after)
- Manually reproduced the original bug and the fix's intended target on the live `makam.co.id` site via browser automation before writing any code (see PR description for the exact repro steps)

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01V5HEWU9oWnDfM1kQTB9762
