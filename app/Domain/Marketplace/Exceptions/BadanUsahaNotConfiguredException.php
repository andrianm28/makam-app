<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use RuntimeException;

/**
 * Thrown by `PlaceMarketplaceOrder` when `marketplace.badan_usaha_ref` is
 * blank (requirement 10). Checkout REFUSES rather than defaulting: an
 * invented entity reference silently misattributes money, the exact failure
 * that freezing the reference at assessment time exists to prevent.
 */
final class BadanUsahaNotConfiguredException extends RuntimeException {}
