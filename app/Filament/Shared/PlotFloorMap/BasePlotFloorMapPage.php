<?php

declare(strict_types=1);

namespace App\Filament\Shared\PlotFloorMap;

use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The Plot Availability dashboard — ONE page per back-office panel, not
 * two nav items. The selected cemetery's `plot_tracking_mode` decides what
 * it renders: a granular cemetery gets the Floor/Block Map (real
 * `CemeteryBlock` + `GravePlot` inventory), an aggregate cemetery gets
 * read-only Quota Cards over `cemetery_packages.availability_status`.
 * `PlotTrackingMode`'s own doc block is why the branch is on the column
 * and not on "does this cemetery happen to have blocks": the mode is a
 * business classification an admin sets deliberately, and a granular
 * cemetery with zero blocks yet is a real, honest state that must render
 * as "no blocks", never as a quota view.
 *
 * ---------------------------------------------------------------------------
 * The ONE difference between the two panel subclasses
 * ---------------------------------------------------------------------------
 * `cemeteryOptions()`. `/admin` returns every cemetery; `/operator`
 * returns `CurrentCemeteryScope::grantedCemeteryOptions()`. Nothing else
 * differs, and nothing else may be allowed to differ — because that method
 * is not merely the select's data source, it is this page's **entire
 * server-side authorization seam**:
 *
 *   - `selectedCemetery()` re-checks `$cemeteryId` against it on EVERY
 *     read, so a wire call that sets the property to a cemetery the actor
 *     may not see resolves to null and the page renders the "pick a
 *     cemetery" prompt rather than another operator's inventory.
 *   - `resolvePlot()` re-derives the plot set from `selectedCemetery()`,
 *     so no plot outside the granted cemetery is ever addressable.
 *   - every action added in later tasks routes through `resolvePlot()`.
 *
 * A public Livewire property is client-writable on every request; "the
 * select only offered three cemeteries" is a render fact, not a security
 * property. Do not add a second scoping path — narrow
 * `cemeteryOptions()` instead.
 *
 * ---------------------------------------------------------------------------
 * UUID guards are load-bearing, not defensive noise
 * ---------------------------------------------------------------------------
 * `cemeteries.id`, `grave_plots.id` and `orders.id` are Postgres `uuid`
 * columns. Comparing one against arbitrary client text raises
 * `invalid input syntax for type uuid` — a 500, not a null result. SQLite
 * silently returns no rows for the same query, so a green SQLite run
 * proves nothing here. Every client-supplied id is `Str::isUuid()`-guarded
 * before it reaches a query.
 */
abstract class BasePlotFloorMapPage extends Page
{
    protected static ?string $slug = 'peta-plot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected string $view = 'filament.shared.plot-floor-map';

    /**
     * The selected cemetery's id. Livewire-bound (`wire:model.live`) and
     * therefore UNTRUSTED — see `selectedCemetery()`.
     */
    public ?string $cemeteryId = null;

    /**
     * The plot whose detail modal is open, or null. Also untrusted; see
     * `resolvePlot()`.
     */
    public ?string $activePlotId = null;

    /**
     * The cemeteries this panel's actor may work with, `id => name`. The
     * single authorization seam — see the class doc block.
     *
     * @return array<string, string>
     */
    abstract public function cemeteryOptions(): array;

    public static function getNavigationLabel(): string
    {
        return 'Peta Ketersediaan Plot';
    }

    public function getTitle(): string
    {
        return 'Peta Ketersediaan Plot';
    }

    /**
     * Seeds the selection from `?cemetery_id=` when it names a cemetery
     * this actor may see, otherwise from the "exactly one option" rule.
     *
     * The one-option rule deliberately reproduces
     * `CurrentCemeteryScope::defaultCemeteryId()`'s semantics without
     * calling it: expressed against `cemeteryOptions()` it is correct for
     * BOTH panels (an operator with one grant, and an admin of a
     * single-cemetery deployment) and it keeps the subclasses to exactly
     * one overridden method, which is the invariant the class doc block
     * depends on.
     */
    public function mount(): void
    {
        $options = $this->cemeteryOptions();

        $requested = request()->query('cemetery_id');

        if (is_string($requested) && array_key_exists($requested, $options)) {
            $this->cemeteryId = $requested;

            return;
        }

        $this->cemeteryId = count($options) === 1
            ? (string) array_key_first($options)
            : null;
    }

    /**
     * The selected cemetery, or null when nothing valid is selected. The
     * `array_key_exists` check against `cemeteryOptions()` is the
     * authorization re-check; the `Str::isUuid()` guard keeps malformed
     * client text away from a `uuid` column.
     */
    public function selectedCemetery(): ?Cemetery
    {
        if (! is_string($this->cemeteryId) || ! Str::isUuid($this->cemeteryId)) {
            return null;
        }

        if (! array_key_exists($this->cemeteryId, $this->cemeteryOptions())) {
            return null;
        }

        return Cemetery::query()->find($this->cemeteryId);
    }

    public function trackingMode(): ?string
    {
        return $this->selectedCemetery()?->plot_tracking_mode;
    }

    /**
     * The granular arm's data: every block of the selected cemetery with
     * its plots eager-loaded in slot order.
     *
     * `slot` is a zero-padded string ('001', '002', ...) written by
     * `CreateCemeteryBlock`, so a plain string `orderBy` IS slot order —
     * the same ordering `GravePlotsTable` uses.
     *
     * Returns empty for an aggregate cemetery so the Blade view can never
     * render a map for a cemetery that has no per-plot truth, even if a
     * future edit reaches this method from the wrong branch.
     *
     * @return Collection<int, CemeteryBlock>
     */
    public function blocks(): Collection
    {
        $cemetery = $this->selectedCemetery();

        if ($cemetery === null || $cemetery->plot_tracking_mode !== PlotTrackingMode::GRANULAR) {
            return new Collection;
        }

        return CemeteryBlock::query()
            ->where('cemetery_id', $cemetery->getKey())
            ->with(['plots' => fn (HasMany $query): HasMany => $query->orderBy('slot')])
            ->orderBy('code')
            ->get();
    }

    /**
     * The aggregate arm's data: the selected cemetery's ACTIVE packages
     * and classes. Inactive rows are excluded because a quota card is a
     * statement about what an enquirer can currently ask for.
     *
     * Read-only by construction — this phase adds no write path here.
     * Editing a package's availability stays with the shipped
     * `PackagesRelationManager` under `CemeteryResource`.
     *
     * @return Collection<int, CemeteryPackage>
     */
    public function packages(): Collection
    {
        $cemetery = $this->selectedCemetery();

        if ($cemetery === null || $cemetery->plot_tracking_mode !== PlotTrackingMode::AGGREGATE) {
            return new Collection;
        }

        return CemeteryPackage::query()
            ->where('cemetery_id', $cemetery->getKey())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Opens the plot detail modal. Stores the id only when it resolves to
     * a plot of the currently selected cemetery, so `activePlot()` can
     * never be tricked into loading a foreign plot on a later request.
     */
    public function openPlot(string $plotId): void
    {
        $this->activePlotId = $this->resolvePlot($plotId) === null ? null : $plotId;
    }

    public function closePlot(): void
    {
        $this->activePlotId = null;
    }

    public function activePlot(): ?GravePlot
    {
        return $this->activePlotId === null ? null : $this->resolvePlot($this->activePlotId);
    }

    /**
     * The single server-side re-resolution of a client-supplied plot id. A
     * plot is addressable only when it sits in a block of the CURRENTLY
     * SELECTED cemetery, and the selection is itself re-validated against
     * `cemeteryOptions()` on every call. Every read and every action in
     * this page goes through here; nothing may query `grave_plots` by a
     * client id directly.
     */
    protected function resolvePlot(string $plotId): ?GravePlot
    {
        if (! Str::isUuid($plotId)) {
            return null;
        }

        $cemetery = $this->selectedCemetery();

        if ($cemetery === null) {
            return null;
        }

        return GravePlot::query()
            ->with(['block', 'cemeteryPackage'])
            ->whereIn(
                'block_id',
                CemeteryBlock::query()->where('cemetery_id', $cemetery->getKey())->select('id'),
            )
            ->whereKey($plotId)
            ->first();
    }
}
