<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderTransitionAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    private const string NON_MONEY = 'verify_order';

    private const string MONEY = 'mark_order_paid';

    private function actor(array $roles, ?string $lastAuth = null): ActorContext
    {
        return new ActorContext(
            identityReference: 'user:1',
            roles: $roles,
            scopes: [],
            lastAuthenticatedAt: $lastAuth === null ? null : CarbonImmutable::parse($lastAuth),
        );
    }

    public function test_operator_can_run_non_money_transition(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::OPERATOR]), self::NON_MONEY);
        $this->assertTrue(true);
    }

    public function test_finance_cannot_run_plain_operator_transition(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::FINANCE]), self::NON_MONEY);
    }

    public function test_finance_can_run_money_transition(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::FINANCE]), self::MONEY);
        $this->assertTrue(true);
    }

    public function test_operator_cannot_run_money_transition(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::OPERATOR]), self::MONEY);
    }

    public function test_admin_can_run_everything(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::ADMIN]), self::MONEY);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::ADMIN]), self::NON_MONEY);
        $this->assertTrue(true);
    }

    public function test_restricted_admin_cannot_issue_quote(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::RESTRICTED_ADMIN]), 'issue_quote');
    }

    public function test_restricted_admin_can_verify_order(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::RESTRICTED_ADMIN]), 'verify_order');
        $this->assertTrue(true);
    }

    public function test_guest_is_denied(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([]), self::NON_MONEY);
    }

    // -----------------------------------------------------------------
    // cemetery_operator — the widened, cemetery-scoped branch (Task 6)
    // -----------------------------------------------------------------

    public function test_cemetery_operator_can_run_non_money_transition_for_their_own_cemetery(): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => 'user:1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => 'cemetery-a',
        ]);

        app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
            $this->actor([ActorRole::CEMETERY_OPERATOR]),
            self::NON_MONEY,
            'cemetery-a',
        );
        $this->assertTrue(true);
    }

    public function test_cemetery_operator_cannot_run_a_transition_for_a_cemetery_they_are_not_granted(): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => 'user:1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => 'cemetery-a',
        ]);

        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
            $this->actor([ActorRole::CEMETERY_OPERATOR]),
            self::NON_MONEY,
            'cemetery-b',
        );
    }

    public function test_cemetery_operator_with_no_cemetery_id_is_denied(): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => 'user:1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => 'cemetery-a',
        ]);

        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
            $this->actor([ActorRole::CEMETERY_OPERATOR]),
            self::NON_MONEY,
            null,
        );
    }

    public function test_cemetery_operator_cannot_run_a_money_transition_even_for_their_own_cemetery(): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => 'user:1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => 'cemetery-a',
        ]);

        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
            $this->actor([ActorRole::CEMETERY_OPERATOR]),
            self::MONEY,
            'cemetery-a',
        );
    }
}
