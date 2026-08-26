<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
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
use App\Platform\Payment\Exceptions\PaymentVerificationOrderNotFoundException;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentVerificationStatus;
use App\Platform\Payment\SubmitManualPayment;
use Carbon\CarbonImmutable;
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
 * (`task-5-brief.md`), extended by PAY-02
 * (`docs/testing/release-gates.md` §C) to require a resolvable order and a
 * customer-stated amount. Proves the reachable half of the original plan's
 * Task 5 Step 1: the `payment_verifications` row is created at `SUBMITTED`,
 * a proof file (when supplied) is referenced through the document vault's
 * quarantine-first seam and never stored directly, and the audit trail is
 * written without a reason (the action is not on `SensitiveActions`). Also
 * proves PAY-02's real order linkage: `$reference` must resolve to a real
 * `marketplace_orders.order_number` or the submission is refused before any
 * row is written, and the stated amount/currency are validated and stored.
 *
 * Also structurally pins the ruling's hard prohibition: this action must
 * never reference `payment_sessions`, `PaymentSession`, `SessionState`, or
 * any `app/Domain/OrderWorkflow/` file.
 */
final class SubmitManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const int TOTAL_MINOR = 325_000_00;

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

    private function marketplaceOrder(?string $orderNumber = null): MarketplaceOrder
    {
        $vendor = Vendor::query()->create(['name' => 'Toko Bunga', 'is_active' => true]);

        return MarketplaceOrder::query()->create([
            'order_number' => $orderNumber ?? 'MKT-'.Str::upper(Str::random(10)),
            'customer_ref' => 'cust-1',
            'entity_ref' => 'BU-JKT-01',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => self::TOTAL_MINOR,
            'delivery_fee_minor' => 0,
            'total_minor' => self::TOTAL_MINOR,
            'payment_state' => PaymentState::BELUM_DIBAYAR,
            'idempotency_key' => 'mkt-'.Str::lower(Str::random(12)),
            'placed_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_it_creates_a_submitted_row_without_a_proof_file(): void
    {
        $order = $this->marketplaceOrder('order-123');

        $verification = $this->action->submit(
            reference: 'order-123',
            paymentMethod: 'bank_transfer',
            paymentReference: 'TRX-998877',
            instructions: 'Transferred to BCA 123-456-789',
            amountMinor: self::TOTAL_MINOR,
            currency: 'IDR',
            proofFile: null,
            actorRef: null,
            actorRole: 'guest',
            source: AuditSource::Api,
        );

        $this->assertSame(1, PaymentVerification::query()->count());
        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->status());
        $this->assertSame('order-123', $verification->reference);
        $this->assertSame($order->id, $verification->order_id);
        $this->assertSame(self::TOTAL_MINOR, $verification->amount_minor);
        $this->assertSame('IDR', $verification->currency);
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

        $this->marketplaceOrder('order-456');

        $verification = $this->action->submit(
            reference: 'order-456',
            paymentMethod: 'qris',
            paymentReference: 'QRIS-112233',
            instructions: null,
            amountMinor: self::TOTAL_MINOR,
            currency: 'IDR',
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
        $this->marketplaceOrder('order-blank');

        $this->expectException(InvalidArgumentException::class);

        $this->action->submit(
            reference: '   ',
            paymentMethod: 'bank_transfer',
            paymentReference: 'TRX-1',
            instructions: null,
            amountMinor: self::TOTAL_MINOR,
            currency: 'IDR',
            proofFile: null,
            actorRef: null,
            actorRole: 'guest',
            source: AuditSource::Api,
        );

        $this->assertSame(0, PaymentVerification::query()->count());
    }

    public function test_it_writes_an_audit_event_without_a_reason(): void
    {
        $this->marketplaceOrder('order-789');

        $verification = $this->action->submit(
            reference: 'order-789',
            paymentMethod: 'bank_transfer',
            paymentReference: 'TRX-1',
            instructions: null,
            amountMinor: self::TOTAL_MINOR,
            currency: 'IDR',
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
        $this->marketplaceOrder('order-fail');

        // An empty PDF fails DocumentValidator's own size/content checks —
        // any DocumentValidationException from inside the same
        // Audit::wrap() transaction must roll the created row back too.
        try {
            $this->action->submit(
                reference: 'order-fail',
                paymentMethod: 'bank_transfer',
                paymentReference: 'TRX-fail',
                instructions: null,
                amountMinor: self::TOTAL_MINOR,
                currency: 'IDR',
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

    // -----------------------------------------------------------------
    // PAY-02: real order linkage and a validated stated amount.
    // -----------------------------------------------------------------

    public function test_a_reference_that_does_not_resolve_to_a_real_order_is_refused_before_any_write(): void
    {
        $this->expectException(PaymentVerificationOrderNotFoundException::class);

        try {
            $this->action->submit(
                reference: 'no-such-order-number',
                paymentMethod: 'bank_transfer',
                paymentReference: 'TRX-1',
                instructions: null,
                amountMinor: self::TOTAL_MINOR,
                currency: 'IDR',
                proofFile: null,
                actorRef: null,
                actorRole: 'guest',
                source: AuditSource::Api,
            );
        } finally {
            $this->assertSame(0, PaymentVerification::query()->count());
            $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_SUBMITTED)->count());
        }
    }

    public function test_a_non_positive_amount_is_rejected(): void
    {
        $this->marketplaceOrder('order-zero');

        $this->expectException(InvalidArgumentException::class);

        $this->action->submit(
            reference: 'order-zero',
            paymentMethod: 'bank_transfer',
            paymentReference: 'TRX-1',
            instructions: null,
            amountMinor: 0,
            currency: 'IDR',
            proofFile: null,
            actorRef: null,
            actorRole: 'guest',
            source: AuditSource::Api,
        );
    }

    public function test_a_currency_other_than_the_configured_one_is_rejected(): void
    {
        $this->marketplaceOrder('order-usd');

        $this->expectException(InvalidArgumentException::class);

        $this->action->submit(
            reference: 'order-usd',
            paymentMethod: 'bank_transfer',
            paymentReference: 'TRX-1',
            instructions: null,
            amountMinor: self::TOTAL_MINOR,
            currency: 'USD',
            proofFile: null,
            actorRef: null,
            actorRole: 'guest',
            source: AuditSource::Api,
        );
    }

    public function test_a_stated_amount_that_differs_from_the_order_total_is_still_accepted_at_submission(): void
    {
        // Submission records the CUSTOMER's claim, whatever it is — the
        // amount is asserted against the real order total at APPROVAL time
        // (`VerifyManualPayment`), not here. A mismatch here would defeat
        // the whole point of asking: catching a customer who transferred
        // the wrong amount requires recording what they actually claim.
        $order = $this->marketplaceOrder('order-mismatch');

        $verification = $this->action->submit(
            reference: 'order-mismatch',
            paymentMethod: 'bank_transfer',
            paymentReference: 'TRX-1',
            instructions: null,
            amountMinor: self::TOTAL_MINOR - 1,
            currency: 'IDR',
            proofFile: null,
            actorRef: null,
            actorRole: 'guest',
            source: AuditSource::Api,
        );

        $this->assertSame(self::TOTAL_MINOR - 1, $verification->amount_minor);
        $this->assertSame($order->id, $verification->order_id);
    }

    public function test_it_never_references_payment_sessions_or_the_booking_order_aggregate(): void
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
