<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Payment\ConditionDenial;
use App\Platform\Payment\GuardCondition;
use App\Platform\Payment\GuardDenialReason;
use App\Platform\Payment\GuardResult;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Wave 1b ruling 1b-L3-01: `GuardResult` must expose "a `DENIED(condition,
 * publicMessage)` shape and an explicit `UnavailableUpstream` denial reason
 * distinct from a genuine domain denial", and the guard must have "no
 * reachable PASS outcome".
 *
 * This test pins the *type-level* half of that invariant: there is no
 * factory anywhere on `GuardResult` that can construct an allowed result, so
 * no caller — not even one inside this module — can manufacture a pass by
 * hand. The behavioural half (the Action never returns one) is pinned by
 * `Tests\Feature\Payment\PaymentGuardFailClosedTest`.
 */
final class GuardResultTest extends TestCase
{
    private function denial(
        GuardCondition $condition = GuardCondition::ConfirmationOrReservation,
        GuardDenialReason $reason = GuardDenialReason::UnavailableUpstream,
        ?string $missingUpstream = 'Confirmation|PlotReservation',
    ): ConditionDenial {
        return new ConditionDenial(
            condition: $condition,
            reason: $reason,
            publicMessage: 'Payment cannot be started yet.',
            missingUpstream: $missingUpstream,
        );
    }

    public function test_the_constructor_is_private_so_only_the_denied_factory_can_build_one(): void
    {
        $constructor = (new ReflectionClass(GuardResult::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    public function test_denied_is_the_only_public_factory_on_the_class(): void
    {
        $factories = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(GuardResult::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static function (ReflectionMethod $method): bool {
                    if (! $method->isStatic()) {
                        return false;
                    }

                    $returnType = $method->getReturnType();

                    return $returnType instanceof ReflectionNamedType
                        && in_array($returnType->getName(), ['self', 'static', GuardResult::class], true);
                },
            ),
        );

        $this->assertSame(['denied'], array_values($factories));
    }

    public function test_a_denial_carries_the_first_failing_condition_and_a_public_message(): void
    {
        $result = GuardResult::denied([
            $this->denial(GuardCondition::ProductGateOpen, GuardDenialReason::DomainDenied, null),
            $this->denial(),
        ]);

        $this->assertFalse($result->isAllowed());
        $this->assertTrue($result->isDenied());
        $this->assertSame(GuardCondition::ProductGateOpen, $result->condition());
        $this->assertSame(GuardDenialReason::DomainDenied, $result->reason());
        $this->assertNull($result->missingUpstream());
        $this->assertSame('Payment cannot be started yet.', $result->publicMessage());
    }

    public function test_it_reports_every_failing_condition_not_only_the_first(): void
    {
        $result = GuardResult::denied([
            $this->denial(GuardCondition::ProductGateOpen, GuardDenialReason::DomainDenied, null),
            $this->denial(GuardCondition::ConfirmationOrReservation),
            $this->denial(GuardCondition::QuoteAcceptedAndUnexpired, missingUpstream: 'Quote'),
        ]);

        $this->assertCount(3, $result->denials());
        $this->assertSame(
            [
                GuardCondition::ProductGateOpen,
                GuardCondition::ConfirmationOrReservation,
                GuardCondition::QuoteAcceptedAndUnexpired,
            ],
            array_map(static fn (ConditionDenial $denial): GuardCondition => $denial->condition, $result->denials()),
        );
    }

    public function test_an_unavailable_upstream_denial_is_distinguishable_from_a_domain_denial(): void
    {
        $upstream = GuardResult::denied([$this->denial()]);
        $domain = GuardResult::denied([
            $this->denial(GuardCondition::ProductGateOpen, GuardDenialReason::DomainDenied, null),
        ]);

        $this->assertTrue($upstream->isUnavailableUpstream());
        $this->assertSame('Confirmation|PlotReservation', $upstream->missingUpstream());

        $this->assertFalse($domain->isUnavailableUpstream());
        $this->assertNull($domain->missingUpstream());
    }

    public function test_a_result_with_no_denials_cannot_be_constructed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GuardResult::denied([]);
    }

    public function test_an_unavailable_upstream_denial_must_name_the_missing_upstream(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConditionDenial(
            condition: GuardCondition::QuoteAcceptedAndUnexpired,
            reason: GuardDenialReason::UnavailableUpstream,
            publicMessage: 'Payment cannot be started yet.',
            missingUpstream: null,
        );
    }
}
