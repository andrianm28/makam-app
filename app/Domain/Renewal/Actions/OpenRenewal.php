<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only online write path for a renewal — AC11 at the seam.
 *
 * The AC11 guard is the database constraint `renewals_grave_period_unique`.
 * Application-level pre-checking ("does a row already exist?") is a race, not
 * a guard: two concurrent requests could both pass the check and both insert,
 * defeating the invariant. The only correct approach is to attempt the write
 * and catch the constraint violation — which is what this Action does.
 *
 * This Action calls `QuoteRenewal`, which atomically creates both the
 * `Renewal` and its initial `RenewalQuote` in one transaction. If the
 * constraint fires (concurrent request, or sequential call that somehow
 * slipped through), the `QueryException` is caught, the transaction rolled
 * back, and `DuplicateRenewalPeriodException` thrown in its place.
 *
 * The constraint's unique index name (`renewals_grave_period_unique`) is
 * PostgreSQL-specific. SQLite uses a different naming scheme for inline
 * uniqueness, so the catch is generic — it matches any `23005` SQLSTATE
 * (integrity constraint violation) rather than a specific index name.
 */
final readonly class OpenRenewal
{
    public function __invoke(GraveRecord $grave): Renewal
    {
        try {
            return DB::transaction(function () use ($grave): Renewal {
                $quote = app(QuoteRenewal::class)($grave);

                return $quote->renewal;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' || $e->getCode() === '23505') {
                throw DuplicateRenewalPeriodException::forGravePeriod(
                    $grave->id,
                    $grave->due_date->toDateString()
                );
            }

            throw $e;
        }
    }
}
