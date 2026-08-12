<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use InvalidArgumentException;

/**
 * The six visible steps of the public renewal journey — requirements.md
 * AC1: "THE SYSTEM SHALL implement the public renewal flow as six visible
 * steps: city, TPU/TPS, grave search, fee, payment, and confirmation/
 * invoice."
 *
 * ---------------------------------------------------------------------------
 * Why the labels live HERE and not inline in a Blade view
 * ---------------------------------------------------------------------------
 * `resources/views/components/mk/stepper.blade.php` takes an optional
 * `labels` prop whose default is the nine canonical BOOKING steps, and its
 * own doc block names this journey as the single reason that prop exists:
 * "`labels` exists for a DIFFERENT JOURNEY, never for re-labelling
 * booking." Every renewal screen passes the same six, so they are defined
 * once here rather than retyped per view — retyped labels are how a
 * product contract quietly drifts, and design-system.md §9.2 MUST NOT
 * 9 forbids renaming, reordering, or hiding a documented step.
 *
 * This is NOT a database-backed closed list, so it has no `access_mode`-
 * style column behind it and no `booted()` hook validates it; it is the
 * `final class` + `const` shape this codebase uses for a fixed vocabulary
 * (`GraveRecordAccessMode` documents that convention and its source). The
 * `assertKnown()` here guards a caller passing a step NUMBER out of range,
 * which is the only way this list is addressed.
 *
 * ---------------------------------------------------------------------------
 * Only steps 1-3 are BUILT in Sprint 4
 * ---------------------------------------------------------------------------
 * S4-T7's slice is AC1-AC5 and AC14 — city, TPU/TPS, grave search. Steps
 * 4-6 (fee, payment, confirmation) render as `upcoming` dots on the
 * stepper and have no screen behind them yet; `docs/planning/
 * sprint-plan.md` §9 puts the tariff, payment and confirmation path in
 * Sprint 13. Showing all six from day one is required, not optional:
 * `requirements.md` AC1 (quoted above) binds all six as visible, and
 * design-system.md §9.2 MUST NOT 9 forbids hiding a documented step.
 * `AGENTS.md` §Source precedence states the same for a step a closed
 * gate would remove; that reasoning extends, as an explicit analogy, to
 * a step that is not built yet — design-system.md §6.9's `PaymentMode`
 * row is the concrete instance ("Step 8 is never removed."). A user must
 * be able to see the whole journey they are entering.
 */
final class RenewalJourneyStep
{
    public const int CITY = 1;

    public const int CEMETERY = 2;

    public const int GRAVE_SEARCH = 3;

    public const int FEE = 4;

    public const int PAYMENT = 5;

    public const int CONFIRMATION = 6;

    /**
     * Keyed 1..6 in AC1's own listed order. `<x-mk.stepper>` re-keys a
     * supplied array to a contiguous 1..N sequence either way (see its doc
     * block), so this map is passed through unchanged.
     *
     * @var array<int, string>
     */
    public const array LABELS = [
        self::CITY => 'Kota',
        self::CEMETERY => 'TPU/TPS',
        self::GRAVE_SEARCH => 'Cari Makam',
        self::FEE => 'Biaya',
        self::PAYMENT => 'Pembayaran',
        self::CONFIRMATION => 'Konfirmasi',
    ];

    /**
     * The last step with a screen behind it in Sprint 4. Steps after this
     * one are real and visible but not yet reachable.
     */
    public const int LAST_IMPLEMENTED = self::PAYMENT;

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
     * @throws InvalidArgumentException when `$step` is outside 1..6.
     */
    public static function assertKnown(int $step): void
    {
        if (! self::isKnown($step)) {
            throw new InvalidArgumentException(
                "Unknown renewal journey step [{$step}]. Known steps: 1-".self::count().'.'
            );
        }
    }

    public static function label(int $step): string
    {
        self::assertKnown($step);

        return self::LABELS[$step];
    }
}
