<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Renewal;

use App\Domain\Renewal\RenewalWizardScreen;
use PHPUnit\Framework\TestCase;

final class RenewalWizardScreenTest extends TestCase
{
    public function test_it_has_exactly_three_screens(): void
    {
        $this->assertCount(3, RenewalWizardScreen::labels());
    }

    public function test_labels_match_the_three_screen_names_from_pr_218(): void
    {
        $this->assertSame([
            1 => 'Cari Makam',
            2 => 'Biaya & Bayar',
            3 => 'Konfirmasi',
        ], RenewalWizardScreen::labels());
    }
}
