<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Exceptions\InvoiceReferenceCollisionException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderInvoice;
use App\Domain\OrderWorkflow\OrderWorkflowAuditActions;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The single writer of `order_invoices` — closes the "no invoice/receipt
 * concept exists" half of NOTIF-02 for the ONLINE-payment path.
 *
 * Called from `Actions\ApplyPaidEffects::apply()`, in the SAME transaction
 * as the `DIBAYAR` transition, right after `Order::stampPaidSource()` and
 * before `emitPaymentReceived()` (so the outbox row can carry the real
 * invoice reference — see that method). This is deliberate, not
 * incidental: a basic invoice/receipt is a financial artifact tied to the
 * order actually becoming paid, so it must commit or roll back WITH that
 * transition, never as a fire-and-forget side effect that could desync
 * from the order's real paid state (the task's own framing). Scoped to
 * the online/webhook path only — the manual-verification path
 * (`payment_verifications`) is separately blocked on PAY-02's missing
 * amount column and is explicitly out of scope here.
 *
 * ---------------------------------------------------------------------------
 * Deliberately minimal, per the task's own framing
 * ---------------------------------------------------------------------------
 * One row per order: a generated reference number, the paid amount and
 * currency (taken from the SAME `PaidTrigger` `ApplyPaidEffects` already
 * validated against the accepted quote — never re-derived), a single
 * summary line, and an issued timestamp. No multi-line-item model, no
 * tax calculation, no sequential legal invoice numbering scheme — none of
 * those were asked for, and guessing at Indonesian tax/invoice-numbering
 * compliance rules is explicitly out of scope for this task.
 *
 * ---------------------------------------------------------------------------
 * Idempotent two ways, matching this codebase's established shape
 * ---------------------------------------------------------------------------
 * 1. Read-before-write: an order that already has an invoice returns it
 *    unchanged — no second row, no second audit write. In practice this
 *    branch is rarely reached because `ApplyPaidEffects` itself only calls
 *    `apply()` (and so this Action) on the FIRST paid arrival; a replay
 *    returns early via its own `alreadyPaid()` branch and never reaches
 *    this Action again. This check exists as a second, independent
 *    backstop rather than the primary defence — see `order_invoices_
 *    order_id_unq` below, which is what actually makes it safe under a
 *    genuine race this read-then-write check cannot see.
 * 2. `order_invoices_order_id_unq` — the database backstop for the case
 *    the read-before-write check cannot close: two concurrent callers
 *    both passing the read. A collision on THAT index is translated into
 *    "return the existing row" (the `OrderAlreadyPaidException` /
 *    `AttachOrderDocument` shape), not an error — the same order asking
 *    for its invoice twice is not a failure.
 *
 * A collision on `order_invoices_reference_unq` (two different orders'
 * randomly generated references colliding) is a DIFFERENT kind of event —
 * not idempotency, an actual collision on a value that is supposed to be
 * unique per invoice — and is translated into
 * `InvoiceReferenceCollisionException` instead, the same distinction
 * `Actions\AgreementCertificate\IssueCertificate` draws between its own
 * two unique indexes.
 */
final readonly class IssueInvoice
{
    public function __invoke(Order $order, PaidTrigger $trigger): OrderInvoice
    {
        $existing = $this->findExisting($order);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->record($order, $trigger);
        } catch (QueryException $exception) {
            if ($this->isDuplicateOrderInvoice($exception)) {
                return $this->findExisting($order) ?? throw $exception;
            }

            if ($this->isDuplicateReference($exception)) {
                // Deliberately not chained — see this class's own doc
                // block and `CertificateReferenceCollisionException`'s for
                // why the raw `QueryException` (its message interpolates
                // the INSERT bindings) is never attached as `$previous`.
                throw InvoiceReferenceCollisionException::forOrder((string) $order->getKey());
            }

            throw $exception;
        }
    }

    private function record(Order $order, PaidTrigger $trigger): OrderInvoice
    {
        return Audit::wrap(
            mutation: fn (): OrderInvoice => OrderInvoice::query()->create([
                'order_id' => $order->getKey(),
                'reference' => 'INV-'.Str::upper(Str::random(10)),
                'amount_minor' => $trigger->amount->toMinorInt(),
                'currency' => $trigger->currency,
                'summary' => "Order {$order->reference} ({$order->product_type})",
                'issued_at' => CarbonImmutable::now(),
            ]),
            action: OrderWorkflowAuditActions::INVOICE_ISSUED,
            subject: fn (OrderInvoice $invoice): AuditSubject => new AuditSubject(
                'order_invoice',
                $invoice->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $trigger->actorRef,
            actorRole: $trigger->actorRole,
            source: AuditSource::Api,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    private function findExisting(Order $order): ?OrderInvoice
    {
        return OrderInvoice::query()->where('order_id', $order->getKey())->first();
    }

    /**
     * Same narrow-matching shape
     * `Actions\AgreementCertificate\IssueCertificate::isDuplicateReference()`
     * documents: a bare column/index-name substring match would also
     * classify an unrelated NOT NULL or length violation as a duplicate.
     */
    private function isDuplicateOrderInvoice(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'order_invoices_order_id_unq')) {
            return true;
        }

        return str_contains($message, 'unique') && str_contains($message, 'order_invoices.order_id');
    }

    private function isDuplicateReference(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'order_invoices_reference_unq')) {
            return true;
        }

        return str_contains($message, 'unique') && str_contains($message, 'order_invoices.reference');
    }
}
