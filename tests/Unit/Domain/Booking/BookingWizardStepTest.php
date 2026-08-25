<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\BookingWizardStep;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BookingWizardStepTest extends TestCase
{
    public function test_there_are_nine_steps(): void
    {
        $this->assertSame(9, BookingWizardStep::count());
    }

    public function test_all_nine_steps_are_the_last_implemented_boundary(): void
    {
        $this->assertSame(BookingWizardStep::CONFIRMATION, BookingWizardStep::LAST_IMPLEMENTED);
        $this->assertSame(9, BookingWizardStep::LAST_IMPLEMENTED);
    }

    public function test_every_step_one_through_nine_is_known(): void
    {
        for ($step = 1; $step <= 9; $step++) {
            $this->assertTrue(BookingWizardStep::isKnown($step), "Expected step [{$step}] to be known.");
        }
    }

    public function test_step_zero_and_step_ten_are_not_known(): void
    {
        $this->assertFalse(BookingWizardStep::isKnown(0));
        $this->assertFalse(BookingWizardStep::isKnown(10));
    }

    public function test_assert_known_throws_outside_the_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingWizardStep::assertKnown(10);
    }

    public function test_labels_match_booking_wizard_fields_headings_in_order(): void
    {
        $this->assertSame(
            [
                1 => 'Pilih Lokasi',
                2 => 'Pilih TPU/TPS',
                3 => 'Pilih Jenis Layanan',
                4 => 'Pilih Layanan',
                5 => 'Ringkasan Pesanan',
                6 => 'Data Pemesan',
                7 => 'Data Almarhum and Documents',
                8 => 'Pembayaran',
                9 => 'Konfirmasi',
            ],
            BookingWizardStep::labels(),
        );
    }

    public function test_label_of_a_known_step_matches_labels_map(): void
    {
        $this->assertSame('Pilih Jenis Layanan', BookingWizardStep::label(BookingWizardStep::SERVICE_TYPE));
    }
}
