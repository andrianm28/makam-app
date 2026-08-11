<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\DocumentVault\Actions\UploadDocument;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\Payment\Models\PaymentVerification;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * Task 5's `SubmitManualPayment` — the customer-facing half of the safe
 * slice defined by the Wave 1c Append-Correction
 * (`.superpowers/sdd/2026-08-09-platform-payment-adapter/task-5-brief.md`).
 *
 * Creates a `PaymentVerification` row at `status = SUBMITTED` and, when a
 * proof file is supplied, routes it through the document vault's
 * quarantine-first upload seam (`Actions\UploadDocument::upload()`,
 * `DocumentKind::PaymentProof`) — never stores the file itself, only the
 * resulting `documents.id` reference.
 *
 * ---------------------------------------------------------------------------
 * Deliberately does NOT touch `payment_sessions`, `PaymentSession`,
 * `SessionState`, or any `app/Domain/OrderWorkflow/` file
 * ---------------------------------------------------------------------------
 * The original plan's Task 5 text asked this class to also set a
 * `MENUNGGU_VERIFIKASI_PEMBAYARAN` session/order status. The Wave 1c ruling
 * found that write has no real target — `GuardResult::isAllowed()` is
 * always false so no `payment_sessions` row can exist, and
 * `app/Domain/OrderWorkflow/` is empty — and forbids fabricating one. This
 * class writes nothing beyond its own `payment_verifications` row and its
 * own audit event. `tests/Feature/Payment/SubmitManualPaymentTest.php`
 * grep-asserts this file contains none of those references.
 */
final readonly class SubmitManualPayment
{
    public function __construct(
        private UploadDocument $uploadDocument,
    ) {}

    /**
     * @param  array<string, mixed>  $proofMeta  Passed straight through to
     *                                           `UploadDocument::upload()`'s own `$meta` — recognized keys
     *                                           `original_filename`/`mime_declared`, required when `$proofFile`
     *                                           is a `StreamInterface`. Ignored when `$proofFile` is null.
     */
    public function submit(
        string $reference,
        string $paymentMethod,
        string $paymentReference,
        ?string $instructions,
        UploadedFile|StreamInterface|null $proofFile,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
        ?string $clientUploadId = null,
        array $proofMeta = [],
    ): PaymentVerification {
        $this->assertNotBlank($reference, 'reference');
        $this->assertNotBlank($paymentMethod, 'payment_method');
        $this->assertNotBlank($paymentReference, 'payment_reference');

        return Audit::wrap(
            mutation: function () use (
                $reference,
                $paymentMethod,
                $paymentReference,
                $instructions,
                $proofFile,
                $clientUploadId,
                $proofMeta,
            ): PaymentVerification {
                $verification = PaymentVerification::createSubmitted([
                    'reference' => $reference,
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $paymentReference,
                    'instructions' => $instructions,
                ]);

                if ($proofFile !== null) {
                    $document = $this->uploadDocument->upload(
                        DocumentKind::PaymentProof,
                        $proofFile,
                        'payment_verification',
                        $verification->id,
                        $clientUploadId,
                        $proofMeta,
                    );

                    $verification->attachProof($document->id);
                }

                return $verification;
            },
            action: PaymentAuditActions::MANUAL_SUBMITTED,
            subject: fn (PaymentVerification $verification): AuditSubject => new AuditSubject('payment_verification', $verification->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
        );
    }

    private function assertNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("\${$field} must not be blank.");
        }
    }
}
