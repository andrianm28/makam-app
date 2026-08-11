<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Audit\AuditSource;
use App\Platform\Payment\Actions\RecordChargeback;
use App\Platform\Payment\Actions\RecordRefund;
use App\Platform\Payment\Models\PaymentReversal;

/**
 * Task 6's `ReversalService` — a thin dispatcher, matching the plan's own
 * "Files: `ReversalService.php` ... `Actions/` per type" list (Wave 1d
 * Append-Correction,
 * `.superpowers/sdd/2026-08-09-platform-payment-adapter/task-6-brief.md`).
 *
 * Routes to the right Action by `PaymentReversalType` and nothing else —
 * all the actual behaviour (writing the `PaymentReversal` row, the
 * mandatory-reason audit, the duplicate-reference domain exception) lives
 * in `Actions\RecordRefund`/`Actions\RecordChargeback` themselves. This
 * class exists so `Http\Controllers\RecordPaymentReversalController` (and
 * any future caller) has one entry point regardless of which reversal type
 * was requested, rather than branching on the type itself.
 */
final readonly class ReversalService
{
    public function __construct(
        private RecordRefund $recordRefund,
        private RecordChargeback $recordChargeback,
    ) {}

    public function record(
        PaymentReversalType $type,
        string $reference,
        ?int $amountMinor,
        string $reason,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
    ): PaymentReversal {
        return match ($type) {
            PaymentReversalType::Refund => $this->recordRefund->handle(
                reference: $reference,
                amountMinor: $amountMinor,
                reason: $reason,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
            ),
            PaymentReversalType::Chargeback => $this->recordChargeback->handle(
                reference: $reference,
                amountMinor: $amountMinor,
                reason: $reason,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
            ),
        };
    }
}
