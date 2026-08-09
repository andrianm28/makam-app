<?php

declare(strict_types=1);

namespace App\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingDraftQuery;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingDraftVersionConflictException;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\ServiceCatalog\ServiceCatalogQuery;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
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
     *
     * `#[Locked]` — a draft id is also this journey's anonymous resume
     * token; a client that could `$wire.set('draftId', ...)` could point the
     * component at any draft whose id it guessed or obtained. Set
     * server-side only, by `mount()` and `hydrateFrom()`.
     */
    #[Locked]
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
     * `#[Locked]` — together with `$currentStep` this is the wizard's step
     * state. `booking-wizard-fields.md` §Global behavior: "User cannot skip
     * required upstream decisions" (`public-booking-wizard` AC13). A client
     * that could `$wire.set('completedSteps', [1,2,3,4])` would walk straight
     * past every upstream decision, so neither is client-writable. This is
     * the CLIENT half of that rule; the authoritative half is
     * `SaveBookingDraftStep`'s own server-side sequencing check, which
     * rejects a skipped step even for a caller that never goes through this
     * component.
     *
     * @var list<int>
     */
    #[Locked]
    public array $completedSteps = [];

    #[Locked]
    public int $currentStep = BookingWizardStep::LOCATION;

    /**
     * The draft's optimistic-concurrency version as of the last hydrate,
     * passed to every save as `expectedVersion` so a draft changed in
     * another tab is detected instead of silently overwritten
     * (`booking-and-order-orchestration` AC2). `#[Locked]` for the same
     * reason as the step state: a client-chosen version would let a stale
     * tab claim to be current.
     */
    #[Locked]
    public int $version = 1;

    public bool $cemeteryListUnavailable = false;

    /**
     * `idle` before any save, `saving` never actually observed server-side
     * (Livewire round-trips are synchronous from this class's perspective;
     * the Blade view's `wire:loading` targets the transient in-flight
     * state), `saved` or `failed` after the most recent step-save attempt.
     * Inline, never a toast — `booking-wizard-fields.md` §Global behavior
     * and `public-booking-wizard/design.md`'s own autosave affordance
     * section.
     */
    public string $autosaveState = 'idle';

    /**
     * Step 4's checkbox staging area — every `ServiceCode` currently
     * checked in the UI, `wire:model`-bound one entry per checkbox.
     * `ServiceCode::BASIC_CODES` are always present here (mandatory,
     * non-removable per `service-catalog.md`) whether or not the draft has
     * reached step 4 yet — see `mount()`/`hydrateFrom()`.
     *
     * @var list<string>
     */
    public array $stagedServiceCodes = [];

    public function mount(?string $draftId = null): void
    {
        if ($draftId === null) {
            $this->stagedServiceCodes = ServiceCode::BASIC_CODES;

            return;
        }

        $draft = BookingDraftQuery::find($draftId);

        if ($draft === null) {
            // Unknown/tampered draft id — same "silently reset to a
            // working state" discipline as RenewalStart::mount() for an
            // unknown ?kota=.
            $this->draftId = null;
            $this->stagedServiceCodes = ServiceCode::BASIC_CODES;

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
        $this->version = $draft->version;

        $this->stagedServiceCodes = $this->selectedServices !== []
            ? array_column($this->selectedServices, 'code')
            : ServiceCode::BASIC_CODES;
    }

    /**
     * The idempotency key for one logical save — DETERMINISTIC, derived from
     * (draft, step, payload), never a fresh UUID.
     *
     * `SaveBookingDraftStep` detects a replay by comparing the incoming key
     * against the draft's stored `last_idempotency_key`. A random key per
     * call can never match, which made that replay branch unreachable from
     * this component and every double-submit a real second write. Hashing
     * the payload instead means "the same logical save repeated" (a
     * double-tap, a retried Livewire round-trip) is a harmless no-op, while
     * a save carrying different data always produces a different key and
     * applies normally.
     *
     * `$this->draftId` is `null` on the very first `saveStep1()` — before
     * any draft exists there is nothing draft-scoped to key on, so the
     * draft-scoped segment is the empty string. That is safe because the key
     * is only ever compared against ONE draft's own stored key: a brand-new
     * draft's `last_idempotency_key` is `null`, so an empty-scoped key
     * cannot collide with anything, and every subsequent call from this
     * component already has `$this->draftId` set by the redirect/hydrate.
     *
     * @param  array<string, mixed>  $payload
     */
    private function idempotencyKeyFor(int $step, array $payload): string
    {
        return hash('sha256', ($this->draftId ?? '').':'.$step.':'.json_encode($payload));
    }

    public function saveStep1(string $cityCode): void
    {
        $payload = ['city_code' => $cityCode];
        $idempotencyKey = $this->idempotencyKeyFor(BookingWizardStep::LOCATION, $payload);
        $expectedVersion = $this->draftId !== null ? $this->version : null;

        try {
            $saved = DB::transaction(function () use ($payload, $idempotencyKey, $expectedVersion): BookingDraft {
                $draft = $this->currentOrNewDraft();

                return (new SaveBookingDraftStep)(
                    $draft,
                    BookingWizardStep::LOCATION,
                    $payload,
                    $idempotencyKey,
                    // A draft this call is creating has no version to be
                    // stale against — only a resumed one does.
                    expectedVersion: $expectedVersion,
                );
            });

            $this->hydrateFrom($saved);
            $this->autosaveState = 'saved';

            $this->redirect(route('pemesanan-makam.draft', ['draftId' => $saved->id]), navigate: false);
        } catch (BookingStepValidationException $e) {
            $this->autosaveState = 'failed';
            $this->addError('city_code', $e->getErrors()['city_code'][0] ?? 'Kota tidak valid.');
        } catch (BookingDraftVersionConflictException) {
            $this->handleVersionConflict();
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

    /**
     * @param  list<array{code: string, quantity: int}>  $selectedServices
     */
    public function saveStep4(array $selectedServices): void
    {
        $this->saveStepOrShowErrors(BookingWizardStep::SERVICES, ['selected_services' => $selectedServices]);
    }

    /**
     * The Blade "Lanjutkan" trigger for step 4 — `wire:click` cannot build
     * the `list<array{code, quantity}>` shape `saveStep4()` needs directly
     * from `$stagedServiceCodes` (a Livewire action-call expression is
     * evaluated client-side, not PHP), so this zero-arg wrapper reads the
     * checkbox-bound property server-side and calls `saveStep4()` with the
     * shape it expects. Quantity is always 1 — this batch has no
     * quantity/variant picker UI; see the task report for that scope note.
     */
    public function continueFromStep4(): void
    {
        $this->saveStep4(array_map(
            static fn (string $code): array => ['code' => $code, 'quantity' => 1],
            $this->stagedServiceCodes,
        ));
    }

    private function saveStepOrShowErrors(int $step, array $payload): void
    {
        // Both "no draft yet" branches used to return silently, so a click
        // did nothing visible at all. A draft id that no longer resolves
        // means the resume token is gone (unknown, tampered, or purged) —
        // say so, in the same field-keyed inline style as every other error
        // on this screen.
        if ($this->draftId === null) {
            $this->autosaveState = 'failed';
            $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

            return;
        }

        try {
            $draft = BookingDraftQuery::find($this->draftId);

            if ($draft === null) {
                $this->autosaveState = 'failed';
                $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

                return;
            }

            $saved = (new SaveBookingDraftStep)(
                $draft,
                $step,
                $payload,
                $this->idempotencyKeyFor($step, $payload),
                expectedVersion: $this->version,
            );

            $this->hydrateFrom($saved);
            $this->autosaveState = 'saved';
        } catch (BookingStepValidationException $e) {
            $this->autosaveState = 'failed';
            foreach ($e->getErrors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (BookingDraftVersionConflictException) {
            $this->handleVersionConflict();
        }
    }

    /**
     * The draft moved on underneath this component — another tab, or a
     * resumed session, saved a step since this instance last hydrated. The
     * only honest response is to show the CURRENT state rather than
     * overwrite it with what this tab happened to be holding, so re-hydrate
     * from the database and say plainly that it happened.
     */
    private function handleVersionConflict(): void
    {
        $this->autosaveState = 'failed';

        $latest = $this->draftId !== null ? BookingDraftQuery::find($this->draftId) : null;

        if ($latest !== null) {
            $this->hydrateFrom($latest);
        }

        $this->addError('draft', 'Pemesanan ini telah diubah di perangkat atau tab lain. Halaman dimuat ulang dengan data terbaru — silakan periksa lalu coba lagi.');
    }

    public function goToStep(int $step): void
    {
        // Navigating away from a step must not carry that step's "Tersimpan"
        // (or "Gagal menyimpan") indicator with it — nothing has been saved
        // on the step being opened.
        $this->autosaveState = 'idle';

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
        $packagesByCemetery = [];
        $this->cemeteryListUnavailable = false;

        if ($this->city !== '') {
            try {
                $cemeteries = CemeteryPublicQuery::inCity($this->city);

                // Step 2 is a TWO-LEVEL choice wherever a cemetery has
                // package/class rows: `SaveBookingDraftStep::
                // validateCemetery()` REQUIRES a package id for those
                // cemeteries (AC6 / `booking-wizard-fields.md` §Step 2
                // "package/class when applicable"), so the view must be able
                // to offer one. Resolved here, once per render, rather than
                // per-card in Blade — `CemeteryPublicQuery::activePackages()`
                // is one query per cemetery either way, but the domain read
                // belongs in the component, not in the template.
                $packagesByCemetery = $cemeteries
                    ->mapWithKeys(static fn (Cemetery $cemetery): array => [
                        $cemetery->id => CemeteryPublicQuery::activePackages($cemetery),
                    ])
                    ->all();
            } catch (Throwable $e) {
                report($e);
                $this->cemeteryListUnavailable = true;
                $cemeteries = new Collection;
                $packagesByCemetery = [];
            }
        }

        // Step 4 renders the REAL catalogue rows (`ServiceDefinition::name`),
        // not the bare `ServiceCode` strings — the same seeded Indonesian
        // names Step 5's summary already shows for the same services. The
        // basic/additional split is taken from `ServiceCode::isBasic()`
        // rather than from the catalogue's own `category` column so the
        // group labelled "Wajib" is exactly the set
        // `SaveBookingDraftStep::validateServices()` enforces; the two agree
        // today, and this way they cannot silently disagree tomorrow.
        $basicServices = new Collection;
        $additionalServices = new Collection;

        if ($this->currentStep === BookingWizardStep::SERVICES) {
            $activeServices = ServiceCatalogQuery::allActive();

            $basicServices = $activeServices->filter(
                static fn ($definition): bool => ServiceCode::isBasic((string) $definition->code),
            )->values();

            $additionalServices = $activeServices->reject(
                static fn ($definition): bool => ServiceCode::isBasic((string) $definition->code),
            )->values();
        }

        $summary = null;
        if ($this->currentStep === BookingWizardStep::SUMMARY && $this->draftId !== null) {
            $draft = BookingDraftQuery::find($this->draftId);
            if ($draft !== null) {
                $summary = BookingDraftQuery::summary($draft);
            }
        }

        // `stepLabels`, `lastImplementedStep` and `allServiceCodes` used to
        // be passed here and are gone deliberately, not by accident:
        // `stepLabels` fed `<x-mk.stepper :labels>`, which that primitive's
        // own doc header forbids a booking screen from passing (it must
        // render its own canonical nine); `lastImplementedStep` was never
        // read by the view; and `allServiceCodes` is replaced by the real
        // `ServiceDefinition` rows below, so Step 4 shows catalogue names
        // rather than raw enum codes.
        return view('livewire.public.booking.wizard', [
            'cities' => CemeteryPublicQuery::launchCities(),
            'cemeteries' => $cemeteries,
            'packagesByCemetery' => $packagesByCemetery,
            'basicServices' => $basicServices,
            'additionalServices' => $additionalServices,
            'summary' => $summary,
        ])->layout('layouts.app', [
            'title' => 'Pemesanan Makam - Makam.co.id',
            'active' => null,
        ]);
    }
}
