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
 */
enum OrderType: string
{
    /** An order-workflow `Order` (the booking domain). */
    case Booking = 'booking';

    /** A `MarketplaceOrder` (the marketplace domain). Deferred — see above. */
    case Marketplace = 'marketplace';
}
