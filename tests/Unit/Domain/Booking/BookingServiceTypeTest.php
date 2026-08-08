<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\BookingServiceType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BookingServiceTypeTest extends TestCase
{
    public function test_all_four_service_types_are_known(): void
    {
        foreach (['NEW_GRAVE', 'OVERLAPPING_GRAVE', 'URGENT_TODAY', 'PRE_NEED'] as $code) {
            $this->assertTrue(BookingServiceType::isKnown($code), "Expected [{$code}] to be known.");
        }
    }

    public function test_known_codes_matches_booking_wizard_fields_step_3_order(): void
    {
        $this->assertSame(
            [
                BookingServiceType::NEW_GRAVE,
                BookingServiceType::OVERLAPPING_GRAVE,
                BookingServiceType::URGENT_TODAY,
                BookingServiceType::PRE_NEED,
            ],
            BookingServiceType::KNOWN_CODES,
        );
    }

    public function test_an_unknown_code_is_not_known(): void
    {
        $this->assertFalse(BookingServiceType::isKnown('CREMATION'));
    }

    public function test_assert_known_throws_for_an_unknown_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingServiceType::assertKnown('CREMATION');
    }

    public function test_assert_known_is_silent_for_a_known_code(): void
    {
        BookingServiceType::assertKnown(BookingServiceType::URGENT_TODAY);

        $this->addToAssertionCount(1);
    }
}
