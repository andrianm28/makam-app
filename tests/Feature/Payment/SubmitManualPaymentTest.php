<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\DocumentVault\Actions\UploadDocument;
use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\DocumentValidator;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\StoragePathPolicy;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentVerificationStatus;
use App\Platform\Payment\SubmitManualPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

/**
 * `SubmitManualPayment` — Task 5's safe slice, Wave 1c Append-Correction
 * (`task-5-brief.md`). Proves the reachable half of the original plan's
 * Task 5 Step 1: the `payment_verifications` row is created at `SUBMITTED`,
 * a proof file (when supplied) is referenced through the document vault's
 * quarantine-first seam and never stored directly, and the audit trail is
 * written without a reason (the action is not on `SensitiveActions`).
 *
 * Also structurally pins the ruling's hard prohibition: this action must
 * never reference `payment_sessions`, `PaymentSession`, `SessionState`, or
 * any `app/Domain/OrderWorkflow/` file.
 */
final class SubmitManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private SubmitManualPayment $action;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway root per test — same precedent as
        // tests/Feature/DocumentVault/UploadDocumentTest.php — never the
        // real dev storage/app/private tree.
        $this->root = sys_get_temp_dir().'/payment-verification-upload-test-'.Str::random(12);

        $this->action = new SubmitManualPayment(
            new UploadDocument(
                new LocalFilesystemObjectStorage($this->root),
                new StoragePathPolicy,
                new DocumentValidator,
            ),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_creates_a_submitted_row_without_a_proof_file(): void
    {
        $verification = $this->action->submit(
            reference: 'order-123',
            paymentMethod: 'bank_transfer',
            paymentReference: 'TRX-998877',
            instructions: 'Transferred to BCA 123-456-789',
            proofFile: null,
            actorRef: null,
            actorRole: 'guest',
            source: AuditSource::Api,
        );

        $this->assertSame(1, PaymentVerification::query()->count());
        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->status());
        $this->assertSame('order-123', $verification->reference);
        $this->assertSame('bank_transfer', $verification->payment_method);
        $this->assertSame('TRX-998877', $verification->payment_reference);
        $this->assertSame('Transferred to BCA 123-456-789', $verification->instructions);
        $this->assertNull($verification->proof_document_id);
        $this->assertNotNull($verification->submitted_at);
        $this->assertNull($verification->decided_at);
    }

    public function test_it_uploads_a_proof_file_through_the_document_vault_and_references_it_only(): void
    {
        Queue::fake();

        $verification = $this->action->submit(
            reference: 'order-456',
            paymentMethod: 'qris',
            paymentReference: 'QRIS-112233',
            instructions: null,
            proofFile: $this->uploadedFile($this->minimalPdf(), 'bukti-bayar.pdf', 'application/pdf'),
            actorRef: 42,
            actorRole: 'customer',
            source: AuditSource::Api,
        );

        $this->assertNotNull($verification->proof_document_id);

        $document = Document::query()->findOrFail($verification->proof_document_id);
        $this->assertSame(DocumentKind::PaymentProof, $document->document_kind);
        $this->assertSame(DocumentState::Quarantined, $document->state);
        $this->assertSame('payment_verification', $document->owner_type);
        $this->assertSame($verification->id, $document->owner_id);

        // The row references the document, never its content — the schema
        // itself has no column capable of carrying file bytes, a checksum,
        // or storage internals.
        $columns = Schema::getColumnListing('payment_verifications');
        foreach (['checksum_sha256', 'storage_key', 'mime_declared', 'original_filename'] as $documentOnlyColumn) {
            $this->assertNotContains($documentOnlyColumn, $columns);
        }
    }

    public function test_a_blank_required_field_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->action->submit(
            reference: '   ',
            paymentMethod: 'bank_transfer',
            paymentReference: 'TRX-1',
            instructions: null,
            proofFile: null,
            actorRef: null,
            actorRole: 'guest',
            source: AuditSource::Api,
        );

        $this->assertSame(0, PaymentVerification::query()->count());
    }

    public function test_it_writes_an_audit_event_without_a_reason(): void
    {
        $verification = $this->action->submit(
            reference: 'order-789',
            paymentMethod: 'bank_transfer',
            paymentReference: 'TRX-1',
            instructions: null,
            proofFile: null,
            actorRef: 7,
            actorRole: 'customer',
            source: AuditSource::Api,
        );

        $event = AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_SUBMITTED)->latest('id')->first();

        $this->assertNotNull($event);
        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('payment_verification', $event->subject_type);
        $this->assertSame($verification->id, $event->subject_id);
        $this->assertNull($event->reason);
        $this->assertSame('7', $event->actor_ref);
    }

    public function test_a_failed_upload_does_not_leave_a_verification_row_behind(): void
    {
        // An empty PDF fails DocumentValidator's own size/content checks —
        // any DocumentValidationException from inside the same
        // Audit::wrap() transaction must roll the created row back too.
        try {
            $this->action->submit(
                reference: 'order-fail',
                paymentMethod: 'bank_transfer',
                paymentReference: 'TRX-fail',
                instructions: null,
                proofFile: $this->uploadedFile('', 'empty.pdf', 'application/pdf'),
                actorRef: null,
                actorRole: 'guest',
                source: AuditSource::Api,
            );

            $this->fail('Expected an exception for an invalid proof upload.');
        } catch (Throwable) {
            $this->assertSame(0, PaymentVerification::query()->count());
            $this->assertSame(0, Document::query()->count());
        }
    }

    public function test_it_never_references_payment_sessions_or_the_order_aggregate(): void
    {
        $source = $this->withoutComments((string) file_get_contents(base_path('app/Platform/Payment/SubmitManualPayment.php')));

        foreach ([
            'payment_sessions',
            'PaymentSession',
            'SessionState',
            'OrderWorkflow',
            'Journal::post',
            'MENUNGGU_VERIFIKASI_PEMBAYARAN',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "SubmitManualPayment.php references [{$forbidden}]");
        }

        $this->assertSame(0, PaymentSession::query()->count());
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function uploadedFile(string $content, string $filename, string $mimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'payment-verification-upload-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, $mimeType, null, true);
    }

    private function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
