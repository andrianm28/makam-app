<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Models;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\PreNeed\Exceptions\IllegalPreNeedInterestTransitionException;
use App\Domain\PreNeed\PreNeedInterestStatus;
use App\Platform\FeatureGate\Modes\PreNeedMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `pre_need_interests` — see
 * `2026_08_12_100018_create_pre_need_interests_table.php` for the schema,
 * for why the table holds nothing financial, and for why the linking column
 * on `orders` is named `pre_need_case_id` rather than
 * `pre_need_interest_id`.
 *
 * Created only by `App\Domain\PreNeed\Actions\RegisterPreNeedInterest`.
 *
 * No write guard, for the reason `App\Domain\FuneralCase\Models\FuneralCase`
 * sets out: this status settles no money and releases no unique index.
 */
final class PreNeedInterest extends Model
{
    use HasUuids;

    protected $table = 'pre_need_interests';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status',
        'gate_mode',
        'service_area',
        'contacted_at',
        'closed_at',
        'booking_draft_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contacted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function status(): PreNeedInterestStatus
    {
        return PreNeedInterestStatus::from($this->status);
    }

    /**
     * The `PreNeedMode` this row was registered under, as resolved
     * SERVER-SIDE from `G-LEGAL-01` at registration time — never
     * re-derived on read, because the gate can be opened afterwards and
     * "what was this created under?" must stay answerable.
     */
    public function gateMode(): PreNeedMode
    {
        return PreNeedMode::from($this->gate_mode);
    }

    /**
     * `INTEREST_REGISTERED -> CONTACTED -> CLOSED`, with the timestamps kept
     * consistent with the status in the same write so the two can never
     * disagree.
     *
     * @throws IllegalPreNeedInterestTransitionException
     */
    public function advanceTo(PreNeedInterestStatus $to): void
    {
        $this->status()->assertAllows($to);

        $this->status = $to->value;

        if ($to === PreNeedInterestStatus::CONTACTED) {
            $this->contacted_at = now();
        }

        if ($to === PreNeedInterestStatus::CLOSED) {
            $this->closed_at = now();
        }

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
