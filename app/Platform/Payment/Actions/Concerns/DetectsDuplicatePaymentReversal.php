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
 * Same substring-detection pattern as
 * `App\Platform\DocumentVault\Actions\UploadDocument::
 * isDuplicateClientUploadId()`, widened for a composite unique key across
 * drivers: PostgreSQL's error message names the constraint
 * (`payment_reversals_type_reference_unique`); SQLite's names the two
 * column names instead.
 */
trait DetectsDuplicatePaymentReversal
{
    private function isDuplicateReversal(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'payment_reversals_type_reference_unique')
            || (str_contains($message, 'reversal_type') && str_contains($message, 'reference'));
    }

    private function assertNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("\${$field} must not be blank.");
        }
    }
}
