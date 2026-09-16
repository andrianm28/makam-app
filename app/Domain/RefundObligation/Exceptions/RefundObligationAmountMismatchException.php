<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when the amount an operator says they transferred is not the amount
 * the obligation records as owed.
 *
 * ---------------------------------------------------------------------------
 * Why the Action takes an amount it never stores
 * ---------------------------------------------------------------------------
 * The plan requires the "catat eksekusi" action to demand *"jumlah, tanggal,
 * rujukan transfer, dan unggahan bukti"*. There is no `executed_amount_minor`
 * column — R0 deliberately shipped one amount per obligation and recorded that
 * partial refunds *"have no representation here"*. So the amount the operator
 * types has exactly one honest use: it is checked against the debt, and a
 * mismatch refuses the execution.
 *
 * Accepting the figure and dropping it would be worse than not asking: the
 * form would imply the number was recorded somewhere it is not. Storing it
 * would need a column on a migration under human review. Checking it turns the
 * field into a real control — the operator re-types, from their banking app,
 * what they actually sent, and a transposed digit stops the obligation being
 * closed rather than being discovered later by the family who was short-paid.
 *
 * The message deliberately carries both figures in minor units. They are the
 * obligation's own amount, already visible to anyone who may see the row, so
 * this leaks nothing the caller did not already have — but nothing about the
 * destination account, the transfer contents, or the customer ever appears
 * here, per `AGENTS.md` §Observability.
 */
final class RefundObligationAmountMismatchException extends InvalidArgumentException
{
    public static function for(string $obligationId, int $owedMinor, int $offeredMinor): self
    {
        return new self(
            "Refund obligation [{$obligationId}] owes {$owedMinor} minor units, but the execution was "
            ."recorded as {$offeredMinor}. A refund discharges the debt in full — this table has no "
            .'representation for a partial one — so the figures must match exactly.'
        );
    }
}
