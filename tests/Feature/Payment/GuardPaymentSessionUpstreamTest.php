<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Models\User;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FinancialLedger\Money;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Payment\GuardCondition;
use App\Platform\Payment\GuardDenialReason;
use App\Platform\Payment\GuardPaymentSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 6 of `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`
 * — conditions 2-5 made real against the `Order` aggregate and `Quote`.
 *
 * Each condition denies with a genuine `DomainDenied` when its record is
 * missing or unsatisfied. Condition 6 alone retains `UnavailableUpstream`
 * (`FIN-DEC-01` TBD). The load-bearing test (test 6) asserts that even when
 * conditions 1-5 are all genuinely satisfied, the guard STILL denies on
 * condition 6 — proving the lane never reaches `GuardResult::Allowed`.
 */
final class GuardPaymentSessionUpstreamTest extends TestCase
{
    use RefreshDatabase;

    private function guardWithPaymentGate(bool $open): GuardPaymentSession
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot([
                    'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: $this->open),
                ]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));

        return $this->app->make(GuardPaymentSession::class);
    }

    private function amount(int $minor): Money
    {
        return new Money($minor);
    }

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-UPSTREAM-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    private function acceptedQuote(Order $order, int $totalMinor, ?CarbonImmutable $expiresAt = null): Quote
    {
        $quote = Quote::query()->create([
            'order_id' => $order->getKey(),
            'version_number' => 1,
            'status' => QuoteStatus::ISSUED->value,
            'total_minor' => $totalMinor,
            'currency' => 'IDR',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => $expiresAt ?? CarbonImmutable::now()->addDays(7),
            'issued_by_ref' => 'actor:admin-1',
            'issued_by_role' => 'admin',
        ]);

        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');

        return $quote->fresh();
    }

    private function issuedQuote(Order $order, int $totalMinor, ?CarbonImmutable $expiresAt = null): Quote
    {
        return Quote::query()->create([
            'order_id' => $order->getKey(),
            'version_number' => 1,
            'status' => QuoteStatus::ISSUED->value,
            'total_minor' => $totalMinor,
            'currency' => 'IDR',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => $expiresAt ?? CarbonImmutable::now()->addDays(7),
            'issued_by_ref' => 'actor:admin-1',
            'issued_by_role' => 'admin',
        ]);
    }

    private function grantOrderScope(string $actorRef, Order $order): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => $actorRef,
            'entity_type' => ScopeEntityType::ORDER,
            'entity_id' => $order->getKey(),
        ]);
    }

    private function adminActor(): User
    {
        $user = User::factory()->create();

        app(GrantActorRole::class)(
            $user->id,
            ActorRole::ADMIN,
            'test',
            1,
        );

        $this->actingAs($user);
        $this->app->forgetInstance(ActorContextResolver::class);

        return $user;
    }

    private function caseManagerActor(): User
    {
        $user = User::factory()->create();

        app(GrantActorRole::class)(
            $user->id,
            ActorRole::CASE_MANAGER,
            'test',
            1,
        );

        $this->actingAs($user);
        $this->app->forgetInstance(ActorContextResolver::class);

        return $user;
    }

    public function test_condition_2_denies_with_a_domain_reason_for_an_unconfirmed_order(): void
    {
        foreach ([
            'MASUK' => OrderStatus::MASUK,
            'MENUNGGU_KETERSEDIAAN' => OrderStatus::MENUNGGU_KETERSEDIAAN,
            'DITOLAK' => OrderStatus::DITOLAK,
            'KEDALUWARSA' => OrderStatus::KEDALUWARSA,
        ] as $label => $status) {
            $order = $this->makeOrder($status);
            $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

            $this->assertFalse($result->isAllowed());

            $denial = $result->condition() === GuardCondition::ConfirmationOrReservation
                ? $result
                : null;

            foreach ($result->denials() as $d) {
                if ($d->condition === GuardCondition::ConfirmationOrReservation) {
                    $denial = $d;
                    break;
                }
            }

            $this->assertNotNull($denial, "Condition 2 denial not found for status [{$label}]");
            $this->assertSame(GuardDenialReason::DomainDenied, $denial->reason);
            $this->assertNull($denial->missingUpstream);
        }
    }

    public function test_condition_2_holds_for_a_confirmed_order(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        $this->acceptedQuote($order, 1_500_000_00);
        $user = $this->adminActor();
        $this->grantOrderScope((string) $user->id, $order);

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

        $this->assertFalse($result->isAllowed());

        foreach ($result->denials() as $denial) {
            $this->assertNotSame(
                GuardCondition::ConfirmationOrReservation,
                $denial->condition,
                'Condition 2 must not be in the denial list for a confirmed order.',
            );
        }
    }

    public function test_condition_3_denies_for_no_quote(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

        $this->assertFalse($result->isAllowed());

        $denial = null;
        foreach ($result->denials() as $d) {
            if ($d->condition === GuardCondition::QuoteAcceptedAndUnexpired) {
                $denial = $d;
                break;
            }
        }

        $this->assertNotNull($denial);
        $this->assertSame(GuardDenialReason::DomainDenied, $denial->reason);
        $this->assertNull($denial->missingUpstream);
    }

    public function test_condition_3_denies_for_an_unaccepted_quote(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);

        $this->issuedQuote($order, 1_500_000_00);
        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

        $denial = null;
        foreach ($result->denials() as $d) {
            if ($d->condition === GuardCondition::QuoteAcceptedAndUnexpired) {
                $denial = $d;
                break;
            }
        }

        $this->assertNotNull($denial);
        $this->assertSame(GuardDenialReason::DomainDenied, $denial->reason);
        $this->assertNull($denial->missingUpstream);
    }

    public function test_condition_3_denies_for_an_expired_quote(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);

        $this->issuedQuote($order, 1_500_000_00, CarbonImmutable::now()->subDays(1));

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

        $denial = null;
        foreach ($result->denials() as $d) {
            if ($d->condition === GuardCondition::QuoteAcceptedAndUnexpired) {
                $denial = $d;
                break;
            }
        }

        $this->assertNotNull($denial);
        $this->assertSame(GuardDenialReason::DomainDenied, $denial->reason);
        $this->assertNull($denial->missingUpstream);
    }

    public function test_condition_4_denies_for_a_case_manager(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        $this->acceptedQuote($order, 1_500_000_00);
        $user = $this->caseManagerActor();
        $this->grantOrderScope((string) $user->id, $order);

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

        $this->assertFalse($result->isAllowed());

        $denial = null;
        foreach ($result->denials() as $d) {
            if ($d->condition === GuardCondition::AuthorizedOpening) {
                $denial = $d;
                break;
            }
        }

        $this->assertNotNull($denial);
        $this->assertSame(GuardDenialReason::DomainDenied, $denial->reason);
        $this->assertNull($denial->missingUpstream);
    }

    public function test_condition_4_passes_for_an_admin(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        $this->acceptedQuote($order, 1_500_000_00);
        $user = $this->adminActor();
        $this->grantOrderScope((string) $user->id, $order);

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

        $this->assertFalse($result->isAllowed());

        foreach ($result->denials() as $denial) {
            $this->assertNotSame(
                GuardCondition::AuthorizedOpening,
                $denial->condition,
                'Condition 4 must not be in the denial list for an admin with ORDER grant.',
            );
        }
    }

    public function test_condition_4_denies_for_an_admin_without_an_order_grant(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        $this->acceptedQuote($order, 1_500_000_00);
        $this->adminActor();

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

        $this->assertFalse($result->isAllowed());

        $denial = null;
        foreach ($result->denials() as $d) {
            if ($d->condition === GuardCondition::AuthorizedOpening) {
                $denial = $d;
                break;
            }
        }

        $this->assertNotNull($denial);
        $this->assertSame(GuardDenialReason::DomainDenied, $denial->reason);
        $this->assertNull($denial->missingUpstream);
    }

    public function test_condition_5_denies_for_an_amount_differing_by_one_minor_unit(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        $this->acceptedQuote($order, 1_500_000_00);
        $user = $this->adminActor();
        $this->grantOrderScope((string) $user->id, $order);

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_01));

        $this->assertFalse($result->isAllowed());

        $denial = null;
        foreach ($result->denials() as $d) {
            if ($d->condition === GuardCondition::AmountMatchesQuoteTotal) {
                $denial = $d;
                break;
            }
        }

        $this->assertNotNull($denial);
        $this->assertSame(GuardDenialReason::DomainDenied, $denial->reason);
        $this->assertNull($denial->missingUpstream);
    }

    public function test_condition_5_denies_for_a_non_positive_quote_total(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);

        $quote = Quote::query()->create([
            'order_id' => $order->getKey(),
            'version_number' => 1,
            'status' => QuoteStatus::ISSUED->value,
            'total_minor' => 0,
            'currency' => 'IDR',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(7),
            'issued_by_ref' => 'actor:admin-1',
            'issued_by_role' => 'admin',
        ]);

        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');

        $user = $this->adminActor();
        $this->grantOrderScope((string) $user->id, $order);

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(0));

        $this->assertFalse($result->isAllowed());

        $denial = null;
        foreach ($result->denials() as $d) {
            if ($d->condition === GuardCondition::AmountMatchesQuoteTotal) {
                $denial = $d;
                break;
            }
        }

        $this->assertNotNull($denial);
        $this->assertSame(GuardDenialReason::DomainDenied, $denial->reason);
        $this->assertNull($denial->missingUpstream);
    }

    public function test_all_four_conditions_satisfied_still_denies_on_condition_six(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        $this->acceptedQuote($order, 1_500_000_00);
        $user = $this->adminActor();
        $this->grantOrderScope((string) $user->id, $order);

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

        $this->assertFalse($result->isAllowed());
        $this->assertSame(GuardCondition::MerchantAndBadanUsahaBound, $result->condition());
        $this->assertSame(GuardDenialReason::UnavailableUpstream, $result->reason());
        $this->assertStringContainsString('FIN-DEC-01', $result->missingUpstream() ?? '');
        $this->assertSame(1, count($result->denials()), 'Only condition 6 may be in the denial list.');
    }

    public function test_condition_six_names_fin_dec_01(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        $this->acceptedQuote($order, 1_500_000_00);
        $user = $this->adminActor();
        $this->grantOrderScope((string) $user->id, $order);

        $result = ($this->guardWithPaymentGate(open: true))($order, $this->amount(1_500_000_00));

        $this->assertStringContainsString('FIN-DEC-01', $result->missingUpstream() ?? '');
    }
}
