<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use App\Domain\OrderWorkflow\ProductType;
use DomainException;

/**
 * A `ProductType` reached `SubmitBookingDraft` that this lane has no
 * workflow for.
 *
 * `ProductType` mirrors `docs/domain/product-type-model.md`'s full
 * six-type catalogue, but that document's §Initial scope puts four of them
 * out of reach today — funeral protection is "not covered by RKS; separate
 * discovery and legal analysis required", care subscription and renewal are
 * RKS scope gated by payment/data, and marketplace orders belong to another
 * lane entirely. Routing them is not a matter of writing the missing arm.
 *
 * This exists so the router's `match` has no default arm. A default would
 * quietly send an unhandled product type down the At-Need path, creating a
 * funeral case for a membership sale.
 */
final class UnroutableProductTypeException extends DomainException
{
    public static function for(ProductType $productType): self
    {
        return new self(
            "Product type [{$productType->value}] has no submission workflow in App\\Domain\\OrderWorkflow; ".
            'see docs/domain/product-type-model.md §Initial scope.'
        );
    }
}
