<?php

declare(strict_types=1);

namespace App\Platform\Payment\Models;

use App\Platform\Payment\Exceptions\PaymentIntentIsImmutableException;
use App\Platform\Payment\PaymentIntentDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `payment_intents` — see
 * `2026_08_09_100000_create_payment_intents_table.php` for the schema
 * reasoning, including which columns are deliberately absent.
 *
 * ONLY ever written by `App\Platform\Payment\GuardPaymentSession`, which is
 * the single place a guard evaluation is recorded together with its
 * `PAYMENT_GUARD_DENIED` audit event in one transaction. Constructing a row
 * anywhere else produces a decision record for a decision nothing took.
 *
 * ---------------------------------------------------------------------------
 * Append-only: what the overrides below do and do NOT stop
 * ---------------------------------------------------------------------------
 * Exactly the same accounting as `App\Platform\Audit\Models\AuditEvent`, and
 * for the same underlying reason (finding N-1: this project has one Postgres
 * role per environment, which both owns the schema and runs the application,
 * so there is no lower-privileged role to REVOKE UPDATE/DELETE from).
 *
 * These overrides stop `$intent->update([...])`, `$intent->save()` on an
 * already-persisted instance, and `$intent->delete()`.
 *
 * They do NOT stop `PaymentIntent::query()->update([...])`,
 * `DB::table('payment_intents')->update(...)`, raw SQL, or any process with
 * direct database credentials — those never pass through this class. Stated
 * plainly rather than assumed closed.
 */
final class PaymentIntent extends Model
{
    use HasUuids;

    protected $table = 'payment_intents';

    /**
     * No `updated_at`: a decision record is never revised, and the column's
     * existence alone would suggest otherwise. `evaluated_at` is set
     * explicitly by the guard.
     */
    public $timestamps = false;

    /**
     * `id` is deliberately not fillable — `HasUuids` generates it, and no
     * caller should choose a decision record's identifier. Same reasoning as
     * `App\Domain\Booking\Models\BookingDraft`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'requested_amount_minor',
        'currency',
        'payment_mode',
        'decision',
        'denied_condition',
        'denial_reason',
        'missing_upstream',
        'denied_conditions',
        'public_message',
        'actor_ref',
        'actor_role',
        'correlation_id',
        'evaluated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `integer`, never `float`/`decimal` — Wave 0 ruling 0c. A
            // decimal cast would return a string that arithmetic silently
            // coerces to float; an integer cast keeps minor units integral
            // all the way from the column to `Money`.
            'requested_amount_minor' => 'integer',
            'denied_conditions' => 'array',
            'evaluated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $intent): void {
            // The decision column is CHECK-constrained on Postgres; this
            // makes the same closed list real on SQLite (the test default)
            // and fails at the model rather than at the driver.
            if (! in_array($intent->decision, PaymentIntentDecision::values(), true)) {
                throw PaymentIntentIsImmutableException::forOperation(
                    "decision [{$intent->decision}] is not one of: ".implode(', ', PaymentIntentDecision::values())
                );
            }
        });
    }

    /**
     * Always throws — see the class-level doc block.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw PaymentIntentIsImmutableException::forOperation('update');
    }

    /**
     * Always throws. Blocks `$intent->decision = ...; $intent->save();` on
     * an already-persisted instance, which routes here rather than through
     * `update()`.
     */
    protected function performUpdate(Builder $query): bool
    {
        throw PaymentIntentIsImmutableException::forOperation('performUpdate');
    }

    /**
     * Always throws — see the class-level doc block.
     */
    public function delete(): ?bool
    {
        throw PaymentIntentIsImmutableException::forOperation('delete');
    }
}
