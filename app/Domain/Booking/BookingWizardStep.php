<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use InvalidArgumentException;

/**
 * The nine steps of the public booking journey —
 * `docs/product/booking-wizard-fields.md`'s own step headings, in order.
 * Same shape and role as `App\Domain\Renewal\RenewalJourneyStep`: a fixed
 * vocabulary (`final class` + `const`), not a database-backed closed list —
 * see that class's own doc block for why.
 *
 * ---------------------------------------------------------------------------
 * Only steps 1-5 are BUILT in this batch
 * ---------------------------------------------------------------------------
 * Steps 6-9 (customer data, deceased data + documents, payment,
 * confirmation) render as `upcoming` stepper dots and have no screen behind
 * them yet — `design-system.md` §6.9's rule that a closed gate (or an
 * unbuilt step) "never removes a required MVP step" applies here exactly as
 * `RenewalJourneyStep::LAST_IMPLEMENTED`'s own doc block explains for the
 * renewal journey. A user must be able to see the whole nine-step journey
 * they are entering, per `public-booking-wizard/design.md`'s "Stepper is a
 * presentation contract" section: "Urgent and Pre-Need may branch
 * internally, but the stepper still reads 1-9."
 *
 * Label for step 7 is copied verbatim from `booking-wizard-fields.md`'s own
 * heading ("Data Almarhum and Documents") including its mixed
 * Indonesian/English wording — this class does not smooth over or
 * translate source copy.
 */
final class BookingWizardStep
{
    public const int LOCATION = 1;

    public const int CEMETERY = 2;

    public const int SERVICE_TYPE = 3;

    public const int SERVICES = 4;

    public const int SUMMARY = 5;

    public const int CUSTOMER_DATA = 6;

    public const int DECEASED_DATA = 7;

    public const int PAYMENT = 8;

    public const int CONFIRMATION = 9;

    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        self::LOCATION => 'Pilih Lokasi',
        self::CEMETERY => 'Pilih TPU/TPS',
        self::SERVICE_TYPE => 'Pilih Jenis Layanan',
        self::SERVICES => 'Pilih Layanan',
        self::SUMMARY => 'Ringkasan Pesanan',
        self::CUSTOMER_DATA => 'Data Pemesan',
        self::DECEASED_DATA => 'Data Almarhum and Documents',
        self::PAYMENT => 'Pembayaran',
        self::CONFIRMATION => 'Konfirmasi',
    ];

    /**
     * The last step with a screen behind it in this batch. Steps after this
     * one are real and visible on the stepper but not yet reachable.
     */
    public const int LAST_IMPLEMENTED = self::SUMMARY;

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

    /**
     * @throws InvalidArgumentException when `$step` is outside 1..9.
     */
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
