<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * The `provider_events.status` closed list.
 *
 * `docs/contracts/payment-webhook.md` §Failure states is the source. Its nine
 * values are reproduced here verbatim; four more are added because AC6 names
 * five distinct things every webhook must be validated against ("signature,
 * merchant scope, amount, currency, and replay window") and requires that a
 * failure be *recorded*, not merely rejected — a rejection recorded under a
 * status that names the wrong cause is not a usable record. The contract
 * document carries a matching amendment (same date as this file); it is the
 * canonical list and this enum follows it, never the other way round.
 *
 * | Value                | Origin                                            |
 * |----------------------|---------------------------------------------------|
 * | RECEIVED             | contract                                          |
 * | VALIDATED            | contract                                          |
 * | PROCESSED            | contract (written by Task 4, never by the receiver)|
 * | DUPLICATE            | contract                                          |
 * | REJECTED_SIGNATURE   | contract                                          |
 * | REJECTED_MERCHANT    | contract                                          |
 * | REJECTED_AMOUNT      | contract                                          |
 * | RETRYABLE_FAILURE    | contract (Task 4)                                 |
 * | MANUAL_REVIEW        | contract (Task 4)                                 |
 * | REJECTED_PAYLOAD     | added — envelope unparseable/incomplete           |
 * | REJECTED_REPLAY      | added — AC6 "replay window"                       |
 * | REJECTED_CURRENCY    | added — AC6 "currency"                            |
 * | REJECTED_SESSION     | added — no `payment_sessions` row to bind against |
 *
 * `REJECTED_SESSION` is the status every well-formed, correctly signed webhook
 * reaches today: Wave 1b ruling 1b-L3-01 made the payment guard deny-only, so
 * no `payment_sessions` row can exist, so AC13's merchant/`badan_usaha`
 * reconciliation and AC6's amount check have nothing to reconcile against.
 * That is fail-closed behaviour working as designed, not a defect — see
 * `WebhookValidator`.
 */
enum ProviderEventStatus: string
{
    case Received = 'RECEIVED';
    case Validated = 'VALIDATED';
    case Processed = 'PROCESSED';
    case Duplicate = 'DUPLICATE';
    case RejectedPayload = 'REJECTED_PAYLOAD';
    case RejectedSignature = 'REJECTED_SIGNATURE';
    case RejectedReplay = 'REJECTED_REPLAY';
    case RejectedMerchant = 'REJECTED_MERCHANT';
    case RejectedSession = 'REJECTED_SESSION';
    case RejectedCurrency = 'REJECTED_CURRENCY';
    case RejectedAmount = 'REJECTED_AMOUNT';
    case RetryableFailure = 'RETRYABLE_FAILURE';
    case ManualReview = 'MANUAL_REVIEW';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isRejection(): bool
    {
        return str_starts_with($this->value, 'REJECTED_');
    }
}
