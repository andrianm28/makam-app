<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderInvoice;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\RefundObligation\Models\RefundObligation;
use App\Filament\Admin\Resources\BookingOrders\Actions\TransitionOrderAction;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class BookingOrderTransitionActionTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    public function test_operator_can_invoke_verify_transition(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::MASUK);
        TransitionOrderAction::make(OrderStatus::DIVERIFIKASI, $order)->call();

        $this->assertSame(OrderStatus::DIVERIFIKASI, $order->fresh()->status());
    }

    public function test_operator_cannot_invoke_money_transition(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::DISETUJUI_PEMESAN);
        $action = TransitionOrderAction::make(OrderStatus::MENUNGGU_PEMBAYARAN, $order);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_finance_money_transition_is_authorized(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::DISETUJUI_PEMESAN);
        $action = TransitionOrderAction::make(OrderStatus::MENUNGGU_PEMBAYARAN, $order);

        $this->assertTrue($action->isAuthorized());
    }

    /**
     * Stage R1 (13 Sep 2026). In the pay-first flow the customer's money has
     * ALREADY arrived by the time these two buttons are offered, so both
     * decide the fate of funds in hand — accepting makes a payment final,
     * refusing opens a refund debt with a deadline. Neither may sit behind
     * the weakest gate on this screen.
     */
    public function test_an_operator_cannot_confirm_or_refuse_a_paid_order(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI);

        $this->assertFalse(TransitionOrderAction::make(OrderStatus::DIKONFIRMASI, $order)->isAuthorized());
        $this->assertFalse(TransitionOrderAction::make(OrderStatus::DITOLAK_SETELAH_BAYAR, $order)->isAuthorized());
    }

    public function test_finance_may_confirm_or_refuse_a_paid_order(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI);

        $this->assertTrue(TransitionOrderAction::make(OrderStatus::DIKONFIRMASI, $order)->isAuthorized());
        $this->assertTrue(TransitionOrderAction::make(OrderStatus::DITOLAK_SETELAH_BAYAR, $order)->isAuthorized());
    }

    /**
     * An order reaches `DIBAYAR_MENUNGGU_KONFIRMASI` because money actually
     * landed — the payment-settlement path puts it there. A panel button
     * declaring "paid, awaiting confirmation" by hand would be a way to mark
     * an order paid with no payment behind it, which `AGENTS.md` §Domain and
     * financial invariants forbids. The status is absent from
     * `TRANSITION_NAME`, so the factory refuses it for EVERY role, admin
     * included.
     */
    public function test_no_role_can_hand_declare_an_order_paid_awaiting_confirmation(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::FINANCE, ActorRole::OPERATOR] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $order = $this->order(OrderStatus::MENUNGGU_PEMBAYARAN);

            $this->assertFalse(
                TransitionOrderAction::make(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI, $order)->isAuthorized(),
                "{$role} must not be able to hand-declare an order paid-awaiting-confirmation",
            );
        }
    }

    /**
     * The refusal needs a number as well as a decision, and the number comes
     * from the order's invoice. With no invoice it cannot be determined, and
     * the refusal must stop rather than proceed on a guess — an invented
     * refund amount is a money bug. A stuck order is visible and
     * recoverable; a wrong number in the ledger is neither.
     *
     * Every precondition that could stop this refusal is satisfied on
     * purpose, because "nothing changed" looks identical no matter WHICH
     * guard produced it — the vacuous-assertion trap this test is most
     * exposed to. The actor is given a fresh `lastAuthenticatedAt` so the
     * re-authentication gate cannot be the cause, and the reason is passed
     * under `data` (the key the action's own closure reads) so a blank-reason
     * rejection cannot be either. The notification body is then asserted, so
     * the test can only pass if the INVOICE check is what stopped it.
     */
    public function test_refusing_a_paid_order_with_no_invoice_changes_nothing(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->actAsReauthenticatedAdmin($user);

        $order = $this->order(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI);

        TransitionOrderAction::make(OrderStatus::DITOLAK_SETELAH_BAYAR, $order)
            ->call(['data' => ['reason' => 'petak tidak lagi tersedia']]);

        $this->assertSame(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI, $order->fresh()->status());
        $this->assertDatabaseCount('refund_obligations', 0);

        $notification = session()->get('filament.notifications', [])[0] ?? null;
        $this->assertNotNull($notification, 'The operator was told nothing at all.');
        $this->assertSame('Penolakan tidak dapat diproses', $notification['title']);
        $this->assertStringContainsString('faktur', (string) $notification['body']);
    }

    /**
     * The same refusal, with the one thing the previous test lacks: an
     * invoice saying what the customer was actually billed. This is the pair
     * that proves the previous test failed for the RIGHT reason — everything
     * else about the two is identical.
     */
    public function test_refusing_a_paid_order_uses_the_invoice_amount_for_the_refund_debt(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->actAsReauthenticatedAdmin($user);

        $order = $this->order(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI);

        OrderInvoice::query()->create([
            'order_id' => $order->getKey(),
            'reference' => 'INV-'.Str::upper(Str::random(10)),
            'amount_minor' => 4_250_000_00,
            'currency' => 'IDR',
            'summary' => 'Pemesanan makam',
            'issued_at' => CarbonImmutable::now(),
        ]);

        TransitionOrderAction::make(OrderStatus::DITOLAK_SETELAH_BAYAR, $order)
            ->call(['data' => ['reason' => 'petak tidak lagi tersedia']]);

        $this->assertSame(OrderStatus::DITOLAK_SETELAH_BAYAR, $order->fresh()->status());

        $obligation = RefundObligation::query()->where('order_id', $order->getKey())->sole();
        $this->assertSame(4_250_000_00, $obligation->amount_minor);
        $this->assertSame('IDR', $obligation->currency);
    }

    /**
     * `lastAuthenticatedAt` is resolved from the actor's session by the
     * identity adapter, which a Filament-less action call never populates.
     * Binding the context directly is the shape
     * `FinanceReportPanelTest::actAsActor()` already uses for the same
     * reason.
     */
    private function actAsReauthenticatedAdmin(User $user): void
    {
        $this->app->instance(ActorContext::class, new ActorContext(
            identityReference: (string) $user->getAuthIdentifier(),
            roles: [ActorRole::ADMIN],
            lastAuthenticatedAt: CarbonImmutable::now(),
        ));
    }

    public function test_a_cemetery_operator_cannot_transition_another_cemeterys_order(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $cemeteryB = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemeteryB->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = TransitionOrderAction::make(OrderStatus::DIVERIFIKASI, $order);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_a_cemetery_operator_can_transition_their_own_cemeterys_order(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemeteryA->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = TransitionOrderAction::make(OrderStatus::DIVERIFIKASI, $order);
        $action->call();

        $this->assertSame(OrderStatus::DIVERIFIKASI, $order->fresh()->status());

        // Phase A left this assertion pinned to the WRONG value
        // ('authenticated_actor') as a deliberate tripwire, with a comment
        // saying it must start failing the moment Phase C taught
        // `auditRoleFor()` about `cemetery_operator`. Phase C did exactly
        // that, so this now asserts the correct attribution. The flip is the
        // tripwire working as designed, not a regression.
        $event = OrderStatusEvent::query()
            ->where('order_id', $order->getKey())
            ->where('to_status', OrderStatus::DIVERIFIKASI->value)
            ->sole();
        $this->assertSame(ActorRole::CEMETERY_OPERATOR, $event->actor_role);
    }

    public function test_audit_role_prefers_the_platform_wide_role_for_a_dual_role_actor(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->assertSame(ActorRole::ADMIN, BookingOrderResource::auditRoleFor(app(ActorContext::class)));
    }

    public function test_audit_role_still_falls_through_for_an_actor_with_no_recognised_role(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CUSTOMER);
        $this->actingAs($user);

        $this->assertSame('authenticated_actor', BookingOrderResource::auditRoleFor(app(ActorContext::class)));
    }
}
