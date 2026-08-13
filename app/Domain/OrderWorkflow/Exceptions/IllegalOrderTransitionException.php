<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use App\Domain\OrderWorkflow\OrderStatus;
use DomainException;

final class IllegalOrderTransitionException extends DomainException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self("Order transition {$from->value} -> {$to->value} is not allowed.");
    }
}
