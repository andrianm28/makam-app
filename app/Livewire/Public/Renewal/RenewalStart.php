<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use App\Domain\GraveRegistry\GraveSearchOutcome;
use App\Domain\Renewal\RenewalGraveSelection;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\GraveSearchMode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * `/perpanjangan` — Screen 1 "Cari Makam" of the consolidated renewal
 * journey (`docs/superpowers/specs/2026-08-29-wizard-screen-consolidation-
 * design.md`). Folds journey steps 1-3 (Kota, TPU/TPS, Cari Makam) into one
 * screen, progressively revealed: TPU/TPS once a city is chosen, the search
 * form once a TPU/TPS is chosen, results once a search runs.
 *
 * This class is the MERGE of the former `RenewalStart` (steps 1-2) and
 * `GraveSearch` (step 3, formerly its own route `/perpanjangan/cari`). Every
 * property, validation rule and query below is carried over unchanged from
 * whichever of the two owned it; see each member's own doc block for its
 * original screen (PUB-030 / PUB-031) if that history matters.
 *
 * ---------------------------------------------------------------------------
 * Why the gate produces a BANNER for city/cemetery and a full explanatory
 * PAGE for search — unchanged reasoning, now within one component
 * ---------------------------------------------------------------------------
 * `G-DATA-01` closed means the grave-search capability is unavailable
 * (AC16). City and TPU/TPS selection work perfectly well either way, so
 * those sections render `<x-mk.alert>` up front (dismissible, per
 * `GraveSearchMode::fallback()`); the search section itself renders §6.4's
 * full explanatory page instead of a form, exactly as `GraveSearch` always
 * has.
 */
final class RenewalStart extends Component
{
    /**
     * `#[Url(as: 'kota', history: true)]` — Step 1. Empty means step 1 is
     * still open.
     */
    #[Url(as: 'kota', history: true)]
    public string $city = '';

    /**
     * `#[Url(as: 'tpu', history: true)]` — Step 2, formerly `GraveSearch::
     * $cemeteryId`. Selecting a TPU/TPS now sets this property directly
     * (`selectCemetery()`) instead of navigating to a separate route.
     */
    #[Url(as: 'tpu', history: true)]
    public string $cemeteryId = '';

    #[Url(as: 'nama', history: true)]
    public string $name = '';

    #[Url(as: 'blok', history: true)]
    public string $block = '';

    #[Url(as: 'tanggal', history: true)]
    public string $deathDate = '';

    /**
     * §6.5 "Provider unavailable" for the city/TPU-TPS read — set only when
     * that query itself throws.
     */
    public bool $cemeteryListUnavailable = false;

    /**
     * `true` once the visitor has actually asked for a search. See
     * `GraveSearch`'s original doc block: without this, arriving at the
     * search section would immediately render the no-result empty state
     * before anything had been searched for.
     */
    public bool $searched = false;

    /**
     * §6.5 "Provider unavailable" for the grave search itself.
     */
    public bool $searchUnavailable = false;

    public function mount(): void
    {
        $this->normalizeCity();
        $this->normalizeCemetery();

        if ($this->cemeteryId === '') {
            return;
        }

        // Carried over verbatim from `GraveSearch::mount()` — a
        // `?nama=`/`?blok=`/`?tanggal=` already present on the initial GET
        // is a real search request (a shared/bookmarked result link), and
        // the error-bag population (rather than `$this->validate()`) is
        // what keeps a malformed `?tanggal=` from ever reaching a typed
        // PostgreSQL column unvalidated. See that method's own doc block
        // for the full reasoning; it is unchanged here.
        $this->searched = $this->criteria()->hasAnyTerm();

        $validator = Validator::make(
            [
                'name' => $this->name,
                'block' => $this->block,
                'deathDate' => $this->deathDate,
            ],
            $this->rules(),
            $this->messages(),
        );

        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    private function normalizeCity(): void
    {
        if ($this->city !== '' && ! LaunchCityQuery::isKnown($this->city)) {
            $this->city = '';
        }
    }

    /**
     * A tampered/stale `?tpu=` (unknown id, or a real but unpublished
     * cemetery) is discarded the same way `normalizeCity()` discards a bad
     * `?kota=` — dropping the visitor back to a working TPU/TPS list rather
     * than a 404, since nothing about this URL names a record whose
     * existence itself could leak.
     */
    private function normalizeCemetery(): void
    {
        if ($this->cemeteryId !== '' && CemeteryPublicQuery::findPublishedById($this->cemeteryId) === null) {
            $this->cemeteryId = '';
        }
    }

    public function selectCity(string $city): void
    {
        if (! LaunchCityQuery::isKnown($city)) {
            return;
        }

        $this->city = $city;
        $this->resetCemetery();
    }

    public function resetCity(): void
    {
        $this->city = '';
        $this->resetCemetery();
    }

    public function selectCemetery(string $cemeteryId): void
    {
        if (CemeteryPublicQuery::findPublishedById($cemeteryId) === null) {
            return;
        }

        $this->cemeteryId = $cemeteryId;
        $this->resetSearch();
    }

    public function resetCemetery(): void
    {
        $this->cemeteryId = '';
        $this->resetSearch();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'block' => ['nullable', 'string', 'max:64'],
            'deathDate' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.max' => 'Nama almarhum terlalu panjang (maksimal 120 karakter).',
            'block.max' => 'Blok terlalu panjang (maksimal 64 karakter).',
            'deathDate.date_format' => 'Tanggal wafat harus berupa tanggal yang valid.',
        ];
    }

    public function search(): void
    {
        $this->validate();

        if (! $this->criteria()->hasAnyTerm()) {
            $this->addError('name', 'Isi minimal satu kolom pencarian: nama almarhum, blok, atau tanggal wafat.');
            $this->searched = false;

            return;
        }

        $this->searched = true;
    }

    public function resetSearch(): void
    {
        $this->reset(['name', 'block', 'deathDate', 'searched']);
        $this->resetValidation();
    }

    private function criteria(): GraveSearchCriteria
    {
        return GraveSearchCriteria::make(
            cemeteryId: $this->cemeteryId,
            name: $this->name,
            block: $this->block,
            deathDate: $this->deathDate,
        );
    }

    /**
     * Screen 1 → Screen 2 handoff — see this plan's Implementation Decision
     * 3 and `GraveRegistryPublicQuery::resolveOpenRecordAt()`'s own doc
     * block. `$index` is the result row's ORDINAL POSITION in the current
     * search's open subset, never a database id.
     */
    public function selectGraveForRenewal(int $index): mixed
    {
        if (app(ModeResolver::class)->graveSearchMode() === GraveSearchMode::ManualAssistance) {
            return null;
        }

        $record = GraveRegistryPublicQuery::resolveOpenRecordAt($this->criteria(), $index);

        if ($record === null) {
            $this->addError('name', 'Data makam yang dipilih sudah tidak tersedia. Silakan cari ulang.');
            $this->searched = false;

            return null;
        }

        RenewalGraveSelection::remember($record->id);

        return $this->redirect(route('perpanjangan.pembayaran'), navigate: true);
    }

    /**
     * Back-navigation target for a completed stepper dot — unchanged from
     * the original `RenewalStart::goToStep()`, still an allow-list of one.
     */
    public function goToStep(int $step): void
    {
        if ($step === RenewalJourneyStep::CITY) {
            $this->resetCity();
        }
    }

    private function currentStep(): int
    {
        return match (true) {
            $this->city === '' => RenewalJourneyStep::CITY,
            $this->cemeteryId === '' => RenewalJourneyStep::CEMETERY,
            default => RenewalJourneyStep::GRAVE_SEARCH,
        };
    }

    public function render(): View
    {
        $this->normalizeCity();
        $this->normalizeCemetery();

        $cemeteries = new Collection;
        $this->cemeteryListUnavailable = false;

        if ($this->city !== '') {
            try {
                $cemeteries = CemeteryPublicQuery::inCity($this->city);
            } catch (Throwable $e) {
                report($e);
                $this->cemeteryListUnavailable = true;
            }
        }

        $selectedCityLabel = null;

        foreach (CemeteryPublicQuery::launchCities() as $cityOption) {
            if ($cityOption['code'] === $this->city) {
                $selectedCityLabel = $cityOption['label'];
            }
        }

        $selectedCemetery = $this->cemeteryId !== ''
            ? CemeteryPublicQuery::findPublishedById($this->cemeteryId)
            : null;

        $graveSearchMode = app(ModeResolver::class)->graveSearchMode();
        $gateClosed = $graveSearchMode === GraveSearchMode::ManualAssistance;

        $outcome = GraveSearchOutcome::empty();
        $this->searchUnavailable = false;

        $shouldSearch = ! $gateClosed
            && $selectedCemetery !== null
            && $this->searched
            && ! $this->getErrorBag()->isNotEmpty();

        if ($shouldSearch) {
            try {
                $outcome = GraveRegistryPublicQuery::search($this->criteria());
            } catch (Throwable $e) {
                report($e);
                $this->searchUnavailable = true;
            }
        }

        return view('livewire.public.renewal.start', [
            'cities' => CemeteryPublicQuery::launchCities(),
            'cemeteries' => $cemeteries,
            'selectedCityLabel' => $selectedCityLabel,
            'selectedCemetery' => $selectedCemetery,
            'currentStep' => $this->currentStep(),
            'stepLabels' => RenewalJourneyStep::labels(),
            'graveSearchMode' => $graveSearchMode,
            'gateClosed' => $gateClosed,
            'outcome' => $outcome,
            'resultsShown' => $shouldSearch && ! $this->searchUnavailable,
            'maxResults' => GraveRegistryPublicQuery::MAX_RESULTS,
        ])->layout('layouts.app', [
            'title' => $selectedCityLabel !== null
                ? 'Perpanjangan Makam '.$selectedCityLabel.' - Makam.co.id'
                : 'Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }
}
