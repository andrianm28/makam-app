<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Exceptions;

use RuntimeException;

/**
 * Thrown when a `ServiceComplaint` status transition is attempted from a
 * status that does not allow it — e.g. resolving an already-dismissed
 * complaint. Mirrors this codebase's fail-closed discipline for domain
 * state machines (see `OrderIsGuardedException` for the same shape in the
 * `OrderWorkflow` domain).
 */
final class InvalidComplaintTransitionException extends RuntimeException {}
