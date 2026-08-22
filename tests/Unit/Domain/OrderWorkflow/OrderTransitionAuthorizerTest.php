<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class OrderTransitionAuthorizerTest extends TestCase
{
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
}
