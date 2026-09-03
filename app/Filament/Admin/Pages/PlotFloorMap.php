<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Filament\Shared\PlotFloorMap\BasePlotFloorMapPage;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;

/**
 * `/admin` Plot Availability dashboard. One of exactly two subclasses of
 * `BasePlotFloorMapPage`, differing from the `/operator` one in exactly
 * two members: `cemeteryOptions()` and `canAccess()` — locked structurally
 * by `PlotFloorMapPageTest::test_the_two_subclasses_override_only_the_options_source_and_access_gate`,
 * which fails CI if either subclass declares any other method.
 *
 * Access is the shared four-back-office-role master-data gate — the same
 * `canAccess()` `GravePlotsResource` and `FeatureGateAdmin` carry, so this
 * page can never be visible to a role that the plot resource family
 * already denies.
 *
 * Options are UNSCOPED on purpose: an `/admin` actor works across the
 * whole directory, and the roadmap names cemetery-options scoping as one
 * of the two intended differences between the two panels — the other
 * being which access gate each carries.
 */
final class PlotFloorMap extends BasePlotFloorMapPage
{
    public static function canAccess(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function cemeteryOptions(): array
    {
        /** @var array<string, string> $options */
        $options = Cemetery::query()->orderBy('name')->pluck('name', 'id')->all();

        return $options;
    }
}
