<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\FinancialOverviewWidget;
use App\Models\User;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ADM-001 AC1 — "payment" and "report" dashboard modules
 * (`FinancialOverviewWidget`). Gated and scoped like `FinanceReports`
 * (`tests/Feature/FinancialLedger/FinanceReportsPageTest.php` is the sibling
 * precedent this file follows): a `finance`-role actor without a business-
 * entity grant is refused, and a granted actor sees ONLY their own badan
 * usaha's payment figures — the leak-prevention assertion is the point of
 * `test_the_widget_never_counts_a_badan_usaha_the_actor_holds_no_grant_for`.
 */
final class FinancialOverviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    private const string ENTITY = 'badan-usaha-widget-1';

    private const string OTHER_ENTITY = 'badan-usaha-widget-2';

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

    private function grant(User $user, string $entityRef, bool $revoked = false): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => $entityRef,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
            'revoked_at' => $revoked ? CarbonImmutable::now() : null,
        ]);
    }

    private function paymentSession(string $badanUsahaRef, string $state): PaymentSession
    {
        $intent = PaymentIntent::query()->create([
            'requested_amount_minor' => 500_000_00,
            'currency' => 'IDR',
            'payment_mode' => 'online',
            'decision' => PaymentIntentDecision::Allowed->value,
            'actor_role' => 'customer',
            'evaluated_at' => CarbonImmutable::now(),
        ]);

        return PaymentSession::query()->create([
            'payment_intent_id' => $intent->id,
            'provider' => 'sumopod_sandbox',
            'provider_payment_id' => 'pay_'.str()->random(8),
            'payment_link_url' => 'https://checkout.sumopod.com/x',
            'amount_minor' => 500_000_00,
            'currency' => 'IDR',
            'merchant_ref' => 'MK-ORD-'.str()->random(4),
            'badan_usaha_ref' => $badanUsahaRef,
            'state' => $state,
        ]);
    }

    public function test_a_panel_user_without_finance_authority_cannot_view_the_widget(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user, roles: []);

        $this->assertFalse(FinancialOverviewWidget::canView());
    }

    public function test_a_finance_actor_without_a_grant_cannot_view_the_widget(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user);

        $this->assertFalse(FinancialOverviewWidget::canView());
    }

    /**
     * @return array<int, Stat>
     */
    private function stats(): array
    {
        $widget = new FinancialOverviewWidget;
        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        return $method->invoke($widget);
    }

    public function test_it_renders_real_scoped_payment_counts(): void
    {
        $this->paymentSession(self::ENTITY, SessionState::Paid->value);
        $this->paymentSession(self::ENTITY, SessionState::Paid->value);
        $this->paymentSession(self::ENTITY, SessionState::Failed->value);

        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, self::ENTITY);

        [$paid, $failed] = $this->stats();

        $this->assertSame('Pembayaran Berhasil', $paid->getLabel());
        $this->assertSame('2', $paid->getValue());
        $this->assertSame('Pembayaran Bermasalah', $failed->getLabel());
        $this->assertSame('1', $failed->getValue());

        // Also prove the wiring reaches a real HTTP/Livewire render, not
        // just the isolated getStats() query.
        Livewire::test(FinancialOverviewWidget::class)
            ->assertSee('Pembayaran Berhasil')
            ->assertSee('Pembayaran Bermasalah');
    }

    /**
     * The load-bearing assertion: a `finance` actor granted ONLY
     * `self::ENTITY` must never see `self::OTHER_ENTITY`'s payment figures,
     * even though both rows exist in the same `payment_sessions` table.
     * Asserted against the exact `Stat` value (not raw rendered HTML,
     * which can false-positive on an unrelated digit such as an SVG
     * `viewBox` attribute).
     */
    public function test_the_widget_never_counts_a_badan_usaha_the_actor_holds_no_grant_for(): void
    {
        $this->paymentSession(self::ENTITY, SessionState::Paid->value);
        $this->paymentSession(self::OTHER_ENTITY, SessionState::Paid->value);
        $this->paymentSession(self::OTHER_ENTITY, SessionState::Paid->value);
        $this->paymentSession(self::OTHER_ENTITY, SessionState::Paid->value);

        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, self::ENTITY);

        [$paid] = $this->stats();

        // Scoped total is 1 (only self::ENTITY); the unscoped total across
        // both entities is 4. Anything other than "1" here means the other
        // badan usaha's rows leaked into this actor's figures.
        $this->assertSame('1', $paid->getValue());
    }

    public function test_a_revoked_grant_denies_view(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, self::ENTITY, revoked: true);

        $this->assertFalse(FinancialOverviewWidget::canView());
    }
}
