<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\RefundObligation;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\RefundObligation\RefundObligationStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The database — not the Action — refuses a closed obligation with no
 * evidence.
 *
 * ---------------------------------------------------------------------------
 * Why this test exists, stated plainly because the gap it closes was real
 * ---------------------------------------------------------------------------
 * The refund plan's central invariant is that *nothing closes an obligation
 * except a recorded execution WITH ITS EVIDENCE — not an admin marking it
 * done, not expiry*. The first revision of this table's
 * `refund_obligations_status_stamps_check` constrained only `executed_at`
 * and `confirmed_at`. Those are TIMESTAMPS. They say an execution was
 * stamped; they say nothing about whether a transfer reference or a proof
 * of transfer was ever recorded.
 *
 * So `status = 'DIEKSEKUSI'` with both evidence columns NULL was a legal row,
 * and the whole invariant rested on one `if` in one Action. Stage R2's
 * mutation M1 demonstrated it: with that check removed, an evidence-free
 * execution committed successfully.
 *
 * Every assertion below therefore writes through the QUERY BUILDER, never
 * through the Action or the model. The Action having a guard is not in
 * question — this is about what survives when the Action is bypassed,
 * refactored, or wrong.
 *
 * ---------------------------------------------------------------------------
 * PostgreSQL only, and it FAILS rather than skips in CI
 * ---------------------------------------------------------------------------
 * The constraint is added by a `DB::statement()` the migration runs only on
 * `pgsql`, so on any other driver there is nothing to test and these
 * assertions would pass vacuously — the exact shape of a test that cannot
 * fail. On a non-Postgres driver each test skips with the reason stated;
 * under `CI=true` a skip is escalated to a failure, because CI runs
 * PostgreSQL 18 and a skip there means the premise stopped holding rather
 * than that the driver changed.
 */
final class RefundObligationEvidenceConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $message = 'refund_obligations CHECK constraints exist only on pgsql; '
                .'this driver is '.DB::connection()->getDriverName().'.';

            // `getenv()`, not Laravel's `env()` helper: PHPStan forbids the
            // helper outside `config/` because it returns null once the
            // config is cached, and this needs the raw process environment
            // the CI runner actually sets.
            if (in_array(getenv('CI'), ['1', 'true', 'TRUE'], true)) {
                $this->fail($message.' CI runs PostgreSQL 18, so this skip is a defect, not a driver difference.');
            }

            $this->markTestSkipped($message);
        }
    }

    private function orderId(): string
    {
        $order = Order::query()->create([
            'reference' => 'MK-EVID-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIBAYAR->value,
        ]);

        return (string) $order->getKey();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid7(),
            'order_id' => $this->orderId(),
            'payment_session_id' => null,
            'amount_minor' => 165_000_00,
            'currency' => 'IDR',
            'status' => RefundObligationStatus::TERUTANG->value,
            'due_at' => now()->addDays(3),
            'opened_at' => now(),
            'opened_by_actor_ref' => 'test',
            'opened_reason' => 'Pesanan terbayar ditolak dalam uji.',
            'executed_at' => null,
            'executed_by_actor_ref' => null,
            'execution_reference' => null,
            'execution_evidence_path' => null,
            'confirmed_at' => null,
            'confirmed_by_actor_ref' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    public function test_an_executed_obligation_without_any_evidence_is_refused_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        DB::table('refund_obligations')->insert($this->row([
            'status' => RefundObligationStatus::DIEKSEKUSI->value,
            'executed_at' => now(),
            'executed_by_actor_ref' => 'admin',
            // Both evidence columns left NULL — the state the first revision
            // of the constraint permitted.
        ]));
    }

    public function test_an_executed_obligation_with_a_reference_but_no_proof_is_refused(): void
    {
        $this->expectException(QueryException::class);

        DB::table('refund_obligations')->insert($this->row([
            'status' => RefundObligationStatus::DIEKSEKUSI->value,
            'executed_at' => now(),
            'executed_by_actor_ref' => 'admin',
            'execution_reference' => 'TRF-0001',
            // A reference an operator typed is a claim; the proof is what
            // makes it checkable. Half the evidence is not the evidence.
        ]));
    }

    public function test_a_confirmed_obligation_without_evidence_is_refused(): void
    {
        $this->expectException(QueryException::class);

        DB::table('refund_obligations')->insert($this->row([
            'status' => RefundObligationStatus::TERKONFIRMASI->value,
            'executed_at' => now(),
            'confirmed_at' => now(),
            'confirmed_by_actor_ref' => 'admin',
        ]));
    }

    public function test_an_outstanding_obligation_carrying_evidence_is_refused(): void
    {
        $this->expectException(QueryException::class);

        DB::table('refund_obligations')->insert($this->row([
            // Still TERUTANG, yet claiming a transfer happened. Proof of a
            // transfer that has not happened is not a state this ledger has
            // a meaning for.
            'execution_reference' => 'TRF-0002',
            'execution_evidence_path' => 'refund-evidence/x.pdf',
        ]));
    }

    public function test_a_fully_evidenced_execution_is_accepted(): void
    {
        DB::table('refund_obligations')->insert($this->row([
            'status' => RefundObligationStatus::DIEKSEKUSI->value,
            'executed_at' => now(),
            'executed_by_actor_ref' => 'admin',
            'execution_reference' => 'TRF-0003',
            'execution_evidence_path' => 'refund-evidence/proof.pdf',
        ]));

        $this->assertDatabaseCount('refund_obligations', 1);
    }
}
