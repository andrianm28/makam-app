<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

/**
 * The two — and only two — ways an order can become paid in this system.
 *
 * A closed list rather than a free string, for the same reason `OrderStatus`,
 * `ProductType` and `QuoteStatus` are closed lists: `orders.paid_via` is a
 * money-path column, and "which mechanism confirmed this payment" is a
 * question whose answer set is fixed by the product, not by whichever caller
 * happened to write the row. The backed values are what land in
 * `orders.paid_via` and in the `payment.received.v1` payload.
 *
 * `Webhook` is the online provider path
 * (`App\Platform\Payment\ProcessWebhookEvent`); `ManualVerification` is the
 * Step 8 fallback path
 * (`App\Platform\Payment\Http\Controllers\VerifyManualPaymentController`).
 * Neither is wired to `Actions\ApplyPaidEffects` yet — see that Action's doc
 * block for exactly why, and `task-7-report.md` for the NOT TESTED record.
 */
enum PaidTriggerSource: string
{
    case Webhook = 'webhook';

    case ManualVerification = 'manual_verification';
}
