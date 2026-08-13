<?php

declare(strict_types=1);

namespace App\Domain\FuneralCase\Models;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\FuneralCase\Exceptions\IllegalFuneralCaseTransitionException;
use App\Domain\FuneralCase\FuneralCaseStatus;
use App\Domain\FuneralCase\FuneralCaseUrgency;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `funeral_cases` — see
 * `2026_08_12_100016_create_funeral_cases_table.php` for the schema and the
 * reasoning behind its deliberate omissions.
 *
 * Created only by `App\Domain\FuneralCase\Actions\OpenFuneralCase`.
 *
 * ---------------------------------------------------------------------------
 * Why this model has NO write guard, unlike `Order`
 * ---------------------------------------------------------------------------
 * `Order` overrides `update()`/`performUpdate()`/`delete()` because
 * `orders.status` is money-bearing: reaching `DIBAYAR` without an
 * `order_status_events` row is a financial defect with no database backstop.
 * `funeral_cases.status` is an OPERATIONAL status. Moving it wrongly is a
 * coordination error, correctable by moving it again, and it settles no
 * money and releases no unique index. Copying `Order`'s guard here would be
 * cargo-culting a control whose justification does not transfer, and would
 * make the guard look like a house style rather than the specific,
 * argued-for protection it is on the one table that needs it.
 *
 * What this model DOES enforce is the transition graph, in `advanceTo()`,
 * so a case cannot skip from `NEW` to `COMPLETED`.
 */
final class FuneralCase extends Model
{
    use HasUuids;

    protected $table = 'funeral_cases';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status',
        'urgency',
        'service_area',
        'case_manager_ref',
        'first_response_due_at',
        'service_due_at',
        'booking_draft_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_response_due_at' => 'immutable_datetime',
            'service_due_at' => 'immutable_datetime',
        ];
    }

    public function status(): FuneralCaseStatus
    {
        return FuneralCaseStatus::from($this->status);
    }

    public function urgency(): FuneralCaseUrgency
    {
        return FuneralCaseUrgency::from($this->urgency);
    }

    /**
     * The one way a case status moves. Asserts the operational graph
     * (`FuneralCaseStatus::allowedNext()`), which is a DIFFERENT graph from
     * `App\Domain\OrderWorkflow\OrderTransition` and shares no state with
     * it — moving a case never reads, writes, or consults an order.
     *
     * @throws IllegalFuneralCaseTransitionException
     */
    public function advanceTo(FuneralCaseStatus $to): void
    {
        $this->status()->assertAllows($to);

        $this->status = $to->value;
        $this->save();
    }

    /**
     * @return BelongsTo<BookingDraft, $this>
     */
    public function bookingDraft(): BelongsTo
    {
        return $this->belongsTo(BookingDraft::class, 'booking_draft_id');
    }
}
