<?php

declare(strict_types=1);

use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Payment\PaymentProviders;

/**
 * AC14: "THE SYSTEM SHALL source provider credentials from secret management,
 * scoped per environment. THE SYSTEM SHALL NOT let credentials appear in logs
 * or error trackers."
 *
 * This file is the ONLY place the module reads a provider credential from the
 * environment. Nothing else in `app/Platform/Payment/**` may call `env()`:
 * `env()` returns null once `config:cache` has run, so a credential read
 * anywhere but here is a production-only null.
 *
 * ---------------------------------------------------------------------------
 * The variable names are copied verbatim from ADR-0033 and are NOT typos to fix
 * ---------------------------------------------------------------------------
 * ADR-0033 §Credential: "the variable name is `SUMODOP_SANDBOX_API_KEY`,
 * injected only into the host `/opt/makam/compose/.env.dev` (protected
 * injection)." The value is already injected under that exact spelling;
 * "correcting" it to SUMOPOD_* here would silently read an unset variable and
 * fail closed at the worst possible moment. `SUMODOP_SANDBOX_WEBHOOK_SECRET` is
 * the spelling the implementation plan's AC14 row uses for the signing secret.
 * The ADR-0033 shared-token alternative is disabled in this lane because it has
 * no authenticated freshness signal.
 *
 * Every credential default is empty. A missing credential must fail closed
 * (every webhook rejected as `REJECTED_SIGNATURE`), never fall back to a
 * value baked into the repository.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Active provider
    |--------------------------------------------------------------------------
    |
    | ADR-0033: SumoPod sandbox is the dev/staging provider. Production
    | activation is a separate decision — gate `G-PAY-01` stays closed there
    | and `PaymentMode` is resolved server-side from that gate, never from this
    | value. This key selects WHICH provider's credentials and slug are in use,
    | not WHETHER online payment is available.
    |
    */

    'default' => env('PAYMENT_PROVIDER', PaymentProviders::SUMOPOD_SANDBOX),

    'providers' => [

        PaymentProviders::SUMOPOD_SANDBOX => [

            'base_url' => env('SUMOPOD_SANDBOX_BASE_URL', 'https://api-pay-sandbox.sumopod.com'),

            /*
             | ADR-0033: `POST /api/v1/payments` with an `X-Api-Key` header.
             | Read by the session-creation path (Task 2/Task 8), never by the
             | webhook receiver — an inbound webhook is authenticated by the
             | signing secret or the shared token below, and this key must
             | never appear on the inbound path at all.
             */
            'api_key' => env('SUMODOP_SANDBOX_API_KEY', ''),

            /*
             | `payment-webhook.md` §Security: "Verify provider signature using
             | current and rotating secret support." A comma-separated list, so
             | a rotation can carry the new and the old secret at once without
             | a code change or a second variable. Every entry is tried, in
             | order, with a constant-time comparison.
             */
            'webhook_signing_secrets' => array_values(array_filter(
                array_map('trim', explode(',', (string) env('SUMODOP_SANDBOX_WEBHOOK_SECRET', ''))),
                static fn (string $secret): bool => $secret !== '',
            )),

            // Shared-token verification is disabled until an authenticated
            // freshness contract exists. Do not load token material into the
            // effective payment configuration in the meantime.
            'webhook_tokens' => [],
        ],
    ],

    'webhook' => [

        /*
        |----------------------------------------------------------------------
        | Merchant scope (AC13)
        |----------------------------------------------------------------------
        |
        | The merchant references this deployment will accept on
        | `POST /api/payments/webhook/{merchant}`. Comma-separated; EMPTY BY
        | DEFAULT, which rejects every merchant — the correct posture for an
        | environment that has not been told which merchant it serves.
        |
        | This is deliberately NOT a merchant registry. Wave 1b ruling
        | 1b-L3-02 assigns the merchant registry to
        | `booking-and-order-orchestration`, and this lane owns no merchant
        | model. This is environment-scoped configuration answering only
        | "which merchant endpoints does THIS deployment answer for", which is
        | the same shape ADR-0033 asks of every provider setting ("L3 must load
        | them from environment … so a provider switch is config-only"). Once
        | the registry exists, the authoritative binding check is the one
        | already implemented in `WebhookValidator` against
        | `payment_sessions.merchant_ref`/`badan_usaha_ref`; this list stays as
        | the cheap outer boundary that stops an unknown merchant before any
        | lookup.
        |
        */

        'merchants' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('PAYMENT_WEBHOOK_MERCHANTS', ''))),
            static fn (string $merchant): bool => $merchant !== '',
        )),

        /*
         | AC6's replay window. `payment-webhook.md` §Security: "Enforce
         | acceptable timestamp skew if provider supports it." Svix's own
         | default tolerance is five minutes; matched here.
         */
        'replay_window_seconds' => (int) env('PAYMENT_WEBHOOK_REPLAY_WINDOW_SECONDS', 300),

        /*
         | ADR-0033's envelope is a few hundred bytes. The cap exists because
         | this endpoint persists a row per request before it can authenticate
         | anything, so the row must be bounded — see the throttle below, which
         | bounds the number of them.
         */
        'max_body_bytes' => (int) env('PAYMENT_WEBHOOK_MAX_BODY_BYTES', 65536),

        /*
        |----------------------------------------------------------------------
        | Shared-token verification is disabled
        |----------------------------------------------------------------------
        |
         | ADR-0033 names a shared `X-Webhook-Token` alternative, but this lane
         | does not enable it: a caller-supplied timestamp is not a trusted
         | freshness signal and cannot prevent replay.
        |
         | The effective value is a code-level false, not an environment switch.
        |
        */

        'allow_shared_token' => false,

        /*
         | `queue-and-outbox.md` §2 / the plan: webhook application runs on
         | `critical` so imports/reports can never starve it.
         */
        'queue' => OutboxQueueName::Critical->value,

        /*
        |----------------------------------------------------------------------
        | Rate limit (binding forward constraint from the Task 2 review)
        |----------------------------------------------------------------------
        |
        | "The task that wires the first HTTP caller MUST require an
        | authenticated actor and/or throttle before the guard is invoked."
        | A provider webhook cannot be authenticated at the framework layer —
        | the provider holds no credential of ours — so throttling is the whole
        | of the answer here, and signature verification does not substitute
        | for it: the durable row is written BEFORE the signature is checked,
        | by design (AC5), so an unsigned flood still costs one row per request.
        |
        | Keyed per merchant + client IP (see `Providers\PaymentServiceProvider`).
        | The default is generous relative to real webhook volume — ADR-0033's
        | provider sends one event per payment transition — and a throttled
        | delivery is not lost: a 429 is a non-2xx, which ADR-0033 says the
        | provider marks failed and can resend.
        |
        */

        'throttle' => [
            'max_attempts' => (int) env('PAYMENT_WEBHOOK_THROTTLE_MAX_ATTEMPTS', 60),
            'decay_seconds' => (int) env('PAYMENT_WEBHOOK_THROTTLE_DECAY_SECONDS', 60),
        ],
    ],
];
