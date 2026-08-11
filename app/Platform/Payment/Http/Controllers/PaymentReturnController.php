<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * `GET /pembayaran/kembali` — ADR-0033's `success_return_url`.
 *
 * ---------------------------------------------------------------------------
 * This class renders a view. That is the entire feature, and it is deliberate
 * ---------------------------------------------------------------------------
 * `AGENTS.md` §Domain and financial invariants: "Never mark paid from browser
 * return URL." Requirements AC4: "THE SYSTEM SHALL set paid state only via a
 * validated webhook or an approved manual verification. THE SYSTEM SHALL NOT
 * set paid state from a browser return URL."
 *
 * The name `success_return_url` is the provider's word for "where to send the
 * browser afterwards", not an assertion that anything succeeded. This endpoint
 * is reached by a CUSTOMER'S BROWSER following a redirect: the visitor controls
 * the URL and every query parameter on it, may arrive having paid nothing, and
 * may never arrive at all after a genuine payment. Nothing it presents is
 * evidence. The only admissible evidence is a signature-verified provider
 * webhook (`App\Platform\Payment\ReceiveWebhook`) or an approved manual
 * verification.
 *
 * So the safety property here is an ABSENCE, and it is enforced structurally
 * rather than by discipline: this controller has no constructor dependency, no
 * model, no query, no action, no event. There is no object in scope through
 * which a state transition, a journal post, an outbox emission, or a "paid"
 * claim could be reached, and `Tests\Feature\Payment\PaymentReturnRouteTest`
 * fails if any of those names so much as appears in this file's code.
 *
 * The request is not even read: no query parameter is inspected, none is passed
 * to the view, and none is echoed back. A reflected `?status=paid` would dress
 * an attacker-supplied string up as something this system had confirmed.
 *
 * ---------------------------------------------------------------------------
 * Why the page cannot yet say anything about a specific payment
 * ---------------------------------------------------------------------------
 * Even the honest version of this screen — "we are checking session X" — needs
 * a `payment_sessions` row to look up, and under Wave 1b ruling 1b-L3-01 no such
 * row can exist. The view therefore explains the process without claiming
 * anything about an individual transaction. That is a truthful screen today and
 * stays truthful later: whoever adds a session lookup must add it as a READ, and
 * must not weaken the invariant this file exists to hold.
 */
final class PaymentReturnController
{
    public function __invoke(): View
    {
        return view('payment.return');
    }
}
