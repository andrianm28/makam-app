<?php

declare(strict_types=1);

namespace App\Platform\Payment\Actions\Concerns;

use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Shared by `RecordRefund` and `RecordChargeback` — both catch the same
 * `(reversal_type, reference)` unique-constraint violation shape on
 * `payment_reversals` and validate the same `reference` input, so both
 * live here once rather than being duplicated across both Actions.
 *
 * ---------------------------------------------------------------------------
 * Fix round 1 (task-6 scoped re-review, IMPORTANT-1) — matching on bare
 * column names is unsafe and was replaced
 * ---------------------------------------------------------------------------
 * The original version fell back to
 * `str_contains($message, 'reversal_type') && str_contains($message,
 * 'reference')` for drivers that don't name the constraint. That is a false
 * positive generator: `Illuminate\Database\QueryException::formatMessage()`
 * always appends the full INSERT statement text to the exception message
 * (`... SQL: insert into "payment_reversals" ("reference", ..., "
 * reversal_type", ...) values (...)`), so ANY `QueryException` on this
 * INSERT — a primary-key collision, a NOT NULL violation, a length
 * violation — contains both substrings regardless of what actually failed.
 * Proven experimentally (SQLite, this codebase's test driver): forcing a
 * `payment_reversals.id` primary-key collision produces the message
 * `SQLSTATE[23000]: ... UNIQUE constraint failed: payment_reversals.id
 * (... SQL: insert into "payment_reversals" ("id", "reversal_type",
 * "reference", ...) ...)` — old logic misclassified this as a duplicate
 * reversal even though `reversal_type`/`reference` were never involved in
 * what actually failed.
 *
 * The fix requires BOTH the word "unique" AND the QUALIFIED `table.column`
 * form for each column in the composite key, which only appears when
 * SQLite (or the SQL standard driver-reported constraint description)
 * actually names those columns as the failing constraint — never in the
 * unqualified INSERT column list. Verified against this codebase's actual
 * SQLite message shapes:
 *   - genuine duplicate: "UNIQUE constraint failed:
 *     payment_reversals.reversal_type, payment_reversals.reference"
 *     (both qualified forms + "unique" present — matches).
 *   - PK collision: "UNIQUE constraint failed: payment_reversals.id" (no
 *     qualified reversal_type/reference form — does not match, propagates
 *     as the real QueryException).
 *   - NOT NULL/length violations: neither "unique" nor the qualified forms
 *     appear — does not match.
 * `getCode()` was checked and rejected as the sole signal: SQLite reports
 * SQLSTATE `23000` for BOTH a unique violation and a PK collision (both are
 * "integrity constraint violation" in SQLite's mapping), so the code alone
 * cannot distinguish them on this driver; message-shape matching is used on
 * both drivers instead for one consistent detection path.
 *
 * PostgreSQL's own error message names the real constraint
 * (`payment_reversals_type_reference_unique`) directly and is matched
 * first, before the qualified-column fallback is even considered.
 *
 * Same substring-detection *style* as
 * `App\Platform\DocumentVault\Actions\UploadDocument::
 * isDuplicateClientUploadId()`, but deliberately NOT that method's bare
 * column-name match — see above for why that shape is unsafe for a
 * composite key on an INSERT that always echoes both column names in its
 * SQL text regardless of which constraint actually failed.
 */
trait DetectsDuplicatePaymentReversal
{
    private function isDuplicateReversal(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'payment_reversals_type_reference_unique')) {
            return true;
        }

        return str_contains($message, 'unique')
            && str_contains($message, 'payment_reversals.reversal_type')
            && str_contains($message, 'payment_reversals.reference');
    }

    private function assertNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("\${$field} must not be blank.");
        }
    }
}
