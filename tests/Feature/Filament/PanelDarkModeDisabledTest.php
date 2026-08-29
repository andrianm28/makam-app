<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * REVERTED, 26 Aug 2026 — explicit, informed owner decision, not a
 * correction of an error in the prior fix. This test previously pinned
 * `->darkMode(false)` (PR #170, efb8493, "fix(filament): disable dark mode
 * on admin and vendor panels"), added because design-system.md §7.1/OQ-07
 * leaves dark mode explicitly out of MVP scope and a live UAT screenshot of
 * Gerbang Fitur showed dark-gray-on-near-black text once a dark-preference
 * browser/OS forced the panel dark.
 *
 * The project owner has since decided to revert that fix and restore
 * Filament's own default (`hasDarkMode(true)`, `defaultThemeMode(System)`)
 * on both panels — see `AdminPanelProvider`/`VendorPanelProvider`'s own doc
 * blocks ("SIXTH change" / matching note) for the full record. KNOWN,
 * ACCEPTED RISK: the legibility bug PR #170 fixed can recur for a visitor
 * whose browser/OS prefers dark — OQ-07 is still open and no `dark:`
 * pairing exists on any affected Blade view. This test is updated to prove
 * the reversal took, not to claim the legibility gap was fixed; it was not
 * (and this change deliberately does not touch any Blade view to fix it).
 *
 * RENAMED from `test_panels_have_dark_mode_disabled` /
 * `PanelDarkModeDisabledTest` describes the opposite of current behaviour
 * now — kept as the same file/class name only because no other test in
 * this suite references it by name and renaming the file adds churn with
 * no benefit; the method name below is accurate.
 *
 * ---------------------------------------------------------------------------
 * VERIFICATION STATUS
 * ---------------------------------------------------------------------------
 * Same host constraint as this repo's other Filament panel tests
 * (`PanelBrandingTest`, `AdminPanelHttpAccessTest` et al.) historically
 * noted here — re-check against the actual CI run for this batch.
 */
final class PanelDarkModeDisabledTest extends TestCase
{
    public function test_panels_follow_filaments_default_system_preference_dark_mode(): void
    {
        foreach (['admin', 'vendor', 'operator'] as $id) {
            $panel = Filament::getPanel($id);

            $this->assertTrue(
                $panel->hasDarkMode(),
                "{$id} panel must offer dark mode again (26 Aug 2026 reversal of PR #170, explicit owner decision — see AdminPanelProvider's doc block)."
            );
        }
    }
}
