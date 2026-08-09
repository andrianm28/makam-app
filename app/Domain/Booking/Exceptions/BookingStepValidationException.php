<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Booking\Actions\SaveBookingDraftStep` when a step's
 * payload fails server-side validation — `booking-and-order-orchestration/
 * tasks.md` §"Required UI states owned by this layer": "validation error —
 * server is authoritative; return field-keyed errors (not a single message
 * string) so inline `aria-invalid` + a summary alert can both be rendered."
 */
final class BookingStepValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Booking step validation failed: '.implode('; ', array_keys($errors)));
    }

    /**
     * @return array<string, list<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
