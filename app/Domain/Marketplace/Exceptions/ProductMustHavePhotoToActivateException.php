<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use RuntimeException;

/**
 * Thrown by `Product`'s `saving` hook when `is_active` is being set (or is
 * staying) `true` while `photo_path` is blank.
 *
 * A competitive scan of makamia.id found real, priced, purchasable listings
 * live with placeholder/missing photos. This catalogue's own `photo_path` is
 * a hand-typed free-text field with no relation to `is_active`, so the same
 * gap is latent here: nothing before this guard stopped a product from going
 * live — appearing as a purchasable, priced listing — with a blank photo.
 *
 * `ProductForm`'s `requiredIf('is_active', true)` on the `photo_path` field
 * is the primary UX for this (a real validation error an admin sees before
 * saving); this exception is the backstop invariant for any other write path
 * (console, tinker, a future API) that bypasses the form.
 */
final class ProductMustHavePhotoToActivateException extends RuntimeException
{
    public static function forProduct(string $code): self
    {
        return new self(
            "Cannot activate marketplace product [{$code}]: is_active is true but photo_path is blank. "
            .'An active, purchasable listing must have a photo.'
        );
    }
}
