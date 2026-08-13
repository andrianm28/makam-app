<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only online write path for a renewal — AC11 at the seam.
 *
 * ---------------------------------------------------------------------------
 * Reached only from an explicit acceptance, never from a page load
 * ---------------------------------------------------------------------------
 * `Actions\QuoteRenewal` calculates without writing; this Action writes. The
 * split exists because step 4 is an anonymous, bookmarkable GET, and the
 * screen that renders a quote must not be the thing that persists one. The
 * caller is `Livewire\Public\Renewal\RenewalFee::terimaDanLanjutkan()` — a
 * Livewire action, i.e. a POST the family triggers by accepting the quoted
 * figure, which is also what makes `accepted_at` below a true statement.
 *
 * ---------------------------------------------------------------------------
 * Why acceptance is stamped here
 * ---------------------------------------------------------------------------
 * `AGENTS.md` §Domain and financial invariants: "Never create payment before
 * an accepted quote." Step 5's `Actions\GuardRenewalPaymentOpening` enforces
 * that by requiring `RenewalQuote::isAcceptedAndUnexpired()`. The family's
 * acceptance IS the act that reaches this Action, so `accepted_at` is stamped
 * in the same transaction that persists the quote — the guard then reads a
 * fact, not an assumption.
 *
 * `expires_at` is deliberately left null. A quote validity window is an
 * operator policy figure that no document in this repository states, and
 * `isAcceptedAndUnexpired()` already treats a null expiry as "does not
 * expire". Inventing a duration here would be the same class of fabrication
 * AC7 forbids for the late fine.
 *
 * ---------------------------------------------------------------------------
 * The AC11 guard is the database constraint, not an application pre-check
 * ---------------------------------------------------------------------------
 * "Does a row already exist?" is a race, not a guard: two concurrent requests
 * could both pass the check and both insert, defeating the invariant. The only
 * correct approach is to attempt the write and catch the constraint violation
 * — which is what this Action does. `Actions\MarkExternalRenewal` catches the
 * same violation for the admin path, so both writers into the shared
 * uniqueness domain fail with the same typed exception.
 *
 * The constraint's index name (`renewals_grave_period_unique`) is
 * PostgreSQL-specific and SQLite names inline uniqueness differently, so the
 * catch matches the SQLSTATE integrity-violation codes rather than a name.
 */
final readonly class OpenRenewal
{
    /**
     * @throws DuplicateRenewalPeriodException when a renewal already claims
     *                                         this grave and period, from
     *                                         either the online or the
     *                                         external source.
     * @throws \InvalidArgumentException when no attributable tariff source
     *                                   exists — see `Actions\QuoteRenewal`.
     */
    public function __invoke(GraveRecord $grave): Renewal
    {
        $draft = app(QuoteRenewal::class)($grave);

        try {
            return DB::transaction(function () use ($grave, $draft): Renewal {
                $renewal = Renewal::create([
                    'grave_record_id' => $grave->id,
                    'target_due_period' => $grave->due_date,
                    'reference' => 'PPJ-'.Str::uuid()->toString(),
                    'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
                    'source' => RenewalSource::ONLINE,
                ]);

                RenewalQuote::create([
                    'renewal_id' => $renewal->id,
                    'amount_minor' => $draft->amountMinor,
                    'currency' => $draft->currency,
                    'tariff_source' => $draft->tariffSource,
                    'tariff_effective_at' => $draft->tariffEffectiveAt,
                    'tariff_source_updated_at' => now(),
                    'late_fine_minor' => $draft->lateFineMinor,
                    'late_fine_basis' => $draft->lateFineBasis,
                    'accepted_at' => now(),
                    'expires_at' => null,
                ]);

                return $renewal;
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
