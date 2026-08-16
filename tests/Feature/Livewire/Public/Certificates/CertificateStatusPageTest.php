<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Certificates;

use App\Domain\AgreementCertificate\Actions\IssueCertificate;
use App\Domain\AgreementCertificate\CertificateStatusView;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Livewire\Public\Certificates\CertificateStatusPage;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task 2 (P5a, Lane 1) — the public `/sertifikat/{subjectType}/{subjectId}`
 * certificate status page (ACG6/AC6 of the certificates-and-agreements
 * spec): renders ONLY issuance state (type, status, version, effective
 * date, issuer role) through `CertificateStatusView` — the vault document
 * reference, the certificate's own document number, and subject internals
 * never appear in the HTML. Unknown subjects/types are indistinguishable
 * 404s.
 */
final class CertificateStatusPageTest extends TestCase
{
    use RefreshDatabase;

    private function makePaidOrder(): Order
    {
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MENUNGGU_PEMBAYARAN->value,
        ]);

        app(RecordOrderStatusChange::class)($order, OrderStatus::DIBAYAR, 'actor:system', 'system');

        return $order;
    }

    /**
     * The Domain fixture shape — an ACCEPTED vault `Document` inserted the
     * way `tests/Feature/Domain/AgreementCertificate/CertificateTest.php`
     * builds it (`DB::table('documents')->insert([...])`).
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

    public function test_the_status_page_renders_state_only_and_never_the_vault_reference_or_document_number(): void
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

        $html = Livewire::test(CertificateStatusPage::class, [
            'subjectType' => Order::class,
            'subjectId' => (string) $order->getKey(),
        ])
            ->assertOk()
            ->html();

        // The state-only projection is rendered (the page's Indonesian
        // display labels, not raw enum values).
        $this->assertStringContainsString('Penyelesaian Pesanan', $html);
        $this->assertStringContainsString('Terbit', $html);
        $this->assertStringContainsString('v1', $html);
        $this->assertStringContainsString('Admin', $html);

        // Restricted sources never leave the server (AC6): neither the
        // certificate's document number nor the vault document reference.
        $this->assertStringNotContainsString($certificate->reference, $html);
        $this->assertStringNotContainsString((string) $document->getKey(), $html);
        $this->assertStringNotContainsString('document_id', $html);
    }

    public function test_the_status_page_renders_the_empty_state_for_a_subject_without_certificates(): void
    {
        $order = $this->makePaidOrder();

        Livewire::test(CertificateStatusPage::class, [
            'subjectType' => Order::class,
            'subjectId' => (string) $order->getKey(),
        ])
            ->assertOk()
            ->assertSee('Belum ada sertifikat untuk subjek ini.');
    }

    public function test_an_unknown_subject_id_404s(): void
    {
        $this->get(route('sertifikat.status', [
            'subjectType' => Order::class,
            'subjectId' => (string) Str::uuid(),
        ]))->assertNotFound();
    }

    public function test_an_unknown_subject_type_404s(): void
    {
        $order = $this->makePaidOrder();

        $this->get(route('sertifikat.status', [
            'subjectType' => 'App\\Domain\\PreNeed\\Models\\PreNeedCase',
            'subjectId' => (string) $order->getKey(),
        ]))->assertNotFound();
    }
}
