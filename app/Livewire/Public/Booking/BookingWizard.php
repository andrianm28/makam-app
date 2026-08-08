<?php

declare(strict_types=1);

namespace App\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingDraftQuery;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

/**
 * `/pemesanan-makam` — Sprint 4 S4-T4/S4-T5 (resumed 08 Aug 2026 after
 * pausing 26 Jul), `.kiro/specs/public-booking-wizard` AC1-AC6, AC11-AC13
 * and `.kiro/specs/booking-and-order-orchestration` AC2, AC3. Steps 1-5
 * only — see both specs' `design.md` "Out of scope" sections for what this
 * batch deliberately does not build.
 *
 * REPLACES the `App\Livewire\Public\ComingSoon\BookingWizardComingSoon`
 * stub wholesale — same pattern as `RenewalStart` replacing
 * `RenewalComingSoon`.
 *
 * `booking-wizard-fields.md` §Global behavior: "Draft created at first
 * meaningful input." No draft exists until `saveStep1()` is called — see
 * `mount()`, which only ever READS a draft (via `$draftId`), never creates
 * one.
 */
final class BookingWizard extends Component
{
    /**
     * Set only when resuming via `/pemesanan-makam/draft/{draftId}`. `null`
     * means no draft exists yet — step 1 is a bare city chooser with
     * nothing persisted.
     */
    public ?string $draftId = null;

    public string $city = '';

    public ?string $cemeteryId = null;

    public ?int $cemeteryPackageId = null;

    public ?string $serviceType = null;

    /**
     * @var list<array{code: string, quantity: int}>
     */
    public array $selectedServices = [];

    /**
     * @var list<int>
     */
    public array $completedSteps = [];

    public int $currentStep = BookingWizardStep::LOCATION;

    public bool $cemeteryListUnavailable = false;

    public function mount(?string $draftId = null): void
    {
        if ($draftId === null) {
            return;
        }

        $draft = BookingDraftQuery::find($draftId);

        if ($draft === null) {
            // Unknown/tampered draft id — same "silently reset to a
            // working state" discipline as RenewalStart::mount() for an
            // unknown ?kota=.
            $this->draftId = null;

            return;
        }

        $this->hydrateFrom($draft);
    }

    private function hydrateFrom(BookingDraft $draft): void
    {
        $this->draftId = $draft->id;
        $this->city = $draft->city_code ?? '';
        $this->cemeteryId = $draft->cemetery_id;
        $this->cemeteryPackageId = $draft->cemetery_package_id;
        $this->serviceType = $draft->service_type;
        $this->selectedServices = $draft->selected_services;
        $this->completedSteps = $draft->completed_steps;
        $this->currentStep = $draft->current_step;
    }

    public function saveStep1(string $cityCode): void
    {
        try {
            $saved = DB::transaction(function () use ($cityCode): BookingDraft {
                $draft = $this->currentOrNewDraft();

                return (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => $cityCode], (string) Str::uuid());
            });

            $this->hydrateFrom($saved);

            $this->redirect(route('pemesanan-makam.draft', ['draftId' => $saved->id]), navigate: false);
        } catch (BookingStepValidationException $e) {
            $this->addError('city_code', $e->getErrors()['city_code'][0] ?? 'Kota tidak valid.');
        }
    }

    public function saveStep2(string $cemeteryId, ?int $cemeteryPackageId = null): void
    {
        $this->saveStepOrShowErrors(BookingWizardStep::CEMETERY, [
            'cemetery_id' => $cemeteryId,
            'cemetery_package_id' => $cemeteryPackageId,
        ]);
    }

    public function saveStep3(string $serviceType): void
    {
        $this->saveStepOrShowErrors(BookingWizardStep::SERVICE_TYPE, ['service_type' => $serviceType]);
    }

    private function saveStepOrShowErrors(int $step, array $payload): void
    {
        if ($this->draftId === null) {
            return;
        }

        try {
            $draft = BookingDraftQuery::find($this->draftId);

            if ($draft === null) {
                return;
            }

            $saved = (new SaveBookingDraftStep)($draft, $step, $payload, (string) Str::uuid());

            $this->hydrateFrom($saved);
        } catch (BookingStepValidationException $e) {
            foreach ($e->getErrors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    public function goToStep(int $step): void
    {
        if (in_array($step, $this->completedSteps, true) || $step === $this->currentStep) {
            $this->currentStep = $step;
        }
    }

    private function currentOrNewDraft(): BookingDraft
    {
        if ($this->draftId !== null) {
            $existing = BookingDraftQuery::find($this->draftId);
            if ($existing !== null) {
                return $existing;
            }
        }

        return (new StartBookingDraft)();
    }

    public function render(): View
    {
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

        return view('livewire.public.booking.wizard', [
            'cities' => CemeteryPublicQuery::launchCities(),
            'cemeteries' => $cemeteries,
            'stepLabels' => BookingWizardStep::labels(),
            'lastImplementedStep' => BookingWizardStep::LAST_IMPLEMENTED,
        ])->layout('layouts.app', [
            'title' => 'Pemesanan Makam - Makam.co.id',
            'active' => null,
        ]);
    }
}
