<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use App\Domain\GraveRegistry\GraveSearchOutcome;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\GraveSearchMode;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * `/perpanjangan/cari` — screen PUB-031 (`docs/product/screen-inventory.md`:
 * "Grave search | results, no result, privacy-limited"). Sprint 4 S4-T7,
 * `.kiro/specs/renewal-and-grave-registry` AC3 (fuzzy search by deceased
 * name, block and death date), AC5 (honest manual-entry / customer-service
 * path on an empty result), AC14 (open/limited/closed access modes) and
 * AC16 (gate closed disables search WITH an explanation).
 *
 * Route path from `docs/product/information-architecture.md` §1's route
 * tree: `/perpanjangan/{cari, permohonan/{renewalReference},
 * konfirmasi/{renewalReference}}`. Only `/cari` is built in Sprint 4.
 *
 * ===========================================================================
 * THE THREE EMPTY STATES ARE THE POINT OF THIS SCREEN
 * ===========================================================================
 * `.kiro/specs/renewal-and-grave-registry/tasks.md` names collapsing them
 * into one message as a defect, and says why in a sentence worth keeping in
 * front of anyone who edits this class: "a family searching for a grave
 * record and finding nothing must not conclude the grave does not exist."
 *
 *   1. GATE CLOSED (AC16, design-system.md §6.4) — `G-DATA-01` is closed,
 *      so the search capability is unavailable to everyone. The search is
 *      never RUN. Renders `<x-mk.gate-closed-page>`: an explanatory page,
 *      never a 404 (§6.4 and information-architecture.md §4: "Route
 *      unavailable karena gate harus memberi explanatory page, bukan 404
 *      generik"). **This is the DEFAULT rendered state** — `2026_07_26_
 *      120400_seed_feature_gate_registry.php` seeds every gate closed, so
 *      an unmodified environment shows this and only this.
 *
 *   2. PRIVACY-LIMITED (AC14, §6.2) — the gate is open, the search ran, and
 *      it matched records whose own `access_mode` withholds fields. Says
 *      plainly that access is restricted. **Must never be worded as "not
 *      found"**: the record demonstrably exists.
 *
 *   3. NO-RESULT (AC5, §6.2) — the gate is open, the search ran, and
 *      nothing matched at all. Three parts, per §6.2: what is empty, why
 *      (the registry may be incomplete), and what to do next (manual entry
 *      or customer service).
 *
 * States 2 and 3 are kept apart by the DATA, not by a Blade `@if` chain
 * getting it right — see `App\Domain\GraveRegistry\GraveSearchOutcome`,
 * which exposes `isNoResult()` and `isPrivacyLimited()` as independently
 * readable facts and deliberately cannot represent state 1 at all.
 *
 * ---------------------------------------------------------------------------
 * Out of scope for Sprint 4, deliberately
 * ---------------------------------------------------------------------------
 * Steps 4-6 (fee, payment, confirmation), the 10,000-row import (AC13), the
 * tariff/late-fine rules (AC6/AC7), the duplicate-period guard (AC11) and
 * the reminder scheduler (AC15) are all Sprint 13 per
 * `docs/planning/sprint-plan.md` §9. The "Input manual" action below
 * therefore routes to the customer-service escape hatch rather than to a
 * manual-entry FORM, which does not exist — AC5 asks for an honest manual
 * entry **or** customer-service path, and offering a form that goes nowhere
 * would be the dishonest reading.
 *
 * AC4 (< 500 ms at 100,000 records) is **NOT TESTED** by anything in this
 * class or its tests. The query and its trigram index exist; no benchmark
 * has been run at any scale, and `sprint-plan.md` §9 defers this spec's
 * performance certification to Sprint 13.
 */
final class GraveSearch extends Component
{
    /**
     * The cemetery chosen in AC1's step 2, carried across from
     * `RenewalStart` as a query parameter. Validated against
     * `CemeteryPublicQuery::findPublishedById()` on every render — an
     * id that names a draft or deleted cemetery must not become searchable
     * by being held in a URL.
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
     * `true` once the visitor has actually asked for a search. Without it,
     * arriving on this screen would immediately render the no-result empty
     * state before anything had been searched for — telling a family their
     * relative was not found before they had typed a name, which is the
     * single worst version of the defect this screen exists to avoid.
     */
    public bool $searched = false;

    /**
     * §6.5 "Provider unavailable" — set only when the registry query itself
     * throws. Distinct from all three empty states: the search could not
     * run, which is neither "nothing matched" nor "access restricted" nor
     * "the capability is switched off".
     */
    public bool $searchUnavailable = false;

    public function mount(): void
    {
        // A `?nama=` / `?blok=` / `?tanggal=` already present on the initial
        // GET is treated as a real search request (a shared or bookmarked
        // result link), so the results are rendered rather than an empty
        // form. `$searched` stays false for a bare arrival.
        $this->searched = $this->criteria()->hasAnyTerm();
    }

    /**
     * §3.2/§6.3. `max:120` on the free-text fields is a generous ceiling
     * whose purpose is a real, exercisable validation state rather than a
     * form with nothing that can fail. `date_format:Y-m-d` matches the
     * `<input type="date">` control's own wire format and
     * `docs/contracts/openapi.yaml`'s `death_date` (`format: date`).
     *
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

        // A submission with every field blank is refused here rather than
        // in the query class, so the visitor gets a validation message
        // instead of a silent "no results" that would read as "your
        // relative is not in the registry". Same reasoning as `$searched`.
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

    public function render(): View
    {
        // Server-resolved, every render, through the one class that pairs a
        // mode with its gate id. Never a client-supplied flag — see
        // design-system.md §6.9 and `ClientSideTamperingCannotOpenAGateTest`.
        $graveSearchMode = app(ModeResolver::class)->graveSearchMode();
        $gateClosed = $graveSearchMode === GraveSearchMode::ManualAssistance;

        // Resolved even when the gate is closed: the explanatory page names
        // the cemetery the visitor selected, which is friendlier than a
        // generic notice, and a failed lookup here simply leaves it unnamed.
        $cemetery = $gateClosed
            ? null
            : CemeteryPublicQuery::findPublishedById($this->cemeteryId);

        $outcome = GraveSearchOutcome::empty();
        $this->searchUnavailable = false;

        // Every condition below must hold before a search runs. Ordered
        // deliberately: the gate first (AC16 — a closed gate means the
        // capability does not exist, so nothing downstream matters), then a
        // real published cemetery, then an actual request to search.
        $shouldSearch = ! $gateClosed
            && $cemetery !== null
            && $this->searched
            && ! $this->getErrorBag()->isNotEmpty();

        if ($shouldSearch) {
            try {
                $outcome = GraveRegistryPublicQuery::search($this->criteria());
            } catch (Throwable $e) {
                report($e);

                // §6.5 — never a dead end and never mistaken for a result.
                // `$outcome` stays empty, but the view branches on
                // `$searchUnavailable` BEFORE it branches on the empty
                // states, so a backend failure can never render as
                // "data makam tidak ditemukan".
                $this->searchUnavailable = true;
            }
        }

        return view('livewire.public.renewal.grave-search', [
            'gateClosed' => $gateClosed,
            'graveSearchMode' => $graveSearchMode,
            'cemetery' => $cemetery,
            'outcome' => $outcome,
            'resultsShown' => $shouldSearch && ! $this->searchUnavailable,
            'currentStep' => RenewalJourneyStep::GRAVE_SEARCH,
            'stepLabels' => RenewalJourneyStep::labels(),
            'maxResults' => GraveRegistryPublicQuery::MAX_RESULTS,
        ])->layout('layouts.app', [
            'title' => 'Cari Data Makam - Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }
}
