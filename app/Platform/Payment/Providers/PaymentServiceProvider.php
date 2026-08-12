<?php

declare(strict_types=1);

namespace App\Platform\Payment\Providers;

use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use App\Platform\Payment\FinanceOrRestrictedAdminPaymentAuthorizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the payment module's three container-level concerns: the signature
 * verifier's credentials, the admin-action authorization policy, and the
 * webhook endpoint's rate limit.
 *
 * The provider HTTP adapter (`PaymentProvider` / `SumoPodSandboxProvider`) is
 * deliberately NOT bound here — it belongs to a later task, and binding a name
 * for something unbuilt would make a `BindingResolutionException` the way a
 * caller discovers it is missing.
 *
 * ---------------------------------------------------------------------------
 * The rate limit is a requirement, not a precaution
 * ---------------------------------------------------------------------------
 * The Task 2 review recorded a binding forward constraint: "The task that wires
 * the first HTTP caller MUST require an authenticated actor and/or throttle
 * before the guard is invoked." `POST /api/payments/webhook/{merchant}` is that
 * first caller and it cannot have an authenticated actor — a payment provider
 * holds no credential of ours — so the throttle carries the whole requirement.
 *
 * Signature verification does not substitute for it. `ReceiveWebhook` persists
 * the durable row BEFORE the signature is examined (AC5 requires exactly that,
 * so that AC6's "record and reject" has something to write to), which means an
 * entirely unsigned flood still costs one row per request. The throttle is what
 * bounds that, and the body-size cap in `config('payment.webhook.max_body_bytes')`
 * is what bounds each row.
 *
 * **Keyed per merchant + client IP.** Not per IP alone: one provider's egress
 * address serves every merchant, so an IP-only bucket would let traffic for one
 * merchant throttle another's legitimate settlements. Not per merchant alone
 * either: the merchant segment is attacker-chosen, so that would be no limit at
 * all.
 *
 * A throttled delivery is not lost. ADR-0033: a non-2xx is "marked failed and
 * can be resent", and 429 is a non-2xx — so the provider's own retry schedule
 * recovers a legitimate burst that overshoots the window.
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public const string WEBHOOK_LIMITER = 'payment-webhook';

    public function register(): void
    {
        // Not a singleton: `fromConfig()` re-reads the configured secrets each
        // time, so a secret rotation takes effect without a process restart
        // (`payment-webhook.md` §Security, "current and rotating secret
        // support").
        $this->app->bind(
            SumoPodWebhookSignature::class,
            static fn (): SumoPodWebhookSignature => SumoPodWebhookSignature::fromConfig(),
        );

        // The policy behind `POST /admin/payments/reversals/{reversalType}`
        // and `POST /admin/payments/manual-verifications/{id}/verify`. Both
        // routes previously carried only `['web', 'auth', ...]`, and
        // `config/auth.php` defines a single `web` guard over the shared
        // `users` table — so `auth` alone meant nothing more than "some user
        // row exists," on two money-moving endpoints.
        //
        // Bound to the interface so a future policy change is a one-line
        // rebind, and so tests can substitute a double without reaching into
        // the controllers.
        //
        // Transient `bind()`, not `singleton()`: the implementation is
        // stateless and reads `ActorContext` from its argument, but a
        // singleton authorization policy is the shape that invites someone
        // to cache a decision across actors later. Nothing here is expensive
        // enough to be worth that risk.
        $this->app->bind(
            PaymentActionAuthorizer::class,
            FinanceOrRestrictedAdminPaymentAuthorizer::class,
        );
    }

    public function boot(): void
    {
        RateLimiter::for(self::WEBHOOK_LIMITER, static function (Request $request): Limit {
            $maxAttempts = (int) config('payment.webhook.throttle.max_attempts', 60);
            $decaySeconds = (int) config('payment.webhook.throttle.decay_seconds', 60);

            $merchant = (string) $request->route('merchant');

            // `new Limit(...)` rather than one of the `per*()` helpers: the
            // window is configured in SECONDS, and the helpers that take a
            // decay all take it in their own unit (minutes/hours/days). Going
            // through the constructor keeps `PAYMENT_WEBHOOK_THROTTLE_DECAY_SECONDS`
            // meaning exactly what it says.
            return new Limit(
                key: $merchant.'|'.(string) $request->ip(),
                maxAttempts: $maxAttempts,
                decaySeconds: $decaySeconds,
            );
        });
    }
}
