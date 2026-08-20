<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * Which downstream aggregate a payment session settles — the discriminator
 * `OpenPaymentSessionCommand` carries so the session-creation path and the
 * webhook router (Task 5) dispatch without coupling the booking and
 * marketplace domains.
 *
 * ---------------------------------------------------------------------------
 * `Marketplace` exists as a closed case but has no production path yet
 * ---------------------------------------------------------------------------
 * Task 4 of the online-payment gateway plan implements booking orders only:
 * `OpenPaymentSession` refuses a `Marketplace` command with
 * `PaymentSessionOrderTypeNotSupportedException` until the marketplace
 * precondition path lands (a follow-up, per the plan's escalation clause —
 * the six-condition guard is `Order`/`Quote`-typed and the marketplace has
 * no `Quote`, no `AuthorizeOrderPaymentOpening` analog, and no accepted
 * session shape). The case is declared NOW so the follow-up and the Task 5
 * router can code against the closed enum instead of inventing a string.
 *
 * ---------------------------------------------------------------------------
 * `CareSubscription` resolves a `SubscriptionCycle`, keyed by its own id
 * ---------------------------------------------------------------------------
 * See `Actions\ApplyPaymentSettlement`'s doc block, "Care subscription"
 * section, for the full resolution design. In short: `subscription_invoices`
 * carries no business reference column comparable to `orders.reference` /
 * `MarketplaceOrder.order_number`, so a `SubscriptionCycle`'s own UUID
 * primary key IS its `orderRef` for this case. Like `Marketplace` above, no
 * session-opening producer sends `OrderType::CareSubscription` yet — this
 * case and the settlement branch that resolves it are declared now so the
 * closed-list/router shape exists before the producer does.
 */
enum OrderType: string
{
    /** An order-workflow `Order` (the booking domain). */
    case Booking = 'booking';

    /** A `MarketplaceOrder` (the marketplace domain). Deferred — see above. */
    case Marketplace = 'marketplace';

    /** A `SubscriptionCycle` (the recurring-care-subscriptions domain). Deferred — see above. */
    case CareSubscription = 'care_subscription';
}
