<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Platform\FeatureGate\ModeResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * `/perpanjangan` — screen PUB-030 (`docs/product/screen-inventory.md`:
 * "Renewal Step 1-2 | city/cemetery selection"). Sprint 4 S4-T7,
 * `.kiro/specs/renewal-and-grave-registry` AC1 (steps 1 and 2 of the six)
 * and AC2 (all five MVP launch cities).
 *
 * REPLACES the `App\Livewire\Public\ComingSoon\RenewalComingSoon` stub
 * wholesale — that class's own doc block says it is "expected to be
 * REPLACED, not extended", and `routes/web.php` says the same of its route
 * registration. This batch does not own `routes/web.php`; the exact route
 * lines needed are reported to the batch lead instead of edited in.
 *
 * Same structural shape as `App\Livewire\Public\Faq\FaqIndex`: a plain
 * `Livewire\Component`, `->layout('layouts.app', [...])` attached
 * per-render so `<title>` can follow the selected city, read-only, no
 * `app/Domain/**` write path anywhere in this class.
 *
 * ---------------------------------------------------------------------------
 * Why the gate produces a BANNER here and a PAGE on the next screen
 * ---------------------------------------------------------------------------
 * `G-DATA-01` closed means the grave-search capability is unavailable
 * (AC16). This screen is not the search — it is city and cemetery
 * selection, which works perfectly well either way, and
 * design-system.md §9.2 MUST NOT 9 forbids hiding a documented step;
 * the same reasoning `AGENTS.md` §Source precedence applies to a step
 * a closed gate would remove extends, as an explicit analogy, to a step
 * that is not built yet. So this screen renders `<x-mk.alert>` up front,
 * telling the visitor before they invest in two selections that
 * the search step will need the manual-assistance path, and step 3 itself
 * (`GraveSearch`) renders §6.4's full explanatory page.
 *
 * The banner is dismissible, which is NOT this class's decision to make —
 * it comes from `App\Platform\FeatureGate\Modes\GraveSearchMode::
 * fallback()`, decided once per mode. §6.9 allows dismissal only for
 * informational modes and forbids it for any mode that changes how a user
 * must pay; this one changes a search path, not a payment.
 */
final class RenewalStart extends Component
{
    /**
     * The launch city selected in AC1's step 1, as a code from the
     * `launch_cities` table (`LaunchCityQuery::isKnown` — which also
     * accepts the five canonical `LaunchCityCode::KNOWN_CODES`). Empty
     * means step 1 is still open — which is what makes the stepper's
     * current step derivable rather than tracked as a second, drift-prone
     * piece of state.
     */
    #[Url(as: 'kota', history: true)]
    public string $city = '';

    /**
     * §6.5 "Provider unavailable" — set only when the cemetery list query
     * itself throws. The city chooser above it is built from
     * `CemeteryPublicQuery::launchCities()` (the table-backed catalogue,
     * seeded with the five canonical `LaunchCityCode::KNOWN_CODES`), so
     * step 1 keeps working even when step 2's read is down. That is the
     * whole point of catching here rather than letting the page 500.
     */
    public bool $cemeteryListUnavailable = false;

    public function mount(): void
    {
        $this->normalizeCity();
    }

    /**
     * A tampered or stale `?kota=` value is silently discarded rather than
     * 404ing: nothing about this URL names a specific record whose
     * existence could leak, and dropping the visitor back to a working
     * step 1 is more useful than an error page. `CemeteryPublicQuery::
     * inCity()` would return empty for an unknown code
     * anyway; this makes the reset visible in the UI and the URL. Called
     * from mount() AND render(): mount() runs once, and a client-initiated
     * property update re-hydrates without re-running it, so render() is the
     * one point every update path passes through.
     */
    private function normalizeCity(): void
    {
        if ($this->city !== '' && ! LaunchCityQuery::isKnown($this->city)) {
            $this->city = '';
        }
    }

    public function selectCity(string $city): void
    {
        if (! LaunchCityQuery::isKnown($city)) {
            return;
        }

        $this->city = $city;
    }

    public function resetCity(): void
    {
        $this->city = '';
    }

    /**
     * Back-navigation target for a completed stepper dot.
     * `<x-mk.stepper>` defaults its click target to `goToStep`
     * (design-system.md §3.9's `complete` row: "Clickable — back nav
     * preserves data", and `:559` documents the prop). On this screen the
     * only step that can ever render `complete` is step 1 — step 2 is the
     * furthest this component reaches and steps 3-6 live on another route
     * or nowhere yet — so anything else is a silent no-op, the same
     * failure mode selectCity() and mount() already use for a value this
     * screen cannot honour (and the same convention BookingWizard::
     * goToStep uses).
     *
     * An allow-list of one constant is deliberately used instead of a
     * `LAST_IMPLEMENTED` range guard: an allow-list is strictly stronger
     * than a range check, so adding `$step > LAST_IMPLEMENTED` here would
     * be dead code guarding a branch the allow-list already excludes. The
     * range guard belongs to Sprint 13's first method that accepts a
     * RANGE of steps — the persisted `current_step` mutator — not to this
     * one.
     */
    public function goToStep(int $step): void
    {
        if ($step === RenewalJourneyStep::CITY) {
            $this->resetCity();
        }
    }

    /**
     * AC1 step 1 until a city is chosen, then step 2. Derived from the one
     * piece of state this screen holds — see `$city`.
     */
    private function currentStep(): int
    {
        return $this->city === ''
            ? RenewalJourneyStep::CITY
            : RenewalJourneyStep::CEMETERY;
    }

    public function render(): View
    {
        $this->normalizeCity();

        $cemeteries = new Collection;
        $this->cemeteryListUnavailable = false;

        if ($this->city !== '') {
            try {
                $cemeteries = CemeteryPublicQuery::inCity($this->city);
            } catch (Throwable $e) {
                report($e);

                // §6.5 — the city chooser and the whole page must survive a
                // failed secondary read (design-system.md §6.3/§6.5, and the
                // pattern `FaqIndex`/`HomePage` both use).
                $this->cemeteryListUnavailable = true;
            }
        }

        $selectedCityLabel = null;

        foreach (CemeteryPublicQuery::launchCities() as $city) {
            if ($city['code'] === $this->city) {
                $selectedCityLabel = $city['label'];
            }
        }

        return view('livewire.public.renewal.start', [
            'cities' => CemeteryPublicQuery::launchCities(),
            'cemeteries' => $cemeteries,
            'selectedCityLabel' => $selectedCityLabel,
            'currentStep' => $this->currentStep(),
            'stepLabels' => RenewalJourneyStep::labels(),
            // Read from the server every render — never a client-supplied
            // flag (design-system.md §6.9, and `ModeResolver` is the one
            // place gate-id-to-mode pairing lives).
            'graveSearchMode' => app(ModeResolver::class)->graveSearchMode(),
        ])->layout('layouts.app', [
            'title' => $selectedCityLabel !== null
                ? 'Perpanjangan Makam '.$selectedCityLabel.' - Makam.co.id'
                : 'Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }
}
