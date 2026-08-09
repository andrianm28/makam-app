<?php

declare(strict_types=1);

use App\Platform\Payment\Http\Controllers\WebhookController;
use App\Platform\Payment\Http\Middleware\RedactProviderPayload;
use App\Platform\Payment\Providers\PaymentServiceProvider;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Machine-to-machine routes
|--------------------------------------------------------------------------
| Registered by bootstrap/app.php under the `api` prefix and middleware group.
| These are provider/system callbacks, not browser routes: no session, no CSRF
| token, no cookies. Nothing user-facing belongs here — public UX routes live
| in routes/web.php.
|
| This file exists as of the platform-payment-adapter lane (Task 3, 10 Aug
| 2026); before it, the application had no API surface at all.
*/

/*
|--------------------------------------------------------------------------
| Payment provider webhook — platform-payment-adapter AC5, AC6, AC13, AC14
|--------------------------------------------------------------------------
| ADR-0033's SumoPod sandbox posts `payment.completed|failed|expired|test`
| here. The endpoint is unauthenticated BY NATURE — a payment provider holds no
| credential of ours — so authenticity is established by the signature
| (`Providers\SumoPodWebhookSignature`) and abuse is bounded by the throttle,
| never the other way round.
|
| Middleware order matters and is deliberate:
|   1. `throttle:payment-webhook` — refuse a flood before anything is read or
|      written. `ReceiveWebhook` persists a durable row before it can
|      authenticate anything (AC5 requires that ordering), so this is the only
|      thing standing between an anonymous caller and unbounded writes. See
|      `Providers\PaymentServiceProvider` for the per-merchant+IP key.
|   2. `RedactProviderPayload` — lift the credential headers off the request
|      before any handler, logger, or exception renderer can see them (AC14).
|
| The `{merchant}` pattern rejects a hostile path segment with a 404 that
| stores nothing. It is a syntax gate only; which merchants this environment
| actually answers for is `config('payment.webhook.merchants')`, checked in
| `WebhookValidator`, and the authoritative binding check is against
| `payment_sessions.merchant_ref` (AC13).
*/

Route::post('payments/webhook/{merchant}', WebhookController::class)
    ->middleware([
        'throttle:'.PaymentServiceProvider::WEBHOOK_LIMITER,
        RedactProviderPayload::class,
    ])
    ->where('merchant', '[A-Za-z0-9][A-Za-z0-9_-]{0,63}')
    ->name('payments.webhook');
