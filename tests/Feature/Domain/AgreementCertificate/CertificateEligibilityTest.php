<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgreementCertificate;

use App\Domain\AgreementCertificate\CertificateEligibilityPolicy;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Models\Agreement;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\PreNeedCaseStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 1 of `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`
 * — `CertificateEligibilityPolicy` (AC3): eligibility is a domain-state
 * rule, never a direct read of `paid_via` / `payment_state`.
 */
final class CertificateEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    private function makePaidOrder(): Order
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        app(RecordOrderStatusChange::class)($order, OrderStatus::DIBAYAR, 'actor:system', 'system');

        return $order;
    }

    public function test_order_settlement_rule_allows_a_paid_order(): void
    {
        $policy = new CertificateEligibilityPolicy;

        $this->assertTrue($policy->eligibleFor(
            CertificateType::OrderSettlement->value,
            $this->makePaidOrder(),
        ));
    }

    public function test_order_settlement_rule_refuses_an_unpaid_order(): void
    {
        $policy = new CertificateEligibilityPolicy;

        $this->assertFalse($policy->eligibleFor(
            CertificateType::OrderSettlement->value,
            $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN),
        ));
    }

    public function test_an_unknown_certificate_type_has_no_rule_and_is_refused(): void
    {
        $policy = new CertificateEligibilityPolicy;

        $this->assertFalse($policy->eligibleFor('NOT_A_KNOWN_TYPE', $this->makePaidOrder()));
    }

    public function test_a_subject_that_is_not_an_order_is_refused(): void
    {
        $policy = new CertificateEligibilityPolicy;
        $order = $this->makeOrder(OrderStatus::MASUK);

        $agreement = Agreement::query()->create([
            'reference' => 'AGR-'.Str::upper(Str::random(8)),
            'type' => 'PRE_NEED_AGREEMENT',
            'version_number' => 1,
            'status' => 'draft',
            'subject_type' => Order::class,
            'subject_id' => (string) $order->getKey(),
        ]);

        $this->assertFalse($policy->eligibleFor(CertificateType::OrderSettlement->value, $agreement));
    }

    public function test_pre_need_settlement_rule_allows_a_settled_pre_need_case(): void
    {
        $case = PreNeedCase::query()->create([
            'status' => PreNeedCaseStatus::SETTLED->value,
        ]);

        $this->assertTrue((new CertificateEligibilityPolicy)->eligibleFor(
            CertificateType::PreNeedSettlement->value,
            $case,
        ));
    }

    public function test_pre_need_settlement_rule_refuses_an_unsatisfied_case(): void
    {
        $case = PreNeedCase::query()->create([
            'status' => PreNeedCaseStatus::INTEREST->value,
        ]);

        $this->assertFalse((new CertificateEligibilityPolicy)->eligibleFor(
            CertificateType::PreNeedSettlement->value,
            $case,
        ));
    }

    public function test_pre_need_settlement_rule_refuses_a_non_case_subject(): void
    {
        // A DIBAYAR order is never "a settled pre-need case" — the rule
        // reads the case's OWN state, never a payment column.
        $this->assertFalse((new CertificateEligibilityPolicy)->eligibleFor(
            CertificateType::PreNeedSettlement->value,
            $this->makePaidOrder(),
        ));
    }
}
