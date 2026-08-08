<?php

declare(strict_types=1);

namespace App\Livewire\Public\Directory;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Directory\Support\PublicCapabilityProjection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * The public TPU/TPS directory list — Sprint 4 S4-T6,
 * `.kiro/specs/cemetery-directory-and-availability` AC1, AC2, AC3, AC4,
 * AC5, AC11, AC12. Screen PUB-011 ("list, filter, detail, no result").
 *
 * Route: registered by `routes/web.php` under the name `cemeteries.index`
 * (this batch does not own that file — see its final report for the exact
 * line requested). Every link in this component's view is built with
 * `route('cemeteries.…')` rather than a literal path, so the URL can be
 * changed in `routes/web.php` alone without touching this component, its
 * view, or its tests.
 *
 * Read-only. Every read goes through `CemeteryPublicQuery`, never a bare
 * `Cemetery::query()` — see that class's doc block for the AC2 published-
 * only guarantee it composes.
 *
 * ---------------------------------------------------------------------------
 * Filters are validated against the CLOSED LISTS, and an invalid one is a
 * validation state, not an empty result
 * ---------------------------------------------------------------------------
 * `?city=` and `?type=` are the same two query parameters
 * `docs/contracts/openapi.yaml`'s `GET /cemeteries` operation already
 * defines, reused verbatim so the web page and the API contract do not
 * grow two different filter vocabularies.
 *
 * Because both arrive from the URL, both are attacker-controlled. An
 * unknown value is REJECTED (§6.3 validation error) rather than silently
 * passed to the query, for a specific reason: silently returning "no
 * cemeteries match" for `?city=SURABAYA` is indistinguishable, to a user,
 * from "we do not serve Surabaya" — and on a directory whose negative
 * criteria include "No hidden omission of a required MVP city," a filter
 * that quietly yields nothing is exactly the wrong failure mode. The
 * unfiltered list is still rendered underneath the error, so the page is
 * never a dead end.
 *
 * ---------------------------------------------------------------------------
 * §6.5 provider unavailable
 * ---------------------------------------------------------------------------
 * The directory listing is this page's PRIMARY content, so unlike
 * `FaqIndex`'s secondary search panel there is no smaller thing to degrade
 * to — if the cemeteries table itself is unreachable the page says so
 * plainly and keeps its header, city list, and customer-service escape
 * hatch. Capability resolution is separately guarded per cemetery: one
 * cemetery whose profile cannot be resolved must not blank the whole
 * directory, and AC4's safe defaults are what it falls back to.
 */
final class CemeteryDirectoryIndex extends Component
{
    #[Url(as: 'city', history: true)]
    public string $city = '';

    #[Url(as: 'type', history: true)]
    public string $type = '';

    /**
     * §6.5 — set true only when the directory query itself throws.
     */
    public bool $directoryUnavailable = false;

    /**
     * §6.5 — set true when at least one cemetery's capability profile could
     * not be resolved and AC4's safe defaults were substituted. The card
     * still renders; the page states that some availability information is
     * degraded rather than showing it as if it were confirmed.
     */
    public bool $capabilitiesDegraded = false;

    /**
     * Validation is expressed as `in:` against the canonical closed lists
     * rather than a hand-written list of five city codes — the codes stay
     * defined exactly once, in `LaunchCityCode`/`CemeteryType`.
     *
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'city' => ['nullable', 'string', 'in:'.implode(',', LaunchCityCode::KNOWN_CODES)],
            'type' => ['nullable', 'string', 'in:'.implode(',', CemeteryType::KNOWN_TYPES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'city.in' => 'Kota tersebut belum termasuk area layanan awal kami. Menampilkan seluruh lokasi.',
            'type.in' => 'Jenis lokasi tersebut tidak dikenal. Menampilkan seluruh lokasi.',
        ];
    }

    public function resetFilters(): void
    {
        $this->reset(['city', 'type']);
        $this->resetValidation();
    }

    public function render(): View
    {
        // Re-validated on every render rather than only on a user action:
        // `#[Url]` means both properties can also arrive straight from the
        // query string on a plain GET, which never passes through an action
        // method. `validateOnly`-style partial validation would miss that.
        $cityValid = $this->city === '' || in_array($this->city, LaunchCityCode::KNOWN_CODES, true);
        $typeValid = $this->type === '' || in_array($this->type, CemeteryType::KNOWN_TYPES, true);

        $this->resetValidation();

        if (! $cityValid) {
            $this->addError('city', $this->messages()['city.in']);
        }

        if (! $typeValid) {
            $this->addError('type', $this->messages()['type.in']);
        }

        $this->directoryUnavailable = false;
        $this->capabilitiesDegraded = false;

        /** @var Collection<int, array<string, mixed>> $cards */
        $cards = new Collection;

        try {
            $cemeteries = CemeteryPublicQuery::published(
                city: $cityValid && $this->city !== '' ? $this->city : null,
                type: $typeValid && $this->type !== '' ? $this->type : null,
            );

            $cards = $cemeteries->map(function (Cemetery $cemetery): array {
                try {
                    $capabilities = PublicCapabilityProjection::forCemetery($cemetery);
                } catch (Throwable $e) {
                    report($e);

                    // AC4: "WHEN a capability profile is missing THE SYSTEM
                    // SHALL use safe defaults." A resolution FAILURE is not
                    // the same thing as a missing profile, but the honest
                    // response is identical — fall back to the most
                    // cautious state and flag the page as degraded, never
                    // omit the cemetery or imply availability we could not
                    // read. tasks.md: "capability resolution failure falls
                    // back to safe defaults (AC4), not a blank page."
                    $this->capabilitiesDegraded = true;
                    $capabilities = null;
                }

                return [
                    'cemetery' => $cemetery,
                    'capabilities' => $capabilities,
                ];
            });
        } catch (Throwable $e) {
            report($e);

            $this->directoryUnavailable = true;
        }

        return view('livewire.public.directory.index', [
            'cards' => $cards,
            'cities' => CemeteryPublicQuery::launchCities(),
            'types' => CemeteryPublicQuery::types(),
            'filtersActive' => $this->city !== '' || $this->type !== '',
        ])->layout('layouts.app', [
            'title' => 'Direktori TPU dan TPS - Makam.co.id',
            // <x-mk.header>'s nav keys are exactly 'pemesanan' | 'layanan' |
            // 'perpanjangan' | 'faq' | null (verified against
            // resources/views/components/mk/header.blade.php). The directory
            // is not one of the four mandatory homepage services, and
            // information-architecture.md §1's public route tree does not
            // list it at all — see this batch's report, which flags that gap
            // rather than papering over it. `null` is the honest value:
            // highlighting "Pemesanan Makam" would claim the user is inside
            // the booking wizard, which they are not.
            'active' => null,
        ]);
    }
}
