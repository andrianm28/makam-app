<?php

declare(strict_types=1);

namespace App\Livewire\Public\Home;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

/**
 * Read-only homepage section — docs/superpowers/specs/2026-09-05-marketing-
 * hero-plot-preview-design.md. Samples real per-plot availability for a
 * small, config-driven list of showcase cemeteries, reusing the Step 2
 * booking-wizard plot picker's badge/intent vocabulary
 * (BookingWizard::pickerBlocks()) without touching any mutating
 * reservation action. Declares no public method other than render() —
 * see this spec's §6/§9: there must be no wire:click/wire:model target on
 * this component, ever.
 *
 * `buildShowcase()` returns plain arrays, never Eloquent models, because
 * the result is round-tripped through `Cache::remember()`'s database
 * store: PHP's `unserialize()` needs the exact class of every cached
 * object to be resolvable at read time, and this app's specific runtime
 * fails to do that reliably for framework classes under the full Laravel
 * bootstrap (reproduced directly: caching a plain, empty
 * `Illuminate\Support\Collection` here already comes back as
 * `__PHP_Incomplete_Class`, a real, confirmed 500 on every homepage
 * request once this cache key is populated — not a hypothetical). Plain
 * arrays of scalars carry no class name in their serialized form, so
 * there is nothing for `unserialize()` to fail to autoload.
 */
final class PlotAvailabilityPreview extends Component
{
    private const int MAX_CEMETERIES = 2;

    private const int MAX_BLOCKS_PER_CEMETERY = 2;

    private const int MAX_PLOTS_PER_BLOCK = 12;

    private const int CACHE_TTL_SECONDS = 60;

    public function render(): View
    {
        $slugs = array_slice(
            (array) config('marketing.homepage_plot_preview_cemetery_slugs'),
            0,
            self::MAX_CEMETERIES,
        );

        $showcase = [];
        $unavailable = false;

        if ($slugs !== []) {
            try {
                $cacheKey = 'homepage:plot-availability-preview:'.md5(implode(',', $slugs));

                $showcase = Cache::remember(
                    $cacheKey,
                    self::CACHE_TTL_SECONDS,
                    fn (): array => $this->buildShowcase($slugs),
                );
            } catch (Throwable $e) {
                report($e);
                $unavailable = true;
            }
        }

        return view('livewire.public.home.plot-availability-preview', [
            'showcase' => $showcase,
            'unavailable' => $unavailable,
        ]);
    }

    /**
     * @param  list<string>  $slugs
     * @return list<array{cemetery: array{id: string, name: string}, blocks: list<array{id: string, code: string, name: string, plots: list<array{id: string, slot: string, plot_state: string}>}>}>
     */
    private function buildShowcase(array $slugs): array
    {
        $result = [];

        foreach ($slugs as $slug) {
            $cemetery = CemeteryPublicQuery::findPublishedBySlug($slug);

            if ($cemetery === null || $cemetery->plot_tracking_mode !== PlotTrackingMode::GRANULAR) {
                continue;
            }

            $blocks = CemeteryBlock::query()
                ->where('cemetery_id', $cemetery->getKey())
                ->with(['plots' => fn ($query) => $query->orderBy('slot')->limit(self::MAX_PLOTS_PER_BLOCK)])
                ->orderBy('code')
                ->limit(self::MAX_BLOCKS_PER_CEMETERY)
                ->get();

            if ($blocks->isEmpty()) {
                continue;
            }

            $result[] = [
                'cemetery' => [
                    'id' => $cemetery->getKey(),
                    'name' => $cemetery->name,
                ],
                'blocks' => $blocks->map(fn (CemeteryBlock $block): array => [
                    'id' => $block->getKey(),
                    'code' => $block->code,
                    'name' => $block->name,
                    'plots' => $block->plots->map(fn (GravePlot $plot): array => [
                        'id' => $plot->getKey(),
                        'slot' => $plot->slot,
                        'plot_state' => $plot->plot_state,
                    ])->all(),
                ])->all(),
            ];
        }

        return $result;
    }
}
