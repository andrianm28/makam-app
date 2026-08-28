<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\PlotTrackingMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlotTrackingModeTest extends TestCase
{
    public function test_known_modes_are_aggregate_and_granular(): void
    {
        $this->assertSame(['aggregate', 'granular'], PlotTrackingMode::KNOWN_MODES);
        $this->assertSame('aggregate', PlotTrackingMode::AGGREGATE);
        $this->assertSame('granular', PlotTrackingMode::GRANULAR);
    }

    public function test_is_known_recognises_valid_modes(): void
    {
        $this->assertTrue(PlotTrackingMode::isKnown('aggregate'));
        $this->assertTrue(PlotTrackingMode::isKnown('granular'));
        $this->assertFalse(PlotTrackingMode::isKnown('hybrid'));
        $this->assertFalse(PlotTrackingMode::isKnown(''));
    }

    public function test_assert_known_throws_for_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown plot tracking mode [hybrid]. Known modes: aggregate, granular.');
        PlotTrackingMode::assertKnown('hybrid');
    }

    public function test_assert_known_does_not_throw_for_known_mode(): void
    {
        PlotTrackingMode::assertKnown('aggregate');
        PlotTrackingMode::assertKnown('granular');
        $this->addToAssertionCount(2);
    }
}
