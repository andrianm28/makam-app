<?php

declare(strict_types=1);

namespace App\Support\Design;

use Carbon\CarbonImmutable;

/**
 * The ONE place the Indonesian weekday/month vocabulary lives — shared by
 * the public visitation page's slot picker and the admin visitation-policy
 * form's operating-hours rows, so the seven weekday labels and the twelve
 * month names are never hand-maintained in two places (AGENTS.md
 * §Documentation: no duplicated canonical data). Presentation vocabulary
 * only — no policy, no domain rule.
 *
 * `weekdayLine()` renders an operating-hours row ("Senin: 08.00–17.00" /
 * "Selasa: tutup") — the en-dash and the dot-separated clock form match
 * the platform's own established clock phrasing (`ContactInfo::
 * BUSINESS_HOURS`, "08.00–17.00 WIB"); the timezone suffix is deliberately
 * NOT appended: the operator writes HH:MM, and this class must not claim
 * a timezone the policy never declared.
 */
final class IndonesianDate
{
    /**
     * PHP `D` weekday keys (`mon`..`sun`, the `CemeteryVisitationPolicy
     * ::WEEKDAY_KEYS` vocabulary) → Indonesian labels.
     *
     * @var array<string, string>
     */
    private const array WEEKDAY_LABELS = [
        'mon' => 'Senin',
        'tue' => 'Selasa',
        'wed' => 'Rabu',
        'thu' => 'Kamis',
        'fri' => 'Jumat',
        'sat' => 'Sabtu',
        'sun' => 'Minggu',
    ];

    /**
     * @var list<string>
     */
    private const array MONTH_LABELS = [
        'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember',
    ];

    public static function weekdayLabel(string $key): string
    {
        return self::WEEKDAY_LABELS[$key] ?? $key;
    }

    /**
     * "Senin, 17 Agustus 2026" — the slot-picker label shape.
     */
    public static function longDate(CarbonImmutable $date): string
    {
        $monthIndex = (int) $date->format('n') - 1;

        return sprintf(
            '%s, %d %s %d',
            self::weekdayLabel(strtolower($date->format('D'))),
            (int) $date->format('j'),
            self::MONTH_LABELS[$monthIndex] ?? (string) $date->format('F'),
            (int) $date->format('Y'),
        );
    }

    /**
     * "Senin: 08.00–17.00" for an open day, "Senin: tutup" for a closed
     * one — the info banner / hours summary row shape.
     *
     * @param  array{open: string, close: string}|null  $hours
     */
    public static function weekdayLine(string $key, ?array $hours): string
    {
        if ($hours === null) {
            return self::weekdayLabel($key).': tutup';
        }

        return sprintf('%s: %s–%s', self::weekdayLabel($key), self::clock($hours['open']), self::clock($hours['close']));
    }

    /**
     * "08:00" → "08.00" — the dot-separated clock form the platform's
     * Indonesian copy uses.
     */
    public static function clock(string $hhmm): string
    {
        return str_replace(':', '.', $hhmm);
    }
}
