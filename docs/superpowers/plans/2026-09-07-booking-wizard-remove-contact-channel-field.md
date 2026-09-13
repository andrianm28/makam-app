# Booking wizard: remove "Saluran Kontak yang Disukai" field

## Context

Product owner request, 7 Sep 2026 (relayed via WhatsApp): "Saluran Kontak yang Disukai * - bagian ini take out aja yan" — take this field out.

## Change

Removed the "Saluran Kontak yang Disukai" (preferred contact channel) `<select>` field entirely from Step 6 of the booking wizard (`resources/views/livewire/public/booking/wizard.blade.php`). The field was previously mandatory (`customer_contact_channel`, backed by `App\Domain\Booking\BookingContactChannel`).

`customer_contact_channel` stays a real, nullable `BookingDraft` column rather than being deleted outright — this is a UI-scope removal, not a decision to delete the domain concept:

- `SaveBookingDraftStep::validateDeceasedData()`'s validation for this field is now optional-but-validated-if-present: a missing value is accepted (matches the field no longer being collected), but an explicit, unknown value is still rejected against `BookingContactChannel::KNOWN_CODES` — so a future caller (or an old client still sending the field) can't silently write garbage into the column.
- The persistence line in `SaveBookingDraftStep` was changed from a direct, unguarded array access (`$payload['customer_contact_channel']`, which would have thrown `Undefined array key` the moment a caller genuinely omits the key) to `?? null`, matching the pattern already used for the other now-optional Step 6 fields.
- `BookingContactChannel::label(?string $code): string` already degrades gracefully for a null/unrecognised code (its own doc block: "Falls back to a neutral phrase for a null or unrecognised code so a confirmation screen never has to print a raw enum token at a bereaved reader" — `'kontak yang Anda pilih'`), so the confirmation screen's several uses of `$confirmationData['contact_channel_label']` render sensibly with no further change needed.

The Livewire component's `public string $customerContactChannel = '';` property, its inclusion in the save payload, and the many `Livewire::test(...)->set('customerContactChannel', ...)` calls across the existing test suite were deliberately left untouched — they still work exactly as before (setting a plain public property has never depended on a Blade `<select>` existing), and touching them would have been unrelated scope creep for a UI-only removal.

## Test

`tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepSteps678Test.php`'s `test_customer_and_deceased_data_step_rejects_a_missing_contact_channel` (which pinned the OLD mandatory behavior) was replaced with `test_customer_and_deceased_data_step_accepts_a_missing_contact_channel`, proving a request that omits the field entirely now saves successfully with `customer_contact_channel` persisted as `null`. The sibling tests proving the closed-list check still rejects an unknown value, and still accepts every known code, were left as-is — both still pass unchanged.

## Verification

- `vendor/bin/pint --test` — PASS
- `vendor/bin/phpstan analyse --no-progress` — no errors
- `bash ci/verify-docs.sh` — all 14 gates PASS
- `tests/Feature/Livewire/Public/Booking/` + `tests/Feature/Domain/Booking/` (336 tests) — PASS, against real PostgreSQL 18 + Redis 8.2 in Docker

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01V5HEWU9oWnDfM1kQTB9762
