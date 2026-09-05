<?php

declare(strict_types=1);

namespace App\Livewire\Public\Home;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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
 */
final class PlotAvailabilityPreview extends Component
{
    private const int MAX_CEMETERIES = 2;

    private const int MAX_BLOCKS_PER_CEMETERY = 2;

    private const int MAX_PLOTS_PER_BLOCK = 12;

    public function render(): View
    {
        $slugs = array_slice(
            (array) config('marketing.homepage_plot_preview_cemetery_slugs'),
            0,
            self::MAX_CEMETERIES,
        );

        $showcase = new Collection;
        $unavailable = false;

        if ($slugs !== []) {
            try {
                $showcase = $this->buildShowcase($slugs);
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
     * @return Collection<int, array{cemetery: Cemetery, blocks: \Illuminate\Database\Eloquent\Collection<int, CemeteryBlock>}>
     */
    private function buildShowcase(array $slugs): Collection
    {
        $result = new Collection;

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

            $result->push(['cemetery' => $cemetery, 'blocks' => $blocks]);
        }

        return $result;
    }
}
