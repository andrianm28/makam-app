<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\FailedPaymentExceptionQueueWidget;
use App\Models\User;
use App\Platform\FinancialLedger\Actions\RunReconciliation;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\Reconciliation;
use App\Platform\FinancialLedger\ProviderStatement;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ADM-001 AC11 (partial) — the failed-payment exception queue
 * (`FailedPaymentExceptionQueueWidget`). See the widget's own doc block for
 * why the other three AC11 queues are out of scope for this lane.
 *
 * Fixtures go through the REAL write paths
 * (`App\Platform\FinancialLedger\Journal::post()` +
 * `Actions\RunReconciliation::run()`), the same way
 * `tests/Feature/FinancialLedger/RunReconciliationTest.php` does — never a
 * direct `ReconciliationException::create()`, because that model is
 * `$guarded = ['*']` precisely so nothing but `RunReconciliation`/
 * `ResolveException` can write it.
 */
final class FailedPaymentExceptionQueueWidgetTest extends TestCase
{
    use RefreshDatabase;

    private const string ENTITY = 'badan-usaha-queue-1';

    private const string OTHER_ENTITY = 'badan-usaha-queue-2';

    private const string PERIOD = '2026-08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    /**
     * Posts a real journal batch, then reconciles it against a deliberately
     * mismatched statement — the same recipe
     * `RunReconciliationTest::test_a_statement_mismatch_becomes_a_finding_...`
     * uses to produce a real, open `reconciliation_exceptions` row.
     */
    private function openFailedPaymentException(string $entityRef, string $subjectRef): Reconciliation
    {
        $this->app->make(Journal::class)->post(
            businessKey: $subjectRef,
            entityRef: $entityRef,
            sourceType: 'payment',
            sourceId: $subjectRef,
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 250_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 250_000],
            ],
            occurredAt: '2026-08-15 10:00:00',
        );

        return $this->app->make(RunReconciliation::class)->run(
            period: self::PERIOD,
            entityRef: $entityRef,
            statement: new ProviderStatement(
                reference: 'statement-'.$entityRef,
                period: self::PERIOD,
                entityRef: $entityRef,
                lines: [$subjectRef => 251_000],
            ),
        );
    }

    public function test_a_panel_user_without_finance_authority_cannot_view_the_widget(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user, roles: []);

        $this->assertFalse(FailedPaymentExceptionQueueWidget::canView());
    }

    public function test_it_lists_a_real_open_exception_for_the_granted_badan_usaha(): void
    {
        $this->openFailedPaymentException(self::ENTITY, 'payment:evt-widget-1');

        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, self::ENTITY);

        Livewire::test(FailedPaymentExceptionQueueWidget::class)
            ->assertSee(self::ENTITY)
            ->assertSee(self::PERIOD)
            ->assertSee('payment:evt-widget-1')
            ->assertSee('Selisih Nominal');
    }

    /**
     * The load-bearing assertion: a `finance` actor granted ONLY
     * `self::ENTITY` must never see `self::OTHER_ENTITY`'s open exception,
     * even though both rows exist in the same `reconciliation_exceptions`
     * table.
     */
    public function test_it_never_lists_an_exception_for_a_badan_usaha_the_actor_holds_no_grant_for(): void
    {
        $this->openFailedPaymentException(self::ENTITY, 'payment:evt-widget-mine');
        $this->openFailedPaymentException(self::OTHER_ENTITY, 'payment:evt-widget-not-mine');

        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, self::ENTITY);

        Livewire::test(FailedPaymentExceptionQueueWidget::class)
            ->assertSee('payment:evt-widget-mine')
            ->assertDontSee('payment:evt-widget-not-mine')
            ->assertDontSee(self::OTHER_ENTITY);
    }

    public function test_it_renders_the_empty_state_when_there_is_no_open_exception(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, self::ENTITY);

        Livewire::test(FailedPaymentExceptionQueueWidget::class)
            ->assertSee('Tidak ada pengecualian pembayaran terbuka');
    }
}
