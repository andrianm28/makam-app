<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgreementCertificate;

use App\Domain\AgreementCertificate\Actions\AcceptAgreement;
use App\Domain\AgreementCertificate\Actions\CreateAgreement;
use App\Domain\AgreementCertificate\Actions\SupersedeAgreement;
use App\Domain\AgreementCertificate\AgreementStatus;
use App\Domain\AgreementCertificate\AgreementType;
use App\Domain\AgreementCertificate\Models\Agreement;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Task 1 of `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`
 * — the Agreement half of the AgreementCertificate domain: creation,
 * AC2 acceptance binding, AC5 history-preserving supersession, and the
 * delete guard for rows a certificate references.
 */
final class AgreementTest extends TestCase
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

    private function makeAgreement(Order $subject): Agreement
    {
        return app(CreateAgreement::class)(
            AgreementType::PreNeedAgreement,
            $subject,
            'user:1',
            'admin',
        );
    }

    public function test_create_records_a_draft_row_with_audit(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        $agreement = $this->makeAgreement($order);

        $this->assertSame('1', (string) $agreement->version_number);
        $this->assertSame(AgreementStatus::Draft, $agreement->status());
        $this->assertSame(Order::class, $agreement->subject_type);
        $this->assertSame((string) $order->getKey(), $agreement->subject_id);
        $this->assertStringStartsWith('AGR-', $agreement->reference);
        $this->assertNull($agreement->accepted_by_ref);
        $this->assertNull($agreement->accepted_quote_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'AGREEMENT_CREATED',
            'subject_type' => 'agreement',
            'subject_id' => (string) $agreement->getKey(),
            'actor_ref' => 'user:1',
            'actor_role' => 'admin',
            'outcome' => 'allowed',
        ]);
    }

    public function test_accept_binds_actor_quote_and_exact_agreement_version(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $agreement = $this->makeAgreement($order);

        $accepted = app(AcceptAgreement::class)(
            $agreement,
            'actor:customer-7',
            'quote-2026-001',
            (string) $agreement->getKey(),
        );

        $this->assertSame(AgreementStatus::Accepted, $accepted->status());
        $this->assertSame('actor:customer-7', $accepted->accepted_by_ref);
        $this->assertSame('quote-2026-001', $accepted->accepted_quote_id);
        $this->assertSame((string) $agreement->getKey(), $accepted->accepted_agreement_version_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'AGREEMENT_ACCEPTED',
            'subject_type' => 'agreement',
            'subject_id' => (string) $agreement->getKey(),
            'actor_ref' => 'actor:customer-7',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'agreement.accepted.v1',
            'event_version' => 1,
            'aggregate_type' => 'agreement',
            'aggregate_id' => (string) $agreement->getKey(),
            'classification' => 'INTERNAL',
            'idempotency_key' => "agreement_accepted:{$agreement->getKey()}",
        ]);
    }

    public function test_accept_refuses_when_the_version_is_not_the_exact_row(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $agreement = $this->makeAgreement($order);

        $this->expectException(InvalidArgumentException::class);

        app(AcceptAgreement::class)(
            $agreement,
            'actor:customer-7',
            'quote-2026-001',
            (string) Str::uuid(),
        );
    }

    public function test_supersede_preserves_the_old_row_and_creates_the_next_version(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $agreement = $this->makeAgreement($order);
        $accepted = app(AcceptAgreement::class)(
            $agreement,
            'actor:customer-7',
            'quote-2026-001',
            (string) $agreement->getKey(),
        );

        $next = app(SupersedeAgreement::class)($accepted, 'user:1', 'admin');

        $this->assertSame(AgreementStatus::Superseded, $accepted->fresh()->status());
        $this->assertSame(2, $next->version_number);
        $this->assertSame(AgreementStatus::Draft, $next->status());
        $this->assertSame(Order::class, $next->subject_type);
        $this->assertSame((string) $order->getKey(), $next->subject_id);
        $this->assertSame(1, Agreement::query()->where('status', AgreementStatus::Superseded->value)->count());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'AGREEMENT_SUPERSEDED',
            'subject_type' => 'agreement',
            'subject_id' => (string) $accepted->getKey(),
        ]);
    }

    public function test_delete_is_blocked_while_a_certificate_references_the_agreement(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $agreement = $this->makeAgreement($order);

        Certificate::query()->create([
            'reference' => 'CERT-FIXED-REF',
            'type' => 'ORDER_SETTLEMENT',
            'version_number' => 1,
            'status' => 'issued',
            'subject_type' => Agreement::class,
            'subject_id' => (string) $agreement->getKey(),
            'issued_by_ref' => 'user:1',
            'issued_by_role' => 'admin',
            'effective_at' => now(),
        ]);

        $this->expectException(LogicException::class);

        $agreement->delete();
    }

    public function test_delete_is_blocked_for_a_non_draft_agreement(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $agreement = $this->makeAgreement($order);
        app(AcceptAgreement::class)(
            $agreement,
            'actor:customer-7',
            'quote-2026-001',
            (string) $agreement->getKey(),
        );

        $this->expectException(LogicException::class);

        $agreement->delete();
    }
}
