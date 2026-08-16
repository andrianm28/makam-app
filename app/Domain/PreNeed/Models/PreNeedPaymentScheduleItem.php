<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Models;

use App\Domain\PreNeed\PreNeedInstallmentState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `pre_need_payment_schedules` — see
 * `2026_08_16_120010_create_pre_need_payment_schedules_table.php` for the
 * schema.
 *
 * One row per installment of a pre-need case's payment schedule. Created
 * ONLY by `App\Domain\PreNeed\Actions\SchedulePreNeedPayments` (AC6: the
 * schedule is explicit and idempotent); the `(pre_need_case_id,
 * installment_number)` unique pair is the database backstop of that
 * idempotency. `state` moves along `PreNeedInstallmentState`
 * (`pending`/`paid`/`overdue`) on this lane only at creation (`pending`);
 * the later per-installment payment-link and delinquency steps own the
 * `paid`/`overdue` movements.
 */
final class PreNeedPaymentScheduleItem extends Model
{
    use HasUuids;

    protected $table = 'pre_need_payment_schedules';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pre_need_case_id',
        'installment_number',
        'amount_minor',
        'currency',
        'due_date',
        'state',
        'payment_session_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installment_number' => 'integer',
            'amount_minor' => 'integer',
            'due_date' => 'immutable_date',
        ];
    }

    public function state(): PreNeedInstallmentState
    {
        return PreNeedInstallmentState::from($this->state);
    }

    /**
     * @return BelongsTo<PreNeedCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(PreNeedCase::class, 'pre_need_case_id');
    }
}
