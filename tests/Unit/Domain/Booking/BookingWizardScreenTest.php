<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\BookingWizardScreen;
use PHPUnit\Framework\TestCase;

final class BookingWizardScreenTest extends TestCase
{
    public function test_it_has_exactly_four_screens(): void
    {
        $this->assertCount(4, BookingWizardScreen::labels());
    }

    public function test_labels_match_the_four_screen_names_from_pr_218(): void
    {
        $this->assertSame([
            1 => 'Cari & Pilih',
            2 => 'Detail Pemesanan',
            3 => 'Pembayaran',
            4 => 'Konfirmasi',
        ], BookingWizardScreen::labels());
    }
}
