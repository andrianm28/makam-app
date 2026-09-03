<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use InvalidArgumentException;

/**
 * The booking wizard has been renumbered from nine steps to four screen groups
 * (see `docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`).
 * This class replaces the old step vocabulary with the new consolidated step
 * numbering: 1=Cari & Pilih, 2=Data Pemesan & Data Almarhum,
 * 3=Pembayaran, 4=Konfirmasi.
 *
 * Same shape and role as `App\Domain\Renewal\RenewalJourneyStep`: a fixed
 * vocabulary (`final class` + `const`), not a database-backed closed list —
 * see that class's own doc block for why.
 */
final class BookingWizardStep
{
    public const int DISCOVERY = 1;

    public const int CUSTOMER_AND_DECEASED_DATA = 2;

    public const int PAYMENT = 3;

    public const int CONFIRMATION = 4;

    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        self::DISCOVERY => 'Cari & Pilih',
        self::CUSTOMER_AND_DECEASED_DATA => 'Data Pemesan & Data Almarhum',
        self::PAYMENT => 'Pembayaran',
        self::CONFIRMATION => 'Konfirmasi',
    ];

    public const int LAST_IMPLEMENTED = self::CONFIRMATION;

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }

    public static function count(): int
    {
        return count(self::LABELS);
    }

    public static function isKnown(int $step): bool
    {
        return array_key_exists($step, self::LABELS);
    }

    public static function assertKnown(int $step): void
    {
        if (! self::isKnown($step)) {
            throw new InvalidArgumentException(
                "Unknown booking wizard step [{$step}]. Known steps: 1-".self::count().'.'
            );
        }
    }

    public static function label(int $step): string
    {
        self::assertKnown($step);

        return self::LABELS[$step];
    }
}
