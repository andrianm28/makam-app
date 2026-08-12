<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Exceptions;

use App\Domain\PreNeed\PreNeedInterestStatus;
use DomainException;

final class IllegalPreNeedInterestTransitionException extends DomainException
{
    public static function between(PreNeedInterestStatus $from, PreNeedInterestStatus $to): self
    {
        return new self("Pre-Need interest transition {$from->value} -> {$to->value} is not allowed.");
    }
}
