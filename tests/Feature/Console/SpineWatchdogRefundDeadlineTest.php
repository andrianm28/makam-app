<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\RefundObligation\RefundObligationDeadlineQuery;
use App\Domain\RefundObligation\RefundObligationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `spine:watchdog` signals 5 and 6 — refund debt running out of time.
 *
 * ---------------------------------------------------------------------------
 * Why the two signals are tested as DIFFERENT things
 * ---------------------------------------------------------------------------
 * It would be easy to treat "due soon" as a softer shade of "overdue" and
 * assert only that something was said. That would miss the point. The owner
 * confirmed (14 Sep 2026) that the provider supports withdraw-to-main-account
 * only, so executing a refund is two manual bank movements and the first
 * settles on the provider's clock. An operator who hears about a debt at its
 * deadline has already lost the time they needed.
 *
 * So the two signals demand two different actions — "start the withdraw" and
 * "this family has waited too long" — and the assertions below pin that
 * separation: each state produces its own message, and neither produces the
 * other's.
 *
 * Every message is asserted to carry counts and a deadline ONLY. A refund
 * obligation belongs to a bereaved family by definition, and
 * `SpineWatchdogCommand`'s own "Restricted data" section forbids more.
 */
final class SpineWatchdogRefundDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private function obligationDue(string $when, string $status = 'TERUTANG'): string
    {
        $order = Order::query()->create([
            'reference' => 'MK-R4-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIBAYAR->value,
        ]);

        $id = (string) Str::uuid7();
        $executed = $status === RefundObligationStatus::TERUTANG->value ? null : now();

        DB::table('refund_obligations')->insert([
            'id' => $id,
            'order_id' => $order->getKey(),
            'payment_session_id' => null,
            'amount_minor' => 165_000_00,
            'currency' => 'IDR',
            'status' => $status,
            'due_at' => $when,
            'opened_at' => now()->subDay(),
            'opened_by_actor_ref' => 'test',
            'opened_reason' => 'Pesanan terbayar ditolak dalam uji R4.',
            'executed_at' => $executed,
            'executed_by_actor_ref' => $executed === null ? null : 'admin',
            'execution_reference' => $executed === null ? null : 'TRF-R4',
            'execution_evidence_path' => $executed === null ? null : 'refund-evidence/r4.pdf',
            'confirmed_at' => null,
            'confirmed_by_actor_ref' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_no_refund_debt_leaves_the_spine_reported_healthy(): void
    {
        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Spine healthy')
            ->assertExitCode(0);
    }

    public function test_an_overdue_unpaid_obligation_is_reported_and_fails_the_run(): void
    {
        $this->obligationDue(now()->subDays(2)->toDateTimeString());

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('PAST their execution deadline')
            ->assertExitCode(1);
    }

    /**
     * The signal that actually prevents a missed deadline, and the reason it
     * exists rather than being folded into the one above.
     */
    public function test_an_obligation_falling_due_soon_is_reported_before_its_deadline(): void
    {
        $this->obligationDue(now()->addHours(6)->toDateTimeString());

        // One positive fragment and one negative, deliberately — not two
        // positives. Laravel matches `expectsOutputToContain` against the
        // output sequentially, so a second positive assertion searches only
        // what the first did not consume, and `error()` additionally wraps a
        // long message across terminal lines. A short fragment plus a proof
        // that the OTHER signal stayed silent is what actually distinguishes
        // the two states here.
        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('fall due within')
            ->doesntExpectOutputToContain('PAST their execution deadline')
            ->assertExitCode(1);
    }

    /**
     * A debt comfortably inside its window is not noise. If this failed, the
     * watchdog would cry wolf on every obligation from the moment it opened,
     * and an operator would learn to ignore signals 5 and 6 entirely.
     */
    public function test_an_obligation_far_from_its_deadline_is_not_flagged(): void
    {
        $this->obligationDue(now()->addDays(5)->toDateTimeString());

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Spine healthy')
            ->assertExitCode(0);
    }

    /**
     * The two states must not bleed into each other: an overdue debt is not
     * also announced as "due soon", and vice versa. Collapsing them would
     * lose the only message that is still actionable.
     */
    public function test_the_two_signals_do_not_report_each_other(): void
    {
        $this->obligationDue(now()->subDays(2)->toDateTimeString());

        $deadlines = app(RefundObligationDeadlineQuery::class);

        $this->assertSame(1, $deadlines->overdueCount());
        $this->assertSame(0, $deadlines->dueSoonCount());
    }

    /**
     * An executed obligation has had its money sent and its evidence
     * recorded. It is not late, whatever its `due_at` says — and a watchdog
     * that kept shouting about settled debts would be worse than silent.
     */
    public function test_an_executed_obligation_past_its_due_date_is_not_late(): void
    {
        $this->obligationDue(
            now()->subDays(5)->toDateTimeString(),
            RefundObligationStatus::DIEKSEKUSI->value,
        );

        $deadlines = app(RefundObligationDeadlineQuery::class);

        $this->assertSame(0, $deadlines->overdueCount());
        $this->assertSame(0, $deadlines->dueSoonCount());

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Spine healthy')
            ->assertExitCode(0);
    }

    /**
     * `AGENTS.md` §Observability: counts and durations only. An order
     * reference or an amount in an alert would travel to whatever error
     * tracker `report()` routes to.
     */
    public function test_the_alert_carries_no_order_reference_and_no_amount(): void
    {
        $this->obligationDue(now()->subDays(2)->toDateTimeString());

        $order = DB::table('orders')->orderByDesc('created_at')->first(['reference']);
        $this->assertNotNull($order);

        $this->artisan('spine:watchdog')
            ->doesntExpectOutputToContain((string) $order->reference)
            ->doesntExpectOutputToContain('165.000')
            ->doesntExpectOutputToContain('16500000')
            ->assertExitCode(1);
    }
}
