<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgreementCertificate;

use App\Domain\AgreementCertificate\Actions\IssueCertificate;
use App\Domain\AgreementCertificate\Actions\ReplaceCertificate;
use App\Domain\AgreementCertificate\Actions\RevokeCertificate;
use App\Domain\AgreementCertificate\CertificateStatus;
use App\Domain\AgreementCertificate\CertificateStatusView;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Exceptions\CertificateEligibilityNotMetException;
use App\Domain\AgreementCertificate\Exceptions\CertificateIssuerNotAuthorisedException;
use App\Domain\AgreementCertificate\Exceptions\CertificateReferenceCollisionException;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Domain\AgreementCertificate\Models\ExternalCertificateReference;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Platform\Audit\AuditSource;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 1 of `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`
 * — the Certificate half of the AgreementCertificate domain: issuance
 * (role gate, eligibility, vault document reference, AC7 number
 * uniqueness backstop, audit + `certificate.issued.v1`), revocation,
 * AC5 history-preserving replacement, AC8 external references, and the
 * AC6 state-only customer view.
 */
final class CertificateTest extends TestCase
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

    /**
     * A DIBAYAR order, walked through the domain's own status writer
     * (`MENUNGGU_PEMBAYARAN -> DIBAYAR` is a legal edge of
     * `OrderTransition`) — the same fixture shape the OrderWorkflow
     * feature tests use, so the eligibility rule is never tested against
     * a hand-written status column.
     */
    private function makePaidOrder(): Order
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        app(RecordOrderStatusChange::class)($order, OrderStatus::DIBAYAR, 'actor:system', 'system');

        return $order;
    }

    /**
     * A vault `Document` in the ACCEPTED state, inserted the same way the
     * DocumentVault feature tests build an accepted document fixture
     * (`DB::table('documents')->insert([...])` — the model refuses to
     * create anything but a quarantined row).
     */
    private function makeAcceptedDocument(): Document
    {
        $documentId = (string) Str::uuid();

        DB::table('documents')->insert([
            'id' => $documentId,
            'document_kind' => DocumentKind::Certificate->value,
            'state' => DocumentState::Accepted->value,
            'owner_type' => 'order',
            'owner_id' => (string) Str::uuid(),
            'original_filename' => 'sertifikat.pdf',
            'storage_prefix' => 'accepted',
            'storage_key' => 'opaque-key-'.Str::random(8),
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Document::query()->findOrFail($documentId);
    }

    private function makeQuarantinedDocument(): Document
    {
        return Document::createQuarantined([
            'document_kind' => DocumentKind::Certificate,
            'owner_type' => 'order',
            'owner_id' => (string) Str::uuid(),
            'original_filename' => 'sertifikat.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => 'quarantine-key-'.Str::random(8),
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'mime_verified' => 'application/pdf',
            'checksum_sha256' => str_repeat('ab', 32),
            'scanner_required' => true,
        ]);
    }

    public function test_issue_records_reference_status_document_audit_and_outbox(): void
    {
        $order = $this->makePaidOrder();
        $document = $this->makeAcceptedDocument();

        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'user:1',
            'admin',
            $document->getKey(),
        );

        $this->assertStringStartsWith('CERT-', $certificate->reference);
        $this->assertSame(CertificateStatus::Issued, $certificate->status());
        $this->assertSame(1, $certificate->version_number);
        $this->assertSame(Order::class, $certificate->subject_type);
        $this->assertSame((string) $order->getKey(), $certificate->subject_id);
        $this->assertSame('user:1', $certificate->issued_by_ref);
        $this->assertSame('admin', $certificate->issued_by_role);
        $this->assertSame((string) $document->getKey(), $certificate->document_id);
        $this->assertNotNull($certificate->effective_at);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'CERTIFICATE_ISSUED',
            'subject_type' => 'certificate',
            'subject_id' => (string) $certificate->getKey(),
            'actor_ref' => 'user:1',
            'actor_role' => 'admin',
            'outcome' => 'allowed',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'certificate.issued.v1',
            'event_version' => 1,
            'aggregate_type' => 'certificate',
            'aggregate_id' => (string) $certificate->getKey(),
            'classification' => 'INTERNAL',
            'idempotency_key' => "certificate:{$certificate->getKey()}",
        ]);
    }

    public function test_issue_allows_the_restricted_admin_role(): void
    {
        $order = $this->makePaidOrder();

        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'user:2',
            'restricted_admin',
            null,
        );

        $this->assertSame('restricted_admin', $certificate->issued_by_role);
    }

    public function test_issue_refuses_an_operator_role_and_writes_nothing(): void
    {
        $order = $this->makePaidOrder();

        $this->expectException(CertificateIssuerNotAuthorisedException::class);

        try {
            app(IssueCertificate::class)(
                CertificateType::OrderSettlement,
                $order,
                'user:3',
                'operator',
                null,
            );
        } finally {
            $this->assertSame(0, Certificate::query()->count());
            $this->assertDatabaseMissing('audit_events', ['action' => 'CERTIFICATE_ISSUED']);
            $this->assertDatabaseMissing('outbox_events', ['event_name' => 'certificate.issued.v1']);
        }
    }

    public function test_issue_refuses_when_eligibility_is_not_met_and_writes_nothing(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);

        $this->expectException(CertificateEligibilityNotMetException::class);

        try {
            app(IssueCertificate::class)(
                CertificateType::OrderSettlement,
                $order,
                'user:1',
                'admin',
                null,
            );
        } finally {
            $this->assertSame(0, Certificate::query()->count());
            $this->assertDatabaseMissing('audit_events', ['action' => 'CERTIFICATE_ISSUED']);
            $this->assertDatabaseMissing('outbox_events', ['event_name' => 'certificate.issued.v1']);
        }
    }

    public function test_issue_refuses_a_document_that_is_not_accepted(): void
    {
        $order = $this->makePaidOrder();
        $quarantined = $this->makeQuarantinedDocument();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(IssueCertificate::class)(
                CertificateType::OrderSettlement,
                $order,
                'user:1',
                'admin',
                $quarantined->getKey(),
            );
        } finally {
            $this->assertSame(0, Certificate::query()->count());
            $this->assertDatabaseMissing('audit_events', ['action' => 'CERTIFICATE_ISSUED']);
            $this->assertDatabaseMissing('outbox_events', ['event_name' => 'certificate.issued.v1']);
        }
    }

    public function test_revoke_records_status_and_audit_with_a_reason(): void
    {
        $order = $this->makePaidOrder();
        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'user:1',
            'admin',
            null,
        );

        $revoked = app(RevokeCertificate::class)(
            $certificate,
            'user:1',
            'admin',
            'Kesalahan data penerbitan',
        );

        $this->assertSame(CertificateStatus::Revoked, $revoked->status());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'CERTIFICATE_REVOKED',
            'subject_type' => 'certificate',
            'subject_id' => (string) $certificate->getKey(),
            'reason' => 'Kesalahan data penerbitan',
        ]);
        $this->assertDatabaseMissing('outbox_events', [
            'event_name' => 'certificate.revoked.v1',
        ]);
    }

    public function test_revoke_requires_a_non_blank_reason(): void
    {
        $order = $this->makePaidOrder();
        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'user:1',
            'admin',
            null,
        );

        $this->expectException(InvalidArgumentException::class);

        app(RevokeCertificate::class)($certificate, 'user:1', 'admin', '   ');
    }

    public function test_replace_preserves_history_and_issues_the_next_version(): void
    {
        $order = $this->makePaidOrder();
        $first = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'user:1',
            'admin',
            null,
        );

        $replacement = app(ReplaceCertificate::class)(
            $first,
            $order,
            'user:1',
            'admin',
            null,
            'Dokumen diperbarui',
        );

        $this->assertSame(CertificateStatus::Replaced, $first->fresh()->status());
        $this->assertSame(CertificateStatus::Issued, $replacement->status());
        $this->assertSame(2, $replacement->version_number);
        $this->assertNotSame($first->reference, $replacement->reference);
        $this->assertSame(Certificate::query()->count(), 2);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'CERTIFICATE_REPLACED',
            'subject_type' => 'certificate',
            'subject_id' => (string) $replacement->getKey(),
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'certificate.replaced.v1',
            'event_version' => 1,
            'aggregate_type' => 'certificate',
            'aggregate_id' => (string) $replacement->getKey(),
            'idempotency_key' => "certificate:{$replacement->getKey()}",
        ]);
    }

    public function test_a_reference_collision_for_issuer_and_type_is_classified(): void
    {
        $order = $this->makePaidOrder();

        Certificate::query()->create([
            'reference' => 'CERT-FIXEDREF',
            'type' => CertificateType::OrderSettlement->value,
            'version_number' => 1,
            'status' => CertificateStatus::Issued->value,
            'subject_type' => Order::class,
            'subject_id' => (string) $order->getKey(),
            'issued_by_ref' => 'user:1',
            'issued_by_role' => 'admin',
            'effective_at' => now(),
        ]);

        $this->expectException(CertificateReferenceCollisionException::class);

        app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'user:1',
            'admin',
            null,
            null,
            AuditSource::Panel,
            'CERT-FIXEDREF',
        );
    }

    public function test_an_external_reference_is_recorded_and_flagged_external(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        $reference = ExternalCertificateReference::query()->create([
            'issuer_ref' => 'TPU-Kota-Tangerang',
            'reference' => 'EKST-2026-0001',
            'type' => 'TANAH_KUBUR',
            'subject_type' => Order::class,
            'subject_id' => (string) $order->getKey(),
        ]);

        $this->assertSame('TPU-Kota-Tangerang', $reference->issuer_ref);
        $this->assertSame('EKST-2026-0001', $reference->reference);
        $this->assertTrue($reference->isExternal());
        $this->assertDatabaseHas('external_certificate_references', [
            'issuer_ref' => 'TPU-Kota-Tangerang',
            'reference' => 'EKST-2026-0001',
        ]);
    }

    public function test_status_view_exposes_state_only_never_the_document_id_or_reference(): void
    {
        $order = $this->makePaidOrder();
        $document = $this->makeAcceptedDocument();
        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'user:1',
            'admin',
            $document->getKey(),
        );

        $view = (new CertificateStatusView)->forSubject($order);

        $this->assertCount(1, $view);
        $entry = $view[0];
        $this->assertSame(
            ['type', 'status', 'version', 'effective_at', 'issued_by_role'],
            array_keys($entry),
        );
        $this->assertSame(CertificateType::OrderSettlement->value, $entry['type']);
        $this->assertSame(CertificateStatus::Issued->value, $entry['status']);
        $this->assertSame(1, $entry['version']);
        $this->assertNotNull($entry['effective_at']);
        $this->assertSame('admin', $entry['issued_by_role']);
        $this->assertNotContains('document_id', array_keys($entry));
        $this->assertNotContains('reference', array_keys($entry));
    }
}
