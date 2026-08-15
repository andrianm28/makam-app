<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use RuntimeException;

final class OrderActionNotAuthorisedException extends RuntimeException
{
    public static function forActorContext(): self
    {
        return new self('The actor is not authorised to manage orders.');
    }

    public static function forTransition(string $transition): self
    {
        return new self("The actor is not authorised for the [{$transition}] order transition.");
    }
}
