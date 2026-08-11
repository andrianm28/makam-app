<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * `GET /pembayaran/batal` — ADR-0033's `cancel_return_url`.
 *
 * The mirror of `PaymentReturnController`, and a SEPARATE class on purpose
 * rather than a second method or a branch on a query parameter. Read that
 * class's doc block first: the reasoning about untrusted browser returns is the
 * same and is not repeated here.
 *
 * What is specific to this route is the symmetry of the risk. A cancel return is
 * as untrusted as a success return, in the opposite direction: a visitor who
 * DID pay can be redirected here (by a mis-configured provider, a back button,
 * or an attacker-crafted link) while the webhook that settles the payment is
 * still in flight. So this endpoint must not cancel, expire, fail, or release
 * anything either — a browser return is not evidence that a payment did not
 * happen, any more than it is evidence that one did. It renders a page, and the
 * provider's own webhook remains the only thing that moves state in either
 * direction.
 *
 * Same structural enforcement: no dependency, no model, no query, no action, and
 * a test that fails if any write-shaped name appears in this file's code.
 */
final class PaymentCancelController
{
    public function __invoke(): View
    {
        return view('payment.cancel');
    }
}
