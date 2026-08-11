<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Payment\Actions\Concerns\DetectsDuplicatePaymentReversal;
use App\Platform\Payment\Models\PaymentReversal;
use App\Platform\Payment\PaymentReversalType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `DetectsDuplicatePaymentReversal::isDuplicateReversal()` — fix round 1
 * (task-6 scoped re-review, IMPORTANT-1).
 *
 * The scoped reviewer proved the ORIGINAL detection logic
 * (`str_contains($message, 'reversal_type') && str_contains($message,
 * 'reference')`) was an over-broad false-positive generator: it matched ANY
 * `QueryException` on the `payment_reversals` INSERT, not just a genuine
 * `(reversal_type, reference)` unique-constraint violation, because
 * `QueryException::formatMessage()` always appends the full INSERT
 * statement text (which names both columns) regardless of what actually
 * failed. The reviewer's executed counter-example forced a PRIMARY KEY
 * collision — a completely unrelated failure, with DIFFERENT
 * `reversal_type`/`reference` values on the colliding rows — and the old
 * logic still classified it as a duplicate reversal.
 *
 * This test reproduces that exact counter-example directly against the
 * trait (bypassing `RecordRefund`/`RecordChargeback` entirely, since the
 * bug lives in the trait's own message-matching, not in either Action) and
 * pins both directions: an unrelated constraint violation must NOT be
 * classified as a duplicate, and a genuine duplicate still must be.
 */
final class DetectsDuplicatePaymentReversalTest extends TestCase
{
    use RefreshDatabase;

    private function detector(): object
    {
        return new class
        {
            use DetectsDuplicatePaymentReversal;

            public function isDuplicate(QueryException $exception): bool
            {
                return $this->isDuplicateReversal($exception);
            }
        };
    }

    public function test_an_unrelated_primary_key_collision_is_not_classified_as_a_duplicate_reversal(): void
    {
        $collidingId = (string) Str::uuid();

        DB::table('payment_reversals')->insert([
            'id' => $collidingId,
            'reversal_type' => PaymentReversalType::Refund->value,
            'reference' => 'PK-COLLISION-A',
            'amount_minor' => null,
            'reason' => 'first row',
            'recorded_by_actor_ref' => null,
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            // Same id, DIFFERENT reversal_type and reference — proves the
            // failure has nothing to do with the (reversal_type, reference)
            // unique constraint, only with the reused primary key.
            DB::table('payment_reversals')->insert([
                'id' => $collidingId,
                'reversal_type' => PaymentReversalType::Chargeback->value,
                'reference' => 'PK-COLLISION-B',
                'amount_minor' => null,
                'reason' => 'second row, different type and reference',
                'recorded_by_actor_ref' => null,
                'recorded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail('Expected a primary-key collision QueryException.');
        } catch (QueryException $exception) {
            $this->assertFalse(
                $this->detector()->isDuplicate($exception),
                'A primary-key collision must not be misclassified as a (reversal_type, reference) duplicate. '
                .'Actual message: '.$exception->getMessage(),
            );
        }
    }

    public function test_a_genuine_reversal_type_reference_duplicate_is_still_classified_correctly(): void
    {
        PaymentReversal::createRecorded([
            'reversal_type' => PaymentReversalType::Refund->value,
            'reference' => 'GENUINE-DUP',
            'amount_minor' => null,
            'reason' => 'first',
            'recorded_by_actor_ref' => null,
        ]);

        try {
            PaymentReversal::createRecorded([
                'reversal_type' => PaymentReversalType::Refund->value,
                'reference' => 'GENUINE-DUP',
                'amount_minor' => null,
                'reason' => 'second, same type and reference',
                'recorded_by_actor_ref' => null,
            ]);

            $this->fail('Expected a (reversal_type, reference) unique-constraint QueryException.');
        } catch (QueryException $exception) {
            $this->assertTrue(
                $this->detector()->isDuplicate($exception),
                'A genuine (reversal_type, reference) duplicate must still be classified as a duplicate. '
                .'Actual message: '.$exception->getMessage(),
            );
        }
    }
}
