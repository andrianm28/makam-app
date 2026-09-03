<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * The four screen names the booking wizard groups its steps into
 * (`docs/superpowers/specs/2026-08-29-wizard-screen-consolidation-design.md`).
 * A SEPARATE class from `BookingWizardStep` even though both now count 4
 * (`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`
 * Decision 9) — kept distinct so a future step split does not have to be
 * un-collapsed from a merged class, matching how `RenewalWizardScreen`/
 * `RenewalJourneyStep` are also two classes despite the same coincidence
 * for renewal. Feeds `<x-mk.stepper>`'s `labels` prop directly — that
 * component already derives its dot count and text from whatever array is
 * passed in, no component-contract change needed.
 */
final class BookingWizardScreen
{
    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        1 => 'Cari & Pilih',
        2 => 'Detail Pemesanan',
        3 => 'Pembayaran',
        4 => 'Konfirmasi',
    ];

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }
}
