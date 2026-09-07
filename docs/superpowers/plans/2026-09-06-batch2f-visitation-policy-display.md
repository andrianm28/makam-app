# Batch 2F — Fix uniform visitation-hours display (COORD-05 / STAT-03)

Source: `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`, Phase 2,
"Batch 2F — Perbaikan tampilan jam kunjung seragam".

## Problem

`CemeteryVisitationPoliciesTable::hoursSummary()` builds `$uniformPair` as an
associative array (`['open' => ..., 'close' => ...]`), but the uniform-hours
branch reads it with numeric offsets:

```php
IndonesianDate::clock((string) $uniformPair[0]),
IndonesianDate::clock((string) $uniformPair[1]),
```

`$uniformPair[0]` and `$uniformPair[1]` don't exist, so `(string) null` is
`''`. Every cemetery with the same open/close time on all seven days renders
as `"Setiap hari –"` in the admin list instead of e.g.
`"Setiap hari 08.00–17.00"`. Non-uniform cemeteries are unaffected — they use
the per-day `$lines` array via `IndonesianDate::weekdayLine()`, which already
reads `['open']`/`['close']` correctly.

No existing test asserts the `"Setiap hari"` string (confirmed by grep —
only the two occurrences in the production file itself; the doc comment and
the format string).

## Fix

Read the associative keys instead of numeric offsets:

```php
IndonesianDate::clock($uniformPair['open']),
IndonesianDate::clock($uniformPair['close']),
```

(`$uniformPair['open']`/`['close']` are already normalized to `string` at
construction time in the loop above, so the redundant `(string)` casts can be
dropped.)

## Test

Add a unit-style test calling `CemeteryVisitationPoliciesTable::hoursSummary()`
directly (it's a public static method — no Filament table/Livewire scaffolding
needed) with a uniform 7-day `08:00`–`17:00` schedule, asserting the exact
string `"Setiap hari 08.00–17.00"`. Also keep/add a non-uniform case to show
the existing per-day path is untouched, and a `null`/non-array input case for
`"Belum diatur"` since those are cheap and pin the method's public contract.

Location: new test file
`tests/Unit/Filament/Admin/CemeteryVisitationPoliciesTableTest.php` (no
`Tests\Unit\Filament\Admin` directory exists yet — created here since this is
a pure static-method unit test, not a Filament/Livewire integration test).

## Verification

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress`
- `bash ci/verify-docs.sh`
- New test passes; full suite run inside Docker (PHP 8.5) against real
  Postgres/Redis containers, not sqlite.

## Out of scope

Everything else in Batch 2F's parent plan (2G, 2I, Phase 3 Medium batches) —
this PR is the single COORD-05/STAT-03 fix only, per the "kandidat PR mandiri
kecil" framing in the source plan.
