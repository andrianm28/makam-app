<?php

declare(strict_types=1);

namespace App\Domain\FuneralCase;

use App\Domain\Booking\BookingServiceType;
use InvalidArgumentException;

/**
 * `funeral-case-model.md` §Aggregate lists "urgency and service area" among
 * the case's minimum fields but never enumerates urgency levels, so this
 * closed list is deliberately the SMALLEST one the source documents
 * actually distinguish: `booking-wizard-fields.md` §Step 3 separates
 * `URGENT_TODAY` from the other At-Need service types, and
 * `funeral-case-model.md` §Urgent readiness treats "Urgent" as a single
 * named mode with its own targets. No third level is invented here.
 *
 * The urgency value records WHICH mode a case is in. It deliberately does
 * NOT imply a deadline: `funeral-case-model.md` §Urgent readiness makes
 * first-response and confirmation targets a per-service-area
 * configuration, and `BookingServiceType`'s own doc block records that the
 * Urgent SLA is `docs/governance/assumptions-and-gates.md` §5 open
 * decision #6 and is unresolved. `Actions\OpenFuneralCase` therefore
 * leaves the deadline columns NULL rather than guessing one.
 */
enum FuneralCaseUrgency: string
{
    case STANDARD = 'STANDARD';
    case URGENT = 'URGENT';

    /**
     * @throws InvalidArgumentException when `$serviceType` is not one of
     *                                  `BookingServiceType::KNOWN_CODES`.
     */
    public static function fromBookingServiceType(string $serviceType): self
    {
        BookingServiceType::assertKnown($serviceType);

        return $serviceType === BookingServiceType::URGENT_TODAY
            ? self::URGENT
            : self::STANDARD;
    }
}
