<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Payment\Models\PaymentSession;

/**
 * Display-only, webhook-driven state for the browser return pages
 * (ADR-0033's `success_return_url` / `cancel_return_url`).
 *
 * ---------------------------------------------------------------------------
 * What this object may never do
 * ---------------------------------------------------------------------------
 * The return routes are reached by the CUSTOMER'S BROWSER, so nothing they
 * present is evidence (`AGENTS.md` §Domain and financial invariants: "Never
 * mark paid from browser return URL"). This class therefore never reports an
 * outcome the payment session's row does not ALREADY have: the row's only
 * terminal-state writer is `Actions\ApplyPaymentSettlement` inside the
 * webhook path. A forged "status=paid" query parameter cannot produce a paid
 * page here, because this class never reads such a parameter — the lookup
 * keys below select WHICH session is displayed, never what the page says
 * about it, and the copy branches on the row's own `SessionState`.
 *
 * ---------------------------------------------------------------------------
 * The lookup keys are display-only selectors
 * ---------------------------------------------------------------------------
 * `session` is the payment session's UUID — the migration for
 * `payment_sessions` names it "the natural candidate for a return-URL or
 * support-reference value" — and `payment_id` is the provider's own
 * transaction id, the value the provider redirect typically carries. An
 * attacker who forges either key can only display the state of a session
 * whose identifier they know; the identifier is unguessable, the page shows
 * generic state copy with no amounts or references, and nothing is written.
 * A lookup that finds nothing renders the same pending page as before the
 * session existed.
 */
final readonly class ReturnPageState
{
    public const string PENDING = 'pending';

    public const string PAID = 'paid';

    public const string FAILED = 'failed';

    public const string EXPIRED = 'expired';

    private function __construct(public string $state) {}

    public static function fromRequest(?string $sessionKey, ?string $providerPaymentId): self
    {
        $session = null;

        if ($sessionKey !== null && $sessionKey !== '') {
            $session = PaymentSession::query()->find($sessionKey);
        }

        if (! $session instanceof PaymentSession && $providerPaymentId !== null && $providerPaymentId !== '') {
            $session = PaymentSession::query()
                ->where('provider_payment_id', $providerPaymentId)
                ->first();
        }

        if (! $session instanceof PaymentSession) {
            return new self(self::PENDING);
        }

        return new self(match (SessionState::tryFrom((string) $session->state)) {
            SessionState::Paid => self::PAID,
            SessionState::Failed => self::FAILED,
            SessionState::Expired => self::EXPIRED,
            default => self::PENDING,
        });
    }

    public function is(string $state): bool
    {
        return $this->state === $state;
    }
}
