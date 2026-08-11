<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Exceptions;

use RuntimeException;

/**
 * The typed, application-facing half of AC11's duplicate-period guard.
 *
 * The actual guard is `renewals_grave_period_unique`, a database unique
 * index (`2026_08_12_100000_create_renewals_table.php`) — application code
 * MUST NOT check-then-insert, because two concurrent requests could both
 * pass the check and both insert, which is exactly the race the index
 * exists to close. This exception is not a substitute for that index; it is
 * what a later task's write path (`OpenRenewal`, `MarkExternalRenewal`)
 * throws after CATCHING the resulting `Illuminate\Database\QueryException`,
 * so a caller sees a named domain failure instead of a raw SQL error.
 * Neither of those Actions exists yet — this task only reserves the shape,
 * matching `App\Domain\Faq\Exceptions\FaqArticleVersionIsImmutableException`'s
 * precedent of a plain `RuntimeException` with a named static factory per
 * call site.
 */
final class DuplicateRenewalPeriodException extends RuntimeException
{
    public static function forGravePeriod(string $graveRecordId, string $period): self
    {
        return new self(
            "A renewal already exists for grave record [{$graveRecordId}] and period [{$period}]. ".
            'One renewal settlement is permitted per grave period, enforced by renewals_grave_period_unique.'
        );
    }
}
