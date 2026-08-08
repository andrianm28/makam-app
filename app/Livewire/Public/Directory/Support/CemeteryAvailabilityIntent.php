<?php

declare(strict_types=1);

namespace App\Livewire\Public\Directory\Support;

use App\Domain\CemeteryCapability\AvailabilityMode;
use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Support\Design\StatusIntent;

/**
 * The ONE place a cemetery availability value becomes a presentation triple
 * (intent, icon, Indonesian label) — Sprint 4 S4-T6,
 * `.kiro/specs/cemetery-directory-and-availability` AC5/AC6/AC7 and that
 * spec's `tasks.md` §"Availability → visual intent (**normative**)".
 *
 * design-system.md §3.7 is the rule this exists to satisfy: "Components
 * must **not** switch on enum strings. Resolve status → intent in one place
 * and pass the intent down." No Blade view in
 * `resources/views/livewire/public/directory/**` may `match` (or `@if`
 * chain) on `availability_mode` or `availability_status`; every badge on
 * those pages resolves here.
 *
 * ---------------------------------------------------------------------------
 * Why this is a scoped local resolver and NOT a new family in
 * `App\Support\Design\StatusIntent`
 * ---------------------------------------------------------------------------
 * Checked directly before writing this, not assumed: design-system.md §3.7
 * defines exactly TWO normative tables — "Order lifecycle" and "Vendor
 * processing" — and neither contains a cemetery availability vocabulary.
 * `StatusIntent`'s own class-level doc block names the specs whose status
 * enums it deliberately does NOT map and states the rule for extending it:
 * a new `FAMILY_*` + `MAP` entry must be "sourced from a design-system.md
 * update (a new §3.7 table), not invented in this file." Adding
 * `AvailabilityMode`/`CemeteryPackageAvailabilityStatus` to that shared
 * class on this batch's own authority would be exactly the silent design
 * decision that doc block forbids.
 *
 * `App\Filament\Admin\Resources\FaqArticles\FaqArticleStatusBadge` is the
 * established precedent for this shape — a small, scoped, heavily-documented
 * local mapper for a vocabulary §3.7 does not cover. This class follows it,
 * with one addition: every intent it can emit is a `StatusIntent::INTENT_*`
 * constant, so the intent VOCABULARY still has a single source of truth even
 * though this particular table lives here. `intentsAreValid()` is asserted
 * in this class's test against `StatusIntent::INTENTS`.
 *
 * **Recommended follow-up (flagged, not done here):** add a cemetery
 * availability table to design-system.md §3.7 and move this table into
 * `StatusIntent` as a third family. That is a design-review change to two
 * files this batch does not own, not an implementation detail.
 *
 * ---------------------------------------------------------------------------
 * The rule this table exists to enforce
 * ---------------------------------------------------------------------------
 * design-system.md §2.3: "State availability honestly: `\"Perlu
 * konfirmasi\"` when indicative. An indicative price is `neutral`, never
 * `success`." requirements.md's negative criteria: "No guaranteed
 * availability from indicative data."
 *
 * So `success` is gated behind ONE condition — the owning cemetery's
 * `availability_mode` being `SPECIFIC_PLOT`, which AC7 itself gates behind
 * "an authoritative registry, freshness evidence, and a reservation
 * contract". Under every other mode, a package marked `AVAILABLE` still
 * renders `neutral`. This is not defensiveness: `CemeteryPackage
 * AvailabilityStatus`'s own doc block says the same thing from the other
 * side ("Deliberately has NO 'confirmed'/guaranteed case. Every value here
 * is indicative by construction"), and no seeded cemetery activates
 * `SPECIFIC_PLOT`, so on today's data the `success` branch is unreachable
 * — deliberately. It is written out because `tasks.md`'s normative table
 * lists the "Confirmed available → `success`" row, and because a later
 * batch activating a real authoritative registry must find the honest
 * mapping already here rather than invent one under deadline.
 *
 * ---------------------------------------------------------------------------
 * Labels
 * ---------------------------------------------------------------------------
 * `"Perlu konfirmasi"` is quoted verbatim from AC5 and design-system.md
 * §2.3 — canonical product copy, not this batch's wording. The three
 * package-status labels ("Tersedia", "Terbatas", "Tidak tersedia") are the
 * ordinary Indonesian readings of `AVAILABLE`/`LIMITED`/`UNAVAILABLE`, the
 * same structural-humanisation posture `StatusIntent::label()` documents
 * for its own enums rather than inventing new product copy. They are never
 * shown without the section-level `"Perlu konfirmasi"` qualifier while the
 * mode is indicative — see `isIndicative()` and the views that call it.
 *
 * Every icon named below was confirmed to exist in
 * `resources/views/components/icon/` before being written here (finding
 * N-15: a missing icon component throws `InvalidArgumentException` at
 * render and has broken CI once).
 */
final class CemeteryAvailabilityIntent
{
    /**
     * AC5's canonical label, verbatim.
     */
    public const string NEEDS_CONFIRMATION_LABEL = 'Perlu konfirmasi';

    /**
     * `tasks.md`'s "Indicative package/class (default, AC5) → `neutral` +
     * `Perlu konfirmasi`" row. Both `INDICATIVE` and `PACKAGE_CLASS` land
     * here: AC5's phrase "indicative/package-class" names the shared
     * presentation both carry, not two different treatments (see
     * `AvailabilityMode`'s own doc block, which makes the same reading).
     *
     * @return array{intent: string, icon: string, label: string}
     */
    public static function forCemetery(string $availabilityMode): array
    {
        if ($availabilityMode === AvailabilityMode::SPECIFIC_PLOT) {
            // `tasks.md`: "`SPECIFIC_PLOT` active (AC7) → `info` — Gated
            // capability, only with authoritative registry." `info`, not
            // `success`: an authoritative registry makes plot-level data
            // SHOWABLE, it does not by itself make any individual plot a
            // confirmed booking.
            return [
                'intent' => StatusIntent::INTENT_INFO,
                'icon' => 'check-circle',
                'label' => 'Ketersediaan per petak',
            ];
        }

        // INDICATIVE, PACKAGE_CLASS, and — deliberately — anything
        // unrecognised. Degrading an unknown mode to the most cautious
        // honest state is the only safe direction on this product: the
        // failure mode of guessing wrong here is telling a family a grave
        // is available during a death in the family.
        return [
            'intent' => StatusIntent::INTENT_NEUTRAL,
            'icon' => 'clock',
            'label' => self::NEEDS_CONFIRMATION_LABEL,
        ];
    }

    /**
     * AC6's package/class granularity, resolved against the owning
     * cemetery's mode — a package status alone is never enough to decide
     * the intent, which is exactly why this takes both arguments.
     *
     * @return array{intent: string, icon: string, label: string}
     */
    public static function forPackage(string $availabilityMode, string $packageStatus): array
    {
        $label = match ($packageStatus) {
            CemeteryPackageAvailabilityStatus::AVAILABLE => 'Tersedia',
            CemeteryPackageAvailabilityStatus::LIMITED => 'Terbatas',
            CemeteryPackageAvailabilityStatus::UNAVAILABLE => 'Tidak tersedia',
            default => self::NEEDS_CONFIRMATION_LABEL,
        };

        // `tasks.md`: "Unavailable (capacity/closed) → `neutral` — Not an
        // error." Never `danger`; a full cemetery is a fact, not a fault,
        // and design-system.md §3.3's service-row variant makes the same
        // call for an unavailable item.
        if ($packageStatus === CemeteryPackageAvailabilityStatus::UNAVAILABLE) {
            return [
                'intent' => StatusIntent::INTENT_NEUTRAL,
                'icon' => 'slash',
                'label' => $label,
            ];
        }

        if (self::isIndicative($availabilityMode)) {
            // The rule this whole class exists for. An `AVAILABLE` package
            // under an indicative mode is NOT a guarantee, so it must not
            // borrow success's visual language.
            return [
                'intent' => StatusIntent::INTENT_NEUTRAL,
                'icon' => 'clock',
                'label' => $label,
            ];
        }

        return match ($packageStatus) {
            CemeteryPackageAvailabilityStatus::AVAILABLE => [
                'intent' => StatusIntent::INTENT_SUCCESS,
                'icon' => 'check-badge',
                'label' => $label,
            ],
            CemeteryPackageAvailabilityStatus::LIMITED => [
                'intent' => StatusIntent::INTENT_INFO,
                'icon' => 'check-circle',
                'label' => $label,
            ],
            default => [
                'intent' => StatusIntent::INTENT_NEUTRAL,
                'icon' => 'clock',
                'label' => self::NEEDS_CONFIRMATION_LABEL,
            ],
        };
    }

    /**
     * True when nothing this cemetery reports about availability may be
     * presented as a guarantee — i.e. every mode except the AC7-gated
     * `SPECIFIC_PLOT`. Views call this to decide whether to render the
     * `"Perlu konfirmasi"` qualifier alongside a package list; it is
     * deliberately a method here rather than a comparison in Blade, so the
     * "what counts as indicative" decision stays in one place.
     */
    public static function isIndicative(string $availabilityMode): bool
    {
        return $availabilityMode !== AvailabilityMode::SPECIFIC_PLOT;
    }
}
