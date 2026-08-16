<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Models;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException;
use App\Domain\PreNeed\PreNeedCaseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `pre_need_cases` — see
 * `2026_08_16_120000_create_pre_need_cases_table.php` for the schema, the
 * plan-gap-fill columns (`agreement_id`, `accepted_by_ref`,
 * `accepted_quote_id`, `settled_paid_source_ref`), and the delete
 * discipline.
 *
 * The paid Pre-Need flow's coordination aggregate: the status moves along
 * `PreNeedCaseStatus`'s chain ONLY through the seven paid-flow Actions
 * (each asserts `status()->assertAllows()` under the case-row lock), and
 * the case keeps its full history.
 *
 * ---------------------------------------------------------------------------
 * The pre-need ORDER resolution — the submit-time chain
 * ---------------------------------------------------------------------------
 * `SubmitBookingDraft` (Task 3 of the platform-order-orchestration plan)
 * creates the PRE_NEED order with `pre_need_case_id` = the INTEREST id and
 * `booking_draft_id` = the draft's id; the interest row carries the same
 * `booking_draft_id`. The chain is therefore:
 *
 *     case -> interest (`pre_need_interest_id`) -> `booking_draft_id`
 *           -> the order whose `booking_draft_id` matches.
 *
 * `order()` resolves exactly that chain, with one disambiguator: the
 * order's `pre_need_case_id` must equal the case's `pre_need_interest_id`.
 * A draft CAN be submitted more than once with different idempotency keys
 * (each submission is a distinct order + interest), so the draft-link
 * alone could match several orders; the `pre_need_case_id` tie — which
 * `SubmitBookingDraft` writes at creation time, in the same insert — pins
 * the lookup to the order THIS interest belongs to. `null` is returned,
 * not thrown, and every order-dependent action refuses honestly on it
 * (`IllegalPreNeedCaseTransitionException::missingOrder()`).
 */
final class PreNeedCase extends Model
{
    use HasUuids;

    protected $table = 'pre_need_cases';

    protected $keyType = 'string';

    /**
     * The plan's reference set plus the three plan-gap-fill columns the
     * Task 3 brief pins (see the migration's class doc block).
     *
     * @var list<string>
     */
    protected $fillable = [
        'pre_need_interest_id',
        'status',
        'cemetery_id',
        'cemetery_package_id',
        'agreement_id',
        'quote_id',
        'plot_reservation_id',
        'activated_funeral_case_id',
        'accepted_by_ref',
        'accepted_quote_id',
        'settled_paid_source_ref',
    ];

    public function status(): PreNeedCaseStatus
    {
        return PreNeedCaseStatus::from($this->status);
    }

    /**
     * @return BelongsTo<PreNeedInterest, $this>
     */
    public function interest(): BelongsTo
    {
        return $this->belongsTo(PreNeedInterest::class, 'pre_need_interest_id');
    }

    /**
     * The pre-need order behind this case — see the class doc block for
     * the submit-time chain and the `pre_need_case_id` disambiguator.
     */
    public function order(): ?Order
    {
        $interest = $this->interest;

        if ($interest === null || $interest->booking_draft_id === null) {
            return null;
        }

        return Order::query()
            ->where('booking_draft_id', $interest->booking_draft_id)
            ->where('pre_need_case_id', $this->pre_need_interest_id)
            ->first();
    }

    /**
     * The case keeps its full history — always throws (see the migration's
     * doc block and `IllegalPreNeedCaseTransitionException::forDelete()`).
     */
    public function delete(): ?bool
    {
        throw IllegalPreNeedCaseTransitionException::forDelete();
    }
}
