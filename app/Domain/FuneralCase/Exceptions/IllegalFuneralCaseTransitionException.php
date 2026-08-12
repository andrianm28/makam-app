<?php

declare(strict_types=1);

namespace App\Domain\FuneralCase\Exceptions;

use App\Domain\FuneralCase\FuneralCaseStatus;
use DomainException;

/**
 * Deliberately a DIFFERENT exception type from
 * `App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException`,
 * for the same reason `FuneralCaseStatus` is a different enum: a caller
 * catching one must never accidentally catch the other, because an illegal
 * OPERATIONAL transition and an illegal COMMERCIAL one are different
 * failures with different handling.
 */
final class IllegalFuneralCaseTransitionException extends DomainException
{
    public static function between(FuneralCaseStatus $from, FuneralCaseStatus $to): self
    {
        return new self("Funeral case transition {$from->value} -> {$to->value} is not allowed.");
    }
}
