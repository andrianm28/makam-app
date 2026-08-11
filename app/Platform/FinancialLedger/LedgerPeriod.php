<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use Carbon\CarbonImmutable;

/**
 * The ONE definition of "which batches belong to `YYYY-MM`" for this module.
 *
 * ---------------------------------------------------------------------------
 * Why this exists: two expressions of one rule is one too many
 * ---------------------------------------------------------------------------
 * `LedgerReport` and `RunReconciliation` each built this window their own way —
 * one with `createFromFormat('!Y-m', $period, 'Asia/Jakarta')`, the other with
 * `CarbonImmutable::parse($period.'-01 00:00:00')` in the app timezone (UTC).
 * They agreed, but only by a coincidence of the persistence layer:
 * `Connection::prepareBindings()` formats a `DateTimeInterface` with
 * `Y-m-d H:i:s` in the object's OWN zone without converting, and `occurred_at`
 * is `timestamp without time zone` — so both produced the literal binding
 * `2026-08-01 00:00:00` despite carrying different timezones.
 *
 * That coincidence is one refactor deep. Anyone adding `->utc()`, or moving
 * `occurred_at` to an Eloquent date cast (which DOES convert), would make the
 * report and the reconciliation disagree by seven hours about which month a
 * batch belongs to — a variance finding raised against a batch that is in the
 * period, or a batch counted in a report and not in the reconciliation of the
 * same period. Two independent reviewers found this latent divergence
 * separately, which is why it is being removed rather than documented again.
 *
 * The bindings are byte-identical before and after this extraction, captured by
 * execution on real PostgreSQL 18 rather than argued from the code.
 *
 * ---------------------------------------------------------------------------
 * Asia/Jakarta is the canonical period timezone for this module
 * ---------------------------------------------------------------------------
 * The choice is not behavioural today (see above), so this is settling a
 * documentation question, not changing a boundary. `Asia/Jakarta` wins because
 * the periods being reported are Indonesian accounting months for an Indonesian
 * business, which is what `LedgerReport` already documented, and because the
 * alternative — the app's UTC default — is an implementation detail nobody
 * chose for this purpose.
 *
 * The moment `occurred_at` stops being stored as naive wall-clock, this
 * constant becomes load-bearing and its value is a decision a human must
 * confirm. It is named and centralised here so that decision has exactly one
 * place to be made.
 */
final class LedgerPeriod
{
    /**
     * `YYYY-MM`, months `01`-`12` only. `\A`/`\z` with the `D` modifier so a
     * trailing newline cannot smuggle a second line past the anchor.
     */
    public const string PATTERN = '/\A\d{4}-(0[1-9]|1[0-2])\z/D';

    public const string TIMEZONE = 'Asia/Jakarta';

    public static function matches(string $period): bool
    {
        return preg_match(self::PATTERN, $period) === 1;
    }

    /**
     * Half-open `[start, start + 1 month)`, so a batch at midnight on the first
     * of the next month belongs to that month and to exactly one period.
     *
     * @return array{CarbonImmutable, CarbonImmutable} `[start, endExclusive]`
     */
    public static function boundsFor(string $period): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period, self::TIMEZONE)->startOfMonth();

        return [$start, $start->addMonth()];
    }
}
