<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\DocumentVault\Actions\UploadDocument;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\Payment\Exceptions\PaymentVerificationOrderNotFoundException;
use App\Platform\Payment\Models\PaymentVerification;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * Task 5's `SubmitManualPayment` — the customer-facing half of the safe
 * slice defined by the Wave 1c Append-Correction
 * (`.superpowers/sdd/2026-08-09-platform-payment-adapter/task-5-brief.md`),
 * later closed for real order-linkage and amount by PAY-02
 * (`docs/testing/release-gates.md` §C).
 *
 * Creates a `PaymentVerification` row at `status = SUBMITTED` and, when a
 * proof file is supplied, routes it through the document vault's
 * quarantine-first upload seam (`Actions\UploadDocument::upload()`,
 * `DocumentKind::PaymentProof`) — never stores the file itself, only the
 * resulting `documents.id` reference.
 *
 * ---------------------------------------------------------------------------
 * PAY-02: `$reference` must now resolve to a real order, and the customer
 * states an amount
 * ---------------------------------------------------------------------------
 * `payment_verifications.order_id` is a real foreign key
 * (`2026_08_26_120000_add_order_link_and_amount_to_payment_verifications_
 * table.php`), scoped to `marketplace_orders` — that migration's own doc
 * block records the investigation confirming this class's only real caller
 * (`App\Livewire\Public\Marketplace\Checkout::submitManualProof()`) always
 * submits a marketplace order's `order_number` as `$reference`; the booking
 * wizard's Step 8 manual-payment card never reaches this class at all. A
 * `$reference` that does not resolve to a real `MarketplaceOrder` is refused
 * BEFORE `Audit::wrap()` opens — same "caller bug, not a guard denial, no
 * row written" posture `PaymentSessionOrderNotFoundException` already
 * documents for the online-payment leg.
 *
 * `$amountMinor`/`$currency` are the customer's OWN stated amount — the
 * money they say they transferred, in the SAME integer-minor-unit +
 * currency convention `Actions\ApplyPaidEffects`/`PaidTrigger` already use
 * on the booking leg. This is deliberately NOT re-derived from the order's
 * `total_minor`: the whole point of asking is to catch a customer who
 * transferred the wrong amount, which an admin can only see if the
 * customer's own claim is recorded rather than assumed correct.
 * `VerifyManualPayment` is what actually asserts this amount against the
 * order's real total, at approval time — this class only validates that the
 * stated amount is well-formed (positive, in the platform's one configured
 * currency) and records it.
 *
 * ---------------------------------------------------------------------------
 * Deliberately does NOT touch `payment_sessions`, `PaymentSession`,
 * `SessionState`, or any `app/Domain/OrderWorkflow/` file
 * ---------------------------------------------------------------------------
 * The original plan's Task 5 text asked this class to also set a
 * `MENUNGGU_VERIFIKASI_PEMBAYARAN` session/order status. The Wave 1c ruling
 * found that write has no real target — `GuardResult::isAllowed()` is
 * always false so no `payment_sessions` row can exist, and
 * `app/Domain/OrderWorkflow/` is empty — and forbids fabricating one. PAY-02
 * does not revisit that ruling: it wires this table to `marketplace_orders`,
 * a table `App\Domain\Marketplace` owns, never to `app/Domain/OrderWorkflow/`.
 * This class still writes nothing beyond its own `payment_verifications`
 * row and its own audit event. `tests/Feature/Payment/
 * SubmitManualPaymentTest.php` grep-asserts this file contains none of
 * those references.
 */
final readonly class SubmitManualPayment
{
    public function __construct(
        private UploadDocument $uploadDocument,
    ) {}

    /**
     * @param  int  $amountMinor  The customer's own stated payment amount, integer minor units.
     *                            Must be strictly positive.
     * @param  string  $currency  Must equal `config('money.currency')` — this platform
     *                            configures exactly one currency; a mismatch is refused rather
     *                            than silently accepted.
     * @param  array<string, mixed>  $proofMeta  Passed straight through to
     *                                           `UploadDocument::upload()`'s own `$meta` — recognized keys
     *                                           `original_filename`/`mime_declared`, required when `$proofFile`
     *                                           is a `StreamInterface`. Ignored when `$proofFile` is null.
     *
     * @throws PaymentVerificationOrderNotFoundException when `$reference` does not match any
     *                                                   `marketplace_orders.order_number`.
     */
    public function submit(
        string $reference,
        string $paymentMethod,
        string $paymentReference,
        ?string $instructions,
        int $amountMinor,
        string $currency,
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
        $this->assertPositive($amountMinor);
        $currency = $this->assertConfiguredCurrency($currency);

        $order = MarketplaceOrder::query()->where('order_number', $reference)->first();

        if (! $order instanceof MarketplaceOrder) {
            throw PaymentVerificationOrderNotFoundException::forReference($reference);
        }

        return Audit::wrap(
            mutation: function () use (
                $reference,
                $order,
                $paymentMethod,
                $paymentReference,
                $instructions,
                $amountMinor,
                $currency,
                $proofFile,
                $clientUploadId,
                $proofMeta,
            ): PaymentVerification {
                $verification = PaymentVerification::createSubmitted([
                    'reference' => $reference,
                    'order_id' => $order->id,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
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

    private function assertPositive(int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('$amountMinor must be a positive integer.');
        }
    }

    /**
     * Same "one configured currency, refuse anything else" check
     * `WebhookValidator` already applies to the online-payment leg.
     */
    private function assertConfiguredCurrency(string $currency): string
    {
        $currency = trim($currency);
        $expected = (string) config('money.currency');

        if ($currency === '' || strcasecmp($currency, $expected) !== 0) {
            throw new InvalidArgumentException(
                "\$currency must be the platform's configured currency [{$expected}]; got [{$currency}]."
            );
        }

        return $expected;
    }
}
