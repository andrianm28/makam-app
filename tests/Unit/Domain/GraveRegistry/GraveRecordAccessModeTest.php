<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GraveRegistry;

use App\Domain\GraveRegistry\GraveRecordAccessMode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `App\Domain\GraveRegistry\GraveRecordAccessMode` — Sprint 4 S4-T7,
 * `.kiro/specs/renewal-and-grave-registry` AC14: "THE SYSTEM SHALL support
 * open, limited, and closed search access modes."
 *
 * Same shape as `tests/Feature/Domain/CemeteryDirectory/
 * CemeteryTypeClosedListTest.php` and the other closed-list tests, minus
 * `RefreshDatabase` — nothing here touches a table.
 */
final class GraveRecordAccessModeTest extends TestCase
{
    /**
     * AC14 names exactly three modes. A fourth appearing silently would
     * mean a projection rule nobody wrote — `GraveRecordProjection`'s
     * `match` falls through to the most restrictive branch by default, so a
     * new mode would fail closed rather than leak, but it would still be
     * undesigned.
     */
    public function test_exactly_the_three_modes_ac14_names_are_known(): void
    {
        $this->assertSame(
            ['open', 'limited', 'closed'],
            GraveRecordAccessMode::KNOWN_MODES
        );
    }

    /**
     * The column default. A record whose mode was never explicitly decided
     * must not become publicly readable by omission — see this class's own
     * doc block and the table migration's.
     */
    public function test_the_default_mode_is_the_most_restrictive_one(): void
    {
        $this->assertSame(GraveRecordAccessMode::CLOSED, GraveRecordAccessMode::DEFAULT);
    }

    /**
     * @return list<array{string, bool}>
     */
    public static function restrictionCases(): array
    {
        return [
            'open is not restricted' => [GraveRecordAccessMode::OPEN, false],
            'limited is restricted' => [GraveRecordAccessMode::LIMITED, true],
            'closed is restricted' => [GraveRecordAccessMode::CLOSED, true],
        ];
    }

    #[DataProvider('restrictionCases')]
    public function test_only_the_open_mode_is_unrestricted(string $mode, bool $expected): void
    {
        $this->assertSame($expected, GraveRecordAccessMode::isRestricted($mode));
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->assertFalse(GraveRecordAccessMode::isKnown('public'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown grave record access mode [public]');

        GraveRecordAccessMode::assertKnown('public');
    }

    /**
     * `isRestricted()` must not quietly treat an unknown mode as
     * unrestricted — a typo in a mode string would otherwise open a record
     * up rather than lock it down.
     */
    public function test_is_restricted_rejects_an_unknown_mode_rather_than_defaulting_to_open(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GraveRecordAccessMode::isRestricted('publik');
    }
}
