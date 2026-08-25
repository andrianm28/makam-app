<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http\Controllers;

use App\Platform\Payment\ReturnPageState;
use Illuminate\Contracts\View\View;

/**
 * `GET /pembayaran/kembali` — ADR-0033's `success_return_url`.
 *
 * ---------------------------------------------------------------------------
 * This controller renders a view and reads two display-only lookup keys.
 * That is the entire feature, and the write-safety is structural
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
 * webhook or an approved manual verification.
 *
 * The two query keys read below (`session`, `payment_id`) are passed to
 * `ReturnPageState::fromRequest()`, whose doc block records the trust model:
 * they are display-only SELECTORS — which session the page describes — while
 * the copy itself is always decided by the session row's own
 * webhook-written state. No parameter ever decides, or can decide, what the
 * page claims about a payment. And nothing here writes: no constructor
 * dependency, no action, no event, no transition — the same absence the
 * structural test in `Tests\Feature\Payment\PaymentReturnRouteTest` pins.
 */
final class PaymentReturnController
{
    public function __invoke(): View
    {
        $sessionKey = self::stringQuery('session');
        $providerPaymentId = self::stringQuery('payment_id');

        $state = ReturnPageState::fromRequest($sessionKey, $providerPaymentId);

        return view('payment.return', ['returnState' => $state]);
    }

    private static function stringQuery(string $key): ?string
    {
        $value = request()->query($key);

        return is_string($value) ? $value : null;
    }
}
