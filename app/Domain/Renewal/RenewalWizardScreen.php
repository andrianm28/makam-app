<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

/**
 * The three screen names the renewal wizard groups its steps into
 * (`docs/superpowers/specs/2026-08-29-wizard-screen-consolidation-design.md`).
 * A SEPARATE class from `RenewalJourneyStep` even though both now count 3 —
 * see `App\Domain\Booking\BookingWizardScreen`'s doc block for the identical
 * reasoning. Feeds `<x-mk.stepper>`'s `labels` prop directly.
 */
final class RenewalWizardScreen
{
    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        1 => 'Cari Makam',
        2 => 'Biaya & Bayar',
        3 => 'Konfirmasi',
    ];

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }
}
