<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http\Controllers;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Payment\Http\InboundWebhook;
use App\Platform\Payment\Http\WebhookCredentials;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ReceiveWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/payments/webhook/{merchant}` — the merchant-scoped endpoint AC13
 * requires, and the first public HTTP entry point this lane has.
 *
 * ---------------------------------------------------------------------------
 * Adapter only
 * ---------------------------------------------------------------------------
 * `AGENTS.md` §Architecture: "Keep domain logic outside controllers." This
 * class translates an `Illuminate\Http\Request` into an `InboundWebhook` and a
 * `ReceiveWebhookResult` back into a response. It makes no decision: not about
 * validity, not about idempotency, not about what to store. Everything that
 * could be wrong about a webhook is decided in `ReceiveWebhook` /
 * `WebhookValidator`, which are exercisable without an HTTP kernel.
 *
 * ---------------------------------------------------------------------------
 * The response says the same thing to everyone
 * ---------------------------------------------------------------------------
 * Body and status are identical for a valid delivery, a forged signature, an
 * unknown merchant, a mismatched amount and a duplicate. See
 * `ReceiveWebhookResult` for why: a response that varied with the reason would
 * let an unauthenticated caller enumerate merchants, transactions and amounts.
 * The truth about what happened lives in `provider_events` and `audit_events`,
 * which is where AC6 puts it.
 *
 * ---------------------------------------------------------------------------
 * What protects this route, given that it cannot authenticate its caller
 * ---------------------------------------------------------------------------
 *  - `throttle:payment-webhook` (registered in `Providers\PaymentServiceProvider`,
 *    keyed per merchant + client IP) — FIRST in the middleware list, so a flood
 *    is refused before a body is even read.
 *  - `RedactProviderPayload` — strips credential headers off the request before
 *    any handler can log them (AC14).
 *  - A route-level pattern on `{merchant}`, so a hostile path segment is a 404
 *    that stores nothing.
 *  - A body-size cap and the durable-row design inside `ReceiveWebhook`.
 *
 * The raw body is read with `getContent()`, never `$request->json()`/`all()`:
 * the signature is computed over the exact bytes received, and a body Laravel
 * has normalised is not those bytes.
 */
final readonly class WebhookController
{
    public function __construct(
        private ReceiveWebhook $receive,
        private CorrelationContext $correlation,
    ) {}

    public function __invoke(Request $request, string $merchant, WebhookCredentials $credentials): JsonResponse
    {
        $correlationId = $this->correlation->current();

        $result = ($this->receive)(new InboundWebhook(
            provider: (string) config('payment.default', PaymentProviders::SUMOPOD_SANDBOX),
            merchantRef: $merchant,
            rawBody: $request->getContent(),
            credentials: $credentials,
            // `svix-id` and `svix-timestamp` are NOT credentials — they are
            // the public, signature-covered parts of the Svix envelope — so
            // `RedactProviderPayload` deliberately leaves them on the request.
            svixId: $this->headerOrNull($request, 'svix-id'),
            svixTimestamp: $this->headerOrNull($request, 'svix-timestamp'),
            receivedAt: CarbonImmutable::now(),
            correlationId: $correlationId === null ? null : (string) $correlationId,
        ));

        return new JsonResponse(
            [
                'status' => 'received',
                'reference' => $result->reference,
            ],
            $result->httpStatus,
        );
    }

    private function headerOrNull(Request $request, string $header): ?string
    {
        $value = $request->header($header);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
