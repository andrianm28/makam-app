<?php

declare(strict_types=1);

namespace App\Domain\Quotation;

/**
 * Commercial quote status — Task 4 of
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`.
 *
 * A quote's lifecycle is deliberately tiny: a version is `ISSUED` once,
 * then either `ACCEPTED` (the customer's single-use decision) or
 * `SUPERSEDED` (a newer version was issued for the same order). Acceptance
 * and supersession are both properties of the QUOTE VERSION, never of the
 * order — see `Models\Quote`'s class doc block for how the write guard
 * makes those the only two legal transitions and how
 * `isAcceptedAndUnexpired()` evaluates them lazily.
 */
enum QuoteStatus: string
{
    case ISSUED = 'ISSUED';

    case ACCEPTED = 'ACCEPTED';

    case SUPERSEDED = 'SUPERSEDED';
}
