<?php

declare(strict_types=1);

namespace App\Filament\Shared\PlotFloorMap;

use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Filament\Admin\Resources\GravePlots\GravePlotsResource;
use App\Filament\Shared\PlotInventory\PlotStateOverrides;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use BackedEnum;
use Filament\Notifications\Notification;
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

    /**
     * May the CURRENT actor change plot state from this page?
     *
     * The same `MasterDataAdminAuthorizerContract` gate `GravePlotsTable`
     * carries, evaluated identically on BOTH panels — this phase does not
     * widen write authorization to `ActorRole::CEMETERY_OPERATOR`, which
     * is not on that authorizer's four-role list. A bare cemetery-operator
     * therefore gets a complete, correct, read-only map; see the
     * `/operator` subclass's doc block for why widening it is a separate,
     * sign-off-requiring decision.
     *
     * A null selection is also a "no": there is nothing to write to.
     */
    public function actorMayWrite(): bool
    {
        if ($this->selectedCemetery() === null) {
            return false;
        }

        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    /**
     * The override targets that are meaningful for the currently open plot,
     * as `target state => Indonesian button label`. Render-time meaning
     * only — `PlotStateOverrides::apply()` re-asserts the same rule against
     * a fresh re-read, because a hidden button is still wire-addressable.
     *
     * @return array<string, string>
     */
    public function availableOverrides(): array
    {
        $plot = $this->activePlot();

        if ($plot === null || ! $this->actorMayWrite()) {
            return [];
        }

        $labels = [
            PlotState::OCCUPIED => 'Tandai Terisi',
            PlotState::MAINTENANCE => 'Tandai Perawatan',
            PlotState::AVAILABLE => 'Tandai Tersedia',
        ];

        return array_filter(
            $labels,
            fn (string $label, string $toState): bool => in_array(
                $plot->plot_state,
                PlotStateOverrides::fromStates($toState),
                true,
            ),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The ZERO-RECORD-ARGUMENT override action: the target state comes from
     * the clicked button, the RECORD comes from `$activePlotId` re-resolved
     * server-side through `resolvePlot()`. A wire call naming a plot in
     * another cemetery — or in a cemetery this actor holds no grant for —
     * resolves to null and writes nothing.
     *
     * Order of checks is deliberate and mirrors `GravePlotsTable`'s:
     * 1. the actor gate, before anything else touches the record;
     * 2. the plot re-resolution (scope enforcement);
     * 3. recent re-authentication — AGENTS.md requires it for plot-override
     *    actions, and a stale actor is routed to the challenge page with
     *    `url.intended` pointing back at THIS page's own URL, so a
     *    satisfied challenge returns here rather than to the plots table;
     * 4. `PlotStateOverrides::apply()`, which owns the from-state rule and
     *    the audited transactional write.
     *
     * Deliberately does NOT call `closePlot()` on success: the modal's
     * "close" is a client-side Alpine dispatch on the button itself
     * (`granular.blade.php`), fired on every click regardless of the wire
     * response, so the visible modal always closes. Clearing
     * `$activePlotId` server-side as well would make a second override on
     * the SAME plot (e.g. maintenance -> available) require a fresh
     * `openPlot()` round trip even though the plot never left view;
     * `resolvePlot()` re-validates the id against the current scope on
     * every call regardless, so leaving it set carries no security cost.
     */
    public function markPlotState(string $toState): void
    {
        if (! $this->actorMayWrite()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengubah status plot.')->send();

            return;
        }

        $plot = $this->activePlot();

        if ($plot === null) {
            Notification::make()->danger()->title('Plot tidak ditemukan pada makam yang dipilih.')->send();

            return;
        }

        if (! $this->requireFreshAuthentication()) {
            return;
        }

        $titles = [
            PlotState::OCCUPIED => 'Plot ditandai terisi.',
            PlotState::MAINTENANCE => 'Plot ditandai perawatan.',
            PlotState::AVAILABLE => 'Plot ditandai tersedia.',
        ];

        $successTitle = $titles[$toState] ?? null;

        if ($successTitle === null) {
            Notification::make()->danger()->title('Status tujuan tidak dikenali.')->send();

            return;
        }

        // `GravePlotsResource::auditRoleFor()` is reused (not re-derived) so the map's
        // audit rows and the table's audit rows cannot become two vocabularies for one action.
        PlotStateOverrides::apply(
            $plot,
            $toState,
            $successTitle,
            GravePlotsResource::auditRoleFor(app(ActorContext::class)),
        );
    }

    /**
     * The wire-level re-authentication enforcement, same shape as
     * `GravePlotsTable::requireFreshAuthentication()` but returning to THIS
     * page. `static::getUrl()` resolves against whichever panel the
     * concrete subclass is registered in, so no subclass override is
     * needed and the two subclasses stay identical apart from
     * `cemeteryOptions()`.
     */
    protected function requireFreshAuthentication(): bool
    {
        try {
            app(ReauthenticationGuard::class)->assertFresh(app(ActorContext::class));

            return true;
        } catch (ReauthenticationRequiredException) {
            Notification::make()
                ->warning()
                ->title('Perlu verifikasi ulang')
                ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                ->send();

            session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'plot_override');
            session()->put('url.intended', static::getUrl());
            redirect()->route(PasswordReauthentication::ROUTE_NAME);

            return false;
        }
    }
}
