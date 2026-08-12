<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Produces a tariff quote for a grave renewal — AC6 (tariff amount + source
 * + effective time) and AC7 (no invented late fine).
 *
 * The quote is grounded in the cemetery's own price range (`price_min`,
 * `price_source`, `price_effective_at`) — the only attributed price data
 * that exists in this system for cemetery services. Per the coordinator's
 * ruling (2026-08-12 handoff): `price_min` → `amount_minor`,
 * `price_source` → `tariff_source`, `price_effective_at` →
 * `tariff_effective_at`. A null `price_min` means no attributable tariff
 * source exists; the plan's own rule is that a quote without a source is not
 * a quote, so this Action throws rather than emit an unattributed figure.
 *
 * AC7's late-fine refusal is a gate read, not a calculation. `G-RATE-01`'s
 * documented closed behavior is literally "No invented fine." When no written
 * operator basis exists, `late_fine_minor` and `late_fine_basis` stay null.
 *
 * One `Renewal` is created alongside the quote — the quote must belong to a
 * renewal (the `renewal_id` column is NOT NULL), and the renewal must exist
 * before the quote. The `Renewal` is created in the same transaction so both
 * commit or roll back together.
 */
final readonly class QuoteRenewal
{
    /**
     * @throws \InvalidArgumentException when the grave's cemetery has no
     *                                   attributable tariff source (`price_min`
     *                                   is null).
     */
    public function __invoke(GraveRecord $grave): RenewalQuote
    {
        $cemetery = $grave->cemetery;

        if (! $cemetery instanceof Cemetery) {
            throw new \InvalidArgumentException(
                'Cannot produce a renewal quote: grave has no associated cemetery.'
            );
        }

        if ($cemetery->price_min === null) {
            throw new \InvalidArgumentException(
                'Cannot produce a renewal quote: no attributable tariff source exists for this grave.'
            );
        }

        return DB::transaction(function () use ($cemetery, $grave): RenewalQuote {
            $renewal = Renewal::create([
                'grave_record_id' => $grave->id,
                'target_due_period' => $grave->due_date,
                'reference' => 'PPJ-'.Str::uuid()->toString(),
                'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
                'source' => RenewalSource::ONLINE,
            ]);

            return RenewalQuote::create([
                'renewal_id' => $renewal->id,
                'amount_minor' => (int) $cemetery->price_min,
                'currency' => 'IDR',
                'tariff_source' => $cemetery->price_source,
                'tariff_effective_at' => $cemetery->price_effective_at,
                'tariff_source_updated_at' => now(),
                'late_fine_minor' => null,
                'late_fine_basis' => null,
            ]);
        });
    }
}
