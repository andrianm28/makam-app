<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use InvalidArgumentException;

/**
 * The closed list of `renewals.status` values.
 *
 * `docs/superpowers/plans/2026-08-12-platform-renewal-completion.md` fixes
 * these three exact strings: `MENUNGGU_PEMBAYARAN`, `DIBAYAR`,
 * `KEDALUWARSA`. They are not invented here — they are the same
 * order-lifecycle vocabulary `app/Support/Design/StatusIntent.php`'s
 * `FAMILY_ORDER_LIFECYCLE` map already resolves to a badge intent
 * (`MENUNGGU_PEMBAYARAN` → pending/clock, `DIBAYAR` → success/banknote), so
 * a renewal's status badge renders correctly through that existing
 * resolver without adding a renewal-specific case to it.
 *
 * Plain `string` column with application-layer validation, no Postgres
 * `CHECK` — the same `final class` + `KNOWN_*` + `assertKnown()` shape as
 * `App\Domain\GraveRegistry\GraveRecordAccessMode` and
 * `App\Domain\GraveRegistry\GraveRecordSource`; see the former's doc block
 * for the convention citation.
 *
 * `KEDALUWARSA` ("expired", Indonesian) exists in the vocabulary now even
 * though nothing in this task writes it — the reminder/expiry scheduler is
 * a later task in this lane, and widening a closed list later is exactly
 * the "migration to extend" cost this convention exists to avoid.
 */
final class RenewalStatus
{
    /**
     * The renewal is open and a quote has been (or can be) generated, but
     * no payment has settled it yet. The status every online `renewals` row
     * starts at.
     */
    public const string MENUNGGU_PEMBAYARAN = 'MENUNGGU_PEMBAYARAN';

    /**
     * Money has settled this renewal — either a valid online payment or an
     * admin external marking (AC10). `DIBAYAR` ≠ `SELESAI` is an
     * order-lifecycle distinction (`AGENTS.md`: "Paid does not mean
     * completed") that does not apply to a renewal the same way, because a
     * renewal has no separate fulfilment step; it is included here only
     * because it is the same string `StatusIntent` already maps.
     */
    public const string DIBAYAR = 'DIBAYAR';

    /**
     * The renewal window closed without payment. Not written by this task —
     * reserved for the reminder/expiry scheduler this lane's plan defers.
     */
    public const string KEDALUWARSA = 'KEDALUWARSA';

    /**
     * In the order `docs/superpowers/plans/
     * 2026-08-12-platform-renewal-completion.md` lists them.
     *
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::MENUNGGU_PEMBAYARAN,
        self::DIBAYAR,
        self::KEDALUWARSA,
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::KNOWN_STATUSES, true);
    }

    /**
     * @throws InvalidArgumentException when `$status` is not one of
     *                                  `self::KNOWN_STATUSES`.
     */
    public static function assertKnown(string $status): void
    {
        if (! self::isKnown($status)) {
            throw new InvalidArgumentException(
                "Unknown renewal status [{$status}]. Known statuses: ".implode(', ', self::KNOWN_STATUSES).'.'
            );
        }
    }
}
