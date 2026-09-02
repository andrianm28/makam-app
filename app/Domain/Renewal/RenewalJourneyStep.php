<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use InvalidArgumentException;

/**
 * The three visible steps of the public renewal journey — consolidated from
 * six steps (city, TPU/TPS, grave search, fee, payment, confirmation) in
 * `docs/superpowers/specs/2026-08-29-wizard-screen-consolidation-design.md`.
 * Merged into search, fee & payment, and confirmation screens.
 *
 * ---------------------------------------------------------------------------
 * Why the labels live HERE and not inline in a Blade view
 * ---------------------------------------------------------------------------
 * `resources/views/components/mk/stepper.blade.php` takes an optional
 * `labels` prop whose default is the nine canonical BOOKING steps, and its
 * own doc block names this journey as the single reason that prop exists:
 * "`labels` exists for a DIFFERENT JOURNEY, never for re-labelling
 * booking." Every renewal screen passes the same three, so they are defined
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
 */
final class RenewalJourneyStep
{
    public const int SEARCH = 1;

    public const int FEE_AND_PAYMENT = 2;

    public const int CONFIRMATION = 3;

    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        self::SEARCH => 'Cari Makam',
        self::FEE_AND_PAYMENT => 'Biaya & Bayar',
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
