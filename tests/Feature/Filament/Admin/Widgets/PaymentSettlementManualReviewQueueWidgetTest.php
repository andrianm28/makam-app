<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\PaymentSettlementManualReviewQueueWidget;
use App\Models\User;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\ProviderEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch M1b, PAY-02 — the `MANUAL_REVIEW` admin queue. Same gating and
 * per-`badan_usaha` scoping convention as
 * `FailedPaymentExceptionQueueWidgetTest`, since `PaymentSettlementManualReviewQueueWidget`
 * follows that widget's own conventions (see its class doc block for why
 * scoping goes through `payment_sessions.badan_usaha_ref` rather than a
 * column `provider_events` does not have).
 */
final class PaymentSettlementManualReviewQueueWidgetTest extends TestCase
{
    use RefreshDatabase;

    private const string ENTITY = 'badan-usaha-review-1';

    private const string OTHER_ENTITY = 'badan-usaha-review-2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);
    }

    private function actAsActor(User $user, array $roles = [FinanceLedgerReadAuthorizer::FINANCE_ROLE]): void
    {
        $this->app->instance(ActorContext::class, new ActorContext(
            identityReference: (string) $user->getAuthIdentifier(),
            roles: $roles,
            lastAuthenticatedAt: CarbonImmutable::now(),
        ));
    }

    private function grant(User $user, string $entityRef): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => $entityRef,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
            'revoked_at' => null,
        ]);
    }

    private function manualReviewEvent(string $badanUsahaRef, string $providerTransactionId): ProviderEvent
    {
        $intent = PaymentIntent::query()->create([
            'requested_amount_minor' => 150_000_00,
            'currency' => 'IDR',
            'payment_mode' => 'online',
            'decision' => PaymentIntentDecision::Allowed->value,
            'actor_role' => 'customer',
            'evaluated_at' => CarbonImmutable::now(),
        ]);

        PaymentSession::query()->create([
            'payment_intent_id' => $intent->id,
            'provider' => 'sumopod_sandbox',
            'provider_payment_id' => $providerTransactionId,
            'payment_link_url' => 'https://checkout.sumopod.com/x',
            'amount_minor' => 150_000_00,
            'currency' => 'IDR',
            'merchant_ref' => 'makam-sandbox',
            'badan_usaha_ref' => $badanUsahaRef,
            'state' => 'AWAITING_PAYMENT',
        ]);

        $event = ProviderEvent::query()->create([
            'provider' => 'sumopod_sandbox',
            'provider_event_id' => 'evt_'.bin2hex(random_bytes(8)),
            'event_id_source' => 'svix',
            'provider_transaction_id' => $providerTransactionId,
            'invoice_reference' => 'REF-'.$providerTransactionId,
            'event_type' => 'payment.completed',
            'merchant_ref' => 'makam-sandbox',
            'amount_minor' => 150_000_00,
            'raw_payload' => '{}',
            'payload_digest' => hash('sha256', '{}'),
            'status' => ProviderEventStatus::Validated->value,
            'received_at' => now(),
        ]);

        $event->markStatus(ProviderEventStatus::ManualReview, 'settlement failed; retries exhausted');

        return $event;
    }

    public function test_a_panel_user_without_finance_authority_cannot_view_the_widget(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user, roles: []);

        $this->assertFalse(PaymentSettlementManualReviewQueueWidget::canView());
    }

    public function test_it_lists_a_manual_review_row_for_the_granted_badan_usaha(): void
    {
        $this->manualReviewEvent(self::ENTITY, 'pay_review_mine');

        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, self::ENTITY);

        Livewire::test(PaymentSettlementManualReviewQueueWidget::class)
            ->assertSee('sumopod_sandbox')
            ->assertSee('makam-sandbox')
            ->assertSee('settlement failed; retries exhausted');
    }

    /**
     * The load-bearing assertion: a `finance` actor granted ONLY
     * `self::ENTITY` must never see `self::OTHER_ENTITY`'s manual-review row.
     */
    public function test_it_never_lists_a_row_for_a_badan_usaha_the_actor_holds_no_grant_for(): void
    {
        $mine = $this->manualReviewEvent(self::ENTITY, 'pay_review_mine');
        $notMine = $this->manualReviewEvent(self::OTHER_ENTITY, 'pay_review_not_mine');

        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, self::ENTITY);

        Livewire::test(PaymentSettlementManualReviewQueueWidget::class)
            ->assertSee($mine->invoice_reference)
            ->assertDontSee($notMine->invoice_reference);
    }

    public function test_it_renders_the_empty_state_when_there_is_no_manual_review_row(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, self::ENTITY);

        Livewire::test(PaymentSettlementManualReviewQueueWidget::class)
            ->assertSee('Tidak ada peristiwa pembayaran yang menunggu peninjauan manual');
    }
}
