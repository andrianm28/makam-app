<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use RuntimeException;

/**
 * The paid precondition of `Actions\ApplyPaidEffects`: an order becomes
 * `DIBAYAR` only for an amount that EXACTLY equals the total of its current,
 * accepted, unexpired quote — `AGENTS.md` §Domain and financial invariants,
 * "Never create payment before valid confirmation/reservation, accepted
 * quote, and authorized opening", and AC9's amount check.
 *
 * Thrown before anything is written, so an order that fails this check is
 * left exactly as it was: no status event, no audit row, no outbox row, no
 * `paid_via` stamp.
 *
 * Amounts appear in the message because a quote total is ordinary commercial
 * data, not restricted data — it is none of KTP, KK, death-certificate
 * content, payment proof, bank detail or credential (`AGENTS.md`
 * §Observability / §Authorization and files), and a money-mismatch that
 * cannot be diagnosed from its own exception is a worse operational outcome.
 * No order content, party, or document reference is ever included.
 */
final class PaidAmountDoesNotMatchQuoteException extends RuntimeException
{
    public static function forMissingAcceptedQuote(string $orderId): self
    {
        return new self(
            "Order [{$orderId}] has no current accepted, unexpired quote; paid effects cannot be applied."
        );
    }

    public static function forAmount(
        string $orderId,
        string $quoteId,
        int $expectedMinor,
        int $paidMinor,
    ): self {
        return new self(
            "Order [{$orderId}] paid amount [{$paidMinor}] does not equal quote [{$quoteId}] ".
            "total [{$expectedMinor}] in minor units."
        );
    }

    public static function forCurrency(
        string $orderId,
        string $quoteId,
        string $expectedCurrency,
        string $paidCurrency,
    ): self {
        return new self(
            "Order [{$orderId}] paid currency [{$paidCurrency}] does not match quote [{$quoteId}] ".
            "currency [{$expectedCurrency}]."
        );
    }
}
