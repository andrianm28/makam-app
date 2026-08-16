<?php

declare(strict_types=1);

namespace App\Domain\Visitation\Models;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Eloquent model for `cemetery_visitation_policies` — Task 1 of
 * `docs/superpowers/plans/2026-08-16-p4-memorial-qr-visitation.md`
 * (Lane 1 — Visitation). One row per cemetery: the weekday operating-
 * hours template and the daily capacity every visitation booking for
 * that cemetery is checked against.
 *
 * ---------------------------------------------------------------------------
 * `operating_hours` — the allowlist is enforced HERE, at the model
 * ---------------------------------------------------------------------------
 * The column is untyped JSON (see the migration's doc block); this
 * model's `saving` guard is the single authority on its shape:
 *   - keys must be a subset of the seven weekday keys `mon`..`sun`;
 *   - each value is either `null` (closed that weekday) or an object
 *     with exactly `open` and `close`, each an `HH:MM` 24-hour time.
 * A policy violating the shape is rejected with
 * `InvalidArgumentException` on save — the guard runs for `create()` and
 * `update()` alike (the `booted()`/`saving` pattern `Cemetery` uses for
 * its own closed lists).
 *
 * `daily_capacity` is guarded `>= 1`: a zero-capacity policy would be
 * "closed every day", which the module models explicitly as blackout
 * dates instead.
 */
final class CemeteryVisitationPolicy extends Model
{
    use HasUuids;

    protected $table = 'cemetery_visitation_policies';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cemetery_id',
        'operating_hours',
        'daily_capacity',
    ];

    /**
     * The allowlisted weekday keys of `operating_hours` — `strtolower()`'d
     * PHP `D` format values, so a date's weekday maps to its key with
     * `strtolower($date->format('D'))`.
     *
     * @var list<string>
     */
    public const array WEEKDAY_KEYS = [
        'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun',
    ];

    /**
     * The single definition of "a well-formed HH:MM time" — 24-hour
     * `00:00`..`23:59`.
     */
    private const string TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operating_hours' => 'array',
            'daily_capacity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $policy): void {
            self::assertOperatingHours($policy->operating_hours);

            if ((int) $policy->daily_capacity < 1) {
                throw new InvalidArgumentException(
                    "Visitation policy daily capacity must be at least 1, got [{$policy->daily_capacity}]."
                );
            }
        });
    }

    /**
     * True when the date falls on a weekday with operating hours — the
     * "bookable weekday" test `RequestVisitation` applies (a weekday key
     * of `null` means the cemetery is closed that weekday).
     */
    public function isVisitingDay(CarbonImmutable $date): bool
    {
        return $this->hoursFor($date) !== null;
    }

    /**
     * The date at the weekday's opening time, or null when the cemetery
     * is closed that weekday.
     */
    public function openTimeFor(CarbonImmutable $date): ?CarbonImmutable
    {
        $hours = $this->hoursFor($date);

        return $hours === null ? null : $this->atTime($date, $hours['open']);
    }

    /**
     * The date at the weekday's closing time, or null when the cemetery
     * is closed that weekday.
     */
    public function closeTimeFor(CarbonImmutable $date): ?CarbonImmutable
    {
        $hours = $this->hoursFor($date);

        return $hours === null ? null : $this->atTime($date, $hours['close']);
    }

    /**
     * True when this policy's blackout list covers the date.
     *
     * The binding is the CarbonImmutable itself, not
     * `$date->toDateString()`: on SQLite Eloquent stores `date`-cast
     * columns as `'Y-m-d H:i:s'`, so a raw `'Y-m-d'` string never
     * equals the stored value (verified in-suite); each grammar formats
     * a DateTime binding the same way it formats the cast on save, and
     * PostgreSQL coerces it to its `date` column type.
     */
    public function isBlackout(CarbonImmutable $date): bool
    {
        return VisitationBlackoutDate::query()
            ->where('policy_id', $this->getKey())
            ->where('date', $date)
            ->exists();
    }

    /**
     * The visitor-visible reason of this policy's blackout covering the
     * date, or null when the date is not blacklisted.
     */
    public function blackoutReasonFor(CarbonImmutable $date): ?string
    {
        return VisitationBlackoutDate::query()
            ->where('policy_id', $this->getKey())
            ->where('date', $date)
            ->value('reason');
    }

    /**
     * @return array{open: string, close: string}|null
     */
    private function hoursFor(CarbonImmutable $date): ?array
    {
        $hours = $this->operating_hours;

        if (! is_array($hours)) {
            return null;
        }

        $entry = $hours[strtolower($date->format('D'))] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        return $entry;
    }

    private function atTime(CarbonImmutable $date, string $hhmm): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $hhmm));

        return $date->setTime($hour, $minute);
    }

    /**
     * @param  mixed  $hours  The model's `operating_hours` attribute
     *                        (decoded JSON).
     */
    public static function assertOperatingHours(mixed $hours): void
    {
        if (! is_array($hours)) {
            throw new InvalidArgumentException(
                'Visitation policy operating_hours must be a JSON object keyed by weekday (mon..sun).'
            );
        }

        foreach ($hours as $key => $entry) {
            if (! in_array($key, self::WEEKDAY_KEYS, true)) {
                throw new InvalidArgumentException(
                    "Unknown weekday key [{$key}] in operating_hours; allowed keys: ".implode(', ', self::WEEKDAY_KEYS).'.'
                );
            }

            if ($entry === null) {
                continue;
            }

            $entryKeys = is_array($entry) ? array_keys($entry) : [];
            $expectedKeys = ['open', 'close'];
            sort($entryKeys);
            sort($expectedKeys);

            if ($entryKeys !== $expectedKeys) {
                throw new InvalidArgumentException(
                    "operating_hours[{$key}] must be null or exactly {open, close}; got [".json_encode($entry).']'
                );
            }

            if (! is_string($entry['open']) || preg_match(self::TIME_PATTERN, $entry['open']) !== 1) {
                throw new InvalidArgumentException(
                    "operating_hours[{$key}].open must be HH:MM (24-hour); got [".json_encode($entry['open'] ?? null).'].'
                );
            }

            if (! is_string($entry['close']) || preg_match(self::TIME_PATTERN, $entry['close']) !== 1) {
                throw new InvalidArgumentException(
                    "operating_hours[{$key}].close must be HH:MM (24-hour); got [".json_encode($entry['close'] ?? null).'].'
                );
            }
        }
    }

    /**
     * The owning cemetery — the read side the admin policy resource's
     * table column (`cemetery.name`) and the scoped bookings resource
     * both route through.
     *
     * @return BelongsTo<Cemetery, $this>
     */
    public function cemetery(): BelongsTo
    {
        return $this->belongsTo(Cemetery::class, 'cemetery_id');
    }

    /**
     * The blackout rows the `BlackoutDatesRelationManager` renders —
     * `(policy_id, date)` unique (migration), so this is one row per
     * closed date.
     *
     * @return HasMany<VisitationBlackoutDate, $this>
     */
    public function blackoutDates(): HasMany
    {
        return $this->hasMany(VisitationBlackoutDate::class, 'policy_id');
    }
}
