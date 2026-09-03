<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Filament\Shared\PlotFloorMap\BasePlotFloorMapPage;

/**
 * `/operator` Plot Availability dashboard — the cemetery-scoped twin of
 * `App\Filament\Admin\Pages\PlotFloorMap`, differing from it in
 * `cemeteryOptions()` and the access gate alone.
 *
 * ---------------------------------------------------------------------------
 * Two gates, doing two different jobs
 * ---------------------------------------------------------------------------
 * PANEL ENTRY is already decided before this class is reached:
 * `CemeteryOperatorPanelAccessPolicy` (via `User::canAccessPanel()`)
 * admits only `ActorRole::CEMETERY_OPERATOR`. `canAccess()` below is the
 * narrower PAGE gate — an actor inside the panel with zero cemetery grants
 * has nothing this page could truthfully show them, so it is hidden rather
 * than rendered empty. This is AC4's "SHALL NOT grant record access on
 * panel membership alone" expressed at the page boundary, and it is the
 * first production consumer of `CurrentCemeteryScope::hasAnyGrant()`,
 * which shipped in Phase A with only test callers.
 *
 * RECORD VISIBILITY is `cemeteryOptions()` plus the base page's re-check of
 * `$cemeteryId` against it on every read. The base class's doc block
 * explains why that one method carries the whole seam; do not add a second
 * scoping path here.
 *
 * ---------------------------------------------------------------------------
 * Writes are deliberately NOT widened to `cemetery_operator`
 * ---------------------------------------------------------------------------
 * The map's write actions are gated by
 * `MasterDataAdminAuthorizerContract`, whose admission list is
 * [admin, restricted_admin, operator, finance] — `cemetery_operator` is
 * not on it. A bare cemetery-operator therefore gets a complete, correct,
 * READ-ONLY map here. Admitting them to plot state changes is a real
 * authorization decision needing product sign-off and human review, and is
 * out of Phase D's read-mostly scope. (Phase A shipped a comparable
 * incompleteness on `ReservePlotAction`, gating it behind an admin-only
 * `canAccess()` composition; Phase C later widened that action to
 * `cemetery_operator` via `CemeteryOrderActionGate` — this page's write
 * gate has had no equivalent follow-up and remains admin-only today.)
 */
final class PlotFloorMap extends BasePlotFloorMapPage
{
    public static function canAccess(): bool
    {
        return app(CurrentCemeteryScope::class)->hasAnyGrant();
    }

    /**
     * @return array<string, string>
     */
    public function cemeteryOptions(): array
    {
        return app(CurrentCemeteryScope::class)->grantedCemeteryOptions();
    }
}
