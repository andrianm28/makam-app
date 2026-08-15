<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http\Controllers;

use App\Platform\Payment\ReturnPageState;
use Illuminate\Contracts\View\View;

/**
 * `GET /pembayaran/batal` — ADR-0033's `cancel_return_url`.
 *
 * The risk is symmetric to `PaymentReturnController`'s: a visitor who DID pay
 * can land here — a back button, a mis-configured provider redirect, an
 * attacker-crafted link — while the webhook that settles the payment is still
 * in flight. So this page must not tell them the payment failed, was
 * cancelled, or was released, any more than the success page may tell them it
 * succeeded. The copy is decided by `ReturnPageState` from the session row's
 * own webhook-written state (read the sibling controller's doc block and
 * `ReturnPageState`'s for the display-only trust model); the page itself
 * writes nothing, structurally — the same absence `Tests\Feature\Payment\
 * PaymentReturnRouteTest` pins for both return routes.
 */
final class PaymentCancelController
{
    public function __invoke(): View
    {
        $sessionKey = self::stringQuery('session');
        $providerPaymentId = self::stringQuery('payment_id');

        $state = ReturnPageState::fromRequest($sessionKey, $providerPaymentId);

        return view('payment.cancel', ['returnState' => $state]);
    }

    private static function stringQuery(string $key): ?string
    {
        $value = request()->query($key);

        return is_string($value) ? $value : null;
    }
}
