<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an execution is recorded without the transfer reference or the
 * stored evidence that is the entire justification for closing a debt.
 *
 * ---------------------------------------------------------------------------
 * This is the plan's binding invariant, given a name
 * ---------------------------------------------------------------------------
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md` §Invarian: *"tidak ada
 * yang menutup kewajiban kecuali eksekusi yang tercatat beserta buktinya.
 * Bukan admin yang menandai selesai. Bukan kedaluwarsa."*
 *
 * The system never sees this money move — SumoPod cannot refund, so the
 * transfer happens in an operator's banking app, outside everything this
 * codebase can observe. The evidence is therefore not paperwork attached to a
 * settled fact; it is the *only* fact. An obligation advanced to `DIEKSEKUSI`
 * with an empty evidence path is a row asserting a grieving family has been
 * paid, backed by nothing whatsoever.
 *
 * A distinct exception type rather than a bare `InvalidArgumentException`
 * because the test that pins this invariant should fail loudly and by name if
 * the check is ever softened into "reason missing" or "validation failed".
 * It still extends `InvalidArgumentException` so existing callers catching the
 * broad case keep working.
 *
 * ---------------------------------------------------------------------------
 * KNOWN GAP, stated rather than implied
 * ---------------------------------------------------------------------------
 * This check lives in the Action only. Neither the model's `saving()` guard
 * nor the `refund_obligations_status_stamps_check` Postgres constraint
 * requires `execution_reference`/`execution_evidence_path` to be non-null for
 * `DIEKSEKUSI` — both check `executed_at` alone. So a write reaching the table
 * by any path other than `Actions\ExecuteRefundObligation` can still produce
 * an evidence-free execution. Closing that needs a change to R0's migration,
 * which is under human review and which Stage R2 is forbidden to touch; it is
 * reported as a finding instead of fixed here.
 */
final class RefundObligationEvidenceRequiredException extends InvalidArgumentException
{
    public static function forMissing(string $what): self
    {
        return new self(
            "A refund execution must carry its {$what}: the system never observes this transfer, so the "
            .'evidence is not a record of the payment — it is the only evidence the payment happened.'
        );
    }
}
