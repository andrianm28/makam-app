<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Models;

use App\Platform\FinancialLedger\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `renewal_quotes` —
 * `.kiro/specs/renewal-and-grave-registry/design.md`'s Data section, backing
 * AC6 (tariff quote) and AC7 (late fine). A `Renewal` may accumulate more
 * than one quote row over its lifetime (a re-quote after the tariff
 * changes), which is why `Renewal::quotes()` is `HasMany` — nothing in this
 * task decides which quote is "current"; that read-time question belongs to
 * `App\Domain\Renewal\Actions\QuoteRenewal`, a later task in this lane.
 *
 * `amount_minor` (and `late_fine_minor`) are plain integer minor-unit
 * columns, never decimal or float — `App\Platform\FinancialLedger\Money`'s
 * own doc block: "No float input, property, or arithmetic is permitted on
 * this type." `amountAsMoney()` is the read-time seam a later task
 * (`GuardRenewalPaymentOpening`, Task 5) uses instead of reading the integer
 * column directly.
 *
 * `tariff_source` / `tariff_effective_at` / `tariff_source_updated_at`
 * record TARIFF provenance — deliberately not the same field as
 * `grave_records.source` / `source_updated_at`, which record the REGISTRY
 * row's provenance. `App\Domain\GraveRegistry\GraveRecordSource`'s own doc
 * block flags this exact naming collision risk.
 */
final class RenewalQuote extends Model
{
    use HasUuids;

    protected $table = 'renewal_quotes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'renewal_id',
        'amount_minor',
        'currency',
        'tariff_source',
        'tariff_effective_at',
        'tariff_source_updated_at',
        'late_fine_minor',
        'late_fine_basis',
        'accepted_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'tariff_effective_at' => 'immutable_datetime',
            'tariff_source_updated_at' => 'immutable_datetime',
            'late_fine_minor' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Renewal, $this>
     */
    public function renewal(): BelongsTo
    {
        return $this->belongsTo(Renewal::class, 'renewal_id');
    }

    /**
     * The read-time seam a caller uses instead of `$quote->amount_minor`
     * directly — see this class's own doc block. `Money`'s constructor
     * already asserts the value is a genuine `int`, so a corrupted column
     * fails loudly here rather than silently propagating.
     *
     * Deliberately NO `(int)` cast on `$this->amount_minor` (fix round 1,
     * F5) — casting here would be exactly the "weak caller" `Money`'s own
     * constructor doc block warns about: it truncates a float or coerces a
     * numeric string BEFORE `Money::__construct()`'s `is_int()` assertion
     * can see it, which defeats the loud failure this method's own comment
     * claims to provide. `amount_minor` is cast `'integer'` above, so the
     * ordinary path is unaffected; this only changes what happens when the
     * column is corrupted.
     */
    public function amountAsMoney(): Money
    {
        return new Money($this->amount_minor);
    }

    /**
     * `true` when this quote was accepted AND has not expired — the two
     * conditions `Actions\GuardRenewalPaymentOpening` (Task 5) needs before
     * it will let a payment session open against this quote. `accepted_at`
     * being set is not enough on its own: an accepted quote can still go
     * stale if the family stalls past `expires_at`.
     */
    public function isAcceptedAndUnexpired(): bool
    {
        if ($this->accepted_at === null) {
            return false;
        }

        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->isFuture();
    }
}
