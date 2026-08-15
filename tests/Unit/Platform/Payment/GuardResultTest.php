<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Payment\ConditionDenial;
use App\Platform\Payment\GuardCondition;
use App\Platform\Payment\GuardDenialReason;
use App\Platform\Payment\GuardResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * The online-payment gateway task is the reviewed change that ends the
 * deny-only era: the guard can now genuinely evaluate all six conditions
 * (condition 6's merchant binding became real via config), so `allowed()`
 * exists as the result of all-six-hold. This test pins the type-level shape
 * of BOTH states:
 *
 *   - the constructor stays private — no caller can build either state by
 *     hand;
 *   - `allowed()` is the only way to an allowed result, and it is
 *     denial-free (its denial-scoped accessors throw);
 *   - `denied()` refuses an empty list, so a pass in all but name can never
 *     be constructed through the denial factory.
 *
 * The behavioural half (which evaluations produce which state) is pinned by
 * `Tests\Feature\Payment\GuardPaymentSessionUpstreamTest` and
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

    public function test_the_constructor_is_private_so_only_the_factories_can_build_one(): void
    {
        $constructor = (new ReflectionClass(GuardResult::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    public function test_allowed_and_denied_are_the_only_public_factories_on_the_class(): void
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

        $this->assertSame(['allowed', 'denied'], array_values($factories));
    }

    public function test_an_allowed_result_is_constructible_and_is_not_a_denial(): void
    {
        $result = GuardResult::allowed();

        $this->assertTrue($result->isAllowed());
        $this->assertFalse($result->isDenied());
        $this->assertSame([], $result->denials());
        $this->assertSame([], $result->deniedConditionValues());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function denialScopedAccessors(): array
    {
        return [
            'condition' => ['condition'],
            'reason' => ['reason'],
            'publicMessage' => ['publicMessage'],
            'missingUpstream' => ['missingUpstream'],
            'isUnavailableUpstream' => ['isUnavailableUpstream'],
        ];
    }

    #[DataProvider('denialScopedAccessors')]
    public function test_the_denial_scoped_accessors_throw_on_an_allowed_result(string $accessor): void
    {
        $result = GuardResult::allowed();

        $this->expectException(InvalidArgumentException::class);
        $result->{$accessor}();
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
