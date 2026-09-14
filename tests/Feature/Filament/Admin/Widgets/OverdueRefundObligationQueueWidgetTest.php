<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\RefundObligation\RefundObligationStatus;
use App\Filament\Admin\Widgets\OverdueRefundObligationQueueWidget;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The dashboard half of refund plan R4.
 *
 * ---------------------------------------------------------------------------
 * The assertion that matters most is the one about ABSENCE
 * ---------------------------------------------------------------------------
 * A widget that renders every day, usually empty, is wallpaper — and an
 * operator who learns to scroll past it will scroll past it on the day it
 * finally has something. So `canView()` is false when nothing is urgent, and
 * `test_it_stays_hidden_when_no_obligation_is_urgent` is what stops a future
 * "simplification" from making it always-on.
 *
 * The authorization tests are the other half: a widget must never be the soft
 * way into data its resource guards. `RefundObligationsResource` gates on
 * `PaymentActionAuthorizer`, and so does this.
 */
final class OverdueRefundObligationQueueWidgetTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    /**
     * A real granted role on a real actor, through `GrantActorRole` — the
     * only write path to `actor_role_assignments`. A fabricated
     * `ActorContext` would prove the authorizer's `in_array()` works; this
     * proves the whole chain does, which is the only version that can fail
     * when the chain breaks.
     */
    private function actAsFinance(): void
    {
        $user = User::factory()->create();

        $this->grantRoleTo($user, ActorRole::FINANCE);

        $this->actingAs($user);
        $this->forgetResolvedActorContext();
    }

    private function obligation(string $dueAt, string $status = 'TERUTANG'): void
    {
        $order = Order::query()->create([
            'reference' => 'MK-W4-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIBAYAR->value,
        ]);

        $executed = $status === RefundObligationStatus::TERUTANG->value ? null : now();

        DB::table('refund_obligations')->insert([
            'id' => (string) Str::uuid7(),
            'order_id' => $order->getKey(),
            'payment_session_id' => null,
            'amount_minor' => 165_000_00,
            'currency' => 'IDR',
            'status' => $status,
            'due_at' => $dueAt,
            'opened_at' => now()->subDay(),
            'opened_by_actor_ref' => 'test',
            'opened_reason' => 'Pesanan terbayar ditolak dalam uji widget R4.',
            'executed_at' => $executed,
            'executed_by_actor_ref' => $executed === null ? null : 'admin',
            'execution_reference' => $executed === null ? null : 'TRF-W4',
            'execution_evidence_path' => $executed === null ? null : 'refund-evidence/w4.pdf',
            'confirmed_at' => null,
            'confirmed_by_actor_ref' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_stays_hidden_when_no_obligation_is_urgent(): void
    {
        $this->actAsFinance();

        $this->obligation(now()->addDays(5)->toDateTimeString());

        $this->assertFalse(
            OverdueRefundObligationQueueWidget::canView(),
            'A widget that renders while nothing is urgent becomes wallpaper, and an operator '
            .'who learns to scroll past it will scroll past it on the day it matters.',
        );
    }

    public function test_it_stays_hidden_when_there_is_no_obligation_at_all(): void
    {
        $this->actAsFinance();

        $this->assertFalse(OverdueRefundObligationQueueWidget::canView());
    }

    public function test_an_executed_obligation_past_its_deadline_does_not_summon_it(): void
    {
        $this->actAsFinance();

        $this->obligation(
            now()->subDays(5)->toDateTimeString(),
            RefundObligationStatus::DIEKSEKUSI->value,
        );

        $this->assertFalse(
            OverdueRefundObligationQueueWidget::canView(),
            'An executed obligation has had its money sent and its evidence recorded; it is not '
            .'late whatever its deadline says.',
        );
    }

    /**
     * Both urgent states summon it — the one that has already failed, and the
     * one that still can be saved. The second is the point: execution is two
     * manual bank movements and the first settles on the provider's clock, so
     * a debt that first appears here on its deadline appears too late.
     */
    public function test_an_overdue_obligation_summons_it(): void
    {
        $this->actAsFinance();

        $this->obligation(now()->subDays(2)->toDateTimeString());

        $this->assertTrue(OverdueRefundObligationQueueWidget::canView());
    }

    /**
     * The authorization gate, tested separately and deliberately LAST.
     *
     * Every test above runs with the gate already open, so what they measure
     * is urgency. Without that, all five would pass for the wrong reason —
     * an unauthenticated test actor makes `canView()` false no matter what
     * the deadlines say, and "hidden because nothing is urgent" would be
     * indistinguishable from "hidden because nobody is logged in". This test
     * is the one that closes that hole from the other side.
     */
    public function test_an_actor_without_payment_authority_never_sees_it(): void
    {
        $this->obligation(now()->subDays(2)->toDateTimeString());

        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();

        $this->assertFalse(
            OverdueRefundObligationQueueWidget::canView(),
            'A widget must never be the soft way into data its resource guards.',
        );
    }

    public function test_an_obligation_falling_due_soon_summons_it(): void
    {
        $this->actAsFinance();

        $this->obligation(now()->addHours(6)->toDateTimeString());

        $this->assertTrue(OverdueRefundObligationQueueWidget::canView());
    }
}
