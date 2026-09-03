<?php

declare(strict_types=1);

namespace App\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingContactChannel;
use App\Domain\Booking\BookingDraftBinding;
use App\Domain\Booking\BookingDraftQuery;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingDraftVersionConflictException;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\OrderWorkflow\Exceptions\UnroutableProductTypeException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\DraftPlotHoldNoLongerValidException;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\Quotation\Actions\ComposeQuoteLinesFromBookingDraft;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Exceptions\UnpricedBookingServiceException;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\Models\QuoteLine;
use App\Domain\ServiceCatalog\ServiceCatalogQuery;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Directory\Support\PublicCapabilityProjection;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\Payment\Actions\OpenPaymentSession;
use App\Platform\Payment\Actions\OpenPaymentSessionCommand;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutProviderException;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutUnavailableException;
use App\Platform\Payment\Exceptions\PaymentSessionOpeningDeniedException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderAlreadyPaidException;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\OrderType;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use App\Support\BankTransferInfo;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use OverflowException;
use Throwable;

/**
 * `/pemesanan-makam` — Sprint 4 S4-T4/S4-T5 (resumed 08 Aug 2026 after
 * pausing 26 Jul), `.kiro/specs/public-booking-wizard` AC1-AC6, AC11-AC13
 * and `.kiro/specs/booking-and-order-orchestration` AC2, AC3. Steps 1-9
 * fully implemented.
 *
 * REPLACES the `App\Livewire\Public\ComingSoon\BookingWizardComingSoon`
 * stub wholesale — same pattern as `RenewalStart` replacing
 * `RenewalComingSoon`.
 *
 * `booking-wizard-fields.md` §Global behavior: "Draft created at first
 * meaningful input." `mount()` still only ever READS a draft (via
 * `$draftId`), never creates one — but there are now TWO places that create
 * one, not one: `saveStep1()` (the DISCOVERY save) and, earlier,
 * `holdPlotForDiscovery()`, because a plot hold's foreign key needs a real
 * draft row and a plot pick precedes the completed DISCOVERY payload. Both
 * go through `currentOrNewDraft()`, so at most one draft is ever created per
 * journey.
 */
final class BookingWizard extends Component
{
    /**
     * Set when resuming via `/pemesanan-makam/draft/{draftId}`, and also set
     * in-place by `holdPlotForDiscovery()` when it lazily creates the draft a
     * plot hold needs. `null` means no draft exists yet — DISCOVERY is still
     * a pure selection screen with nothing persisted.
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

    /**
     * The cemetery a granular-tier picker is currently showing plots for.
     * Distinct from `$this->cemeteryId` (the customer's confirmed choice,
     * persisted only once `saveStep1()` runs) — this is picker-only,
     * pre-save UI state, and untrusted client input like every other public
     * property.
     */
    public ?string $pickerCemeteryId = null;

    /**
     * The package id the picker was opened for, when the cemetery has
     * packages — null for a cemetery with none. Threaded through
     * `openPickerFor()` because the picker section renders ONCE, outside
     * the per-cemetery `@foreach`/`@foreach ($packages as $package)` loops
     * that hold this value in the existing Step 2 markup — a loop-local
     * Blade variable is not in scope there, so it must be captured as
     * component state instead.
     */
    public ?int $pickerCemeteryPackageId = null;

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
    public int $currentStep = BookingWizardStep::DISCOVERY;

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

    public string $customerFullName = '';

    public string $customerMobile = '';

    public string $customerEmail = '';

    public string $customerAddress = '';

    public string $customerRelationship = '';

    public string $customerContactChannel = '';

    /**
     * Step 6's privacy consent. Bound to a real, initially-unticked checkbox
     * — NOT client-trusted evidence: it is sent to `SaveBookingDraftStep`,
     * which refuses the step unless it is `true` and only then stamps
     * `privacy_notice_accepted_at` server-side. Previously the component
     * sent `now()` unconditionally, which recorded a consent timestamp for
     * every user whether or not they had consented to anything.
     */
    public bool $privacyNoticeAccepted = false;

    public string $deceasedFullName = '';

    public ?string $deceasedDateOfBirth = null;

    public ?string $deceasedDateOfDeath = null;

    public string $deceasedRelationship = '';

    public string $deceasedGender = '';

    public string $paymentMethod = '';

    /**
     * The customer's own reference for a MANUAL transfer (the sending bank's
     * reference number, or the name the transfer was made under). Required
     * by `SaveBookingDraftStep` whenever the method is MANUAL — the manual
     * path previously hardcoded `null` here, so it could never validate and
     * the closed-gate fallback was unreachable.
     *
     * This is a customer CLAIM, never proof of payment: nothing downstream
     * may treat it as settlement. Verification is L7's.
     */
    public string $paymentReference = '';

    /**
     * The ONLINE branch's fail-closed copy: `null` means the last
     * `openOnlinePayment()` attempt opened a session or is the first attempt.
     * Every failure lands here as fixed Indonesian copy with the manual
     * fallback (and the support escape) still on screen — never a 500 and
     * never an English exception message (`AGENTS.md` §Observability).
     */
    public ?string $onlinePaymentError = null;

    /**
     * The PHP-session key under which an opened session is remembered for
     * the draft's journey. Stored server-side keyed by the draft id (the
     * journey's own resume token, same scoping as `BookingDraftBinding`),
     * so a resumed wizard never opens a SECOND session for the same draft
     * and can render the webhook-driven state of the first one after the
     * hosted-checkout return.
     */
    private function onlinePaymentSessionKey(): string
    {
        return 'booking_online_payment.'.(string) $this->draftId;
    }

    public function mount(?string $draftId = null): void
    {
        if ($draftId === null) {
            $this->stagedServiceCodes = ServiceCode::BASIC_CODES;

            return;
        }

        $draft = $this->resolveDraftById($draftId);

        if ($draft === null) {
            // Unknown, tampered, purged — or real but belonging to a session
            // that is not this one. All four are reported identically and
            // reset to a working state, the same discipline as
            // RenewalStart::mount() for an unknown ?kota=. A stranger opening
            // a shared link therefore learns nothing about whether the draft
            // exists, and reaches a blank wizard rather than someone's PII.
            BookingDraftBinding::forget($draftId);
            $this->draftId = null;
            $this->stagedServiceCodes = ServiceCode::BASIC_CODES;

            return;
        }

        if (! BookingWizardStep::isKnown($draft->current_step)) {
            // A real, resolvable draft — but its `current_step` was written
            // under the OLD 9-step numbering (or is otherwise out of range),
            // which has no meaning against the new 4-step
            // `BookingWizardStep::LABELS`. Spec Decision 6: no data
            // migration, no dual-numbering compatibility layer — this draft
            // is treated exactly like an unknown/purged one, not silently
            // resumed at some arbitrary new step it was never saved under.
            BookingDraftBinding::forget($draftId);
            $this->draftId = null;
            $this->stagedServiceCodes = ServiceCode::BASIC_CODES;
            $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

            return;
        }

        $this->hydrateFrom($draft);

        // A genuine page reload/resume with a live plot hold should show
        // it without the customer manually reopening the picker. Scoped to
        // mount() only (not every hydrateFrom() call from an autosave
        // tick elsewhere in the wizard) — reopening on every save would
        // re-fight a customer who deliberately closed the picker.
        $hold = PlotReservation::activeForDraft($draft);

        if ($hold !== null) {
            // This used to read `$draft->cemetery_id` alone. That was correct
            // when the cemetery was persisted by its own step BEFORE a plot
            // could be picked — but DISCOVERY now saves city, cemetery,
            // service type and services as ONE payload, so a draft carrying a
            // live hold has NO cemetery_id yet in the normal case: the
            // customer held a plot and has not finished the step. Reading
            // only the column meant a reload mid-selection silently dropped
            // both the picker and the cemetery the customer had already
            // committed to, leaving them unable to complete DISCOVERY at all.
            //
            // So fall back to the cemetery the HELD PLOT itself belongs to —
            // the hold is server-side proof of that choice, and it outranks
            // an as-yet-unwritten column.
            $cemeteryId = $draft->cemetery_id ?? $hold->plot?->block?->cemetery_id;

            if ($cemeteryId !== null && $this->pickerAppliesTo($cemeteryId)) {
                $this->pickerCemeteryId = $cemeteryId;
                $this->pickerCemeteryPackageId = $draft->cemetery_package_id;
                // Restore the confirmed selection too, not just the open
                // picker, so `continueFromDiscovery()` still has a cemetery
                // to send. The PACKAGE id is genuinely unrecoverable here —
                // it is picker-only state that DISCOVERY never persists until
                // the final save — so a cemetery that HAS packages will ask
                // the customer to re-pick one. That is an inline field error
                // on a visible control, not a dead end.
                $this->cemeteryId ??= $cemeteryId;

                // ...and the CITY for the same reason, from the same
                // evidence. `city_code` is unwritten mid-DISCOVERY exactly as
                // `cemetery_id` is, and the view's TPU/TPS section reveals on
                // `$city !== ''` — so without this a reload left the customer
                // holding a plot in a cemetery they could no longer see, and
                // re-picking their city would have cleared the restored
                // cemetery (`selectCity()` drops a cross-city selection).
                // Read through the same published-only seam
                // `pickerAppliesTo()` above just used.
                if ($this->city === '') {
                    $this->city = CemeteryPublicQuery::findPublishedById($cemeteryId)?->city ?? '';
                }
            }
        }
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

        $this->customerFullName = $draft->customer_full_name ?? '';
        $this->customerMobile = $draft->customer_mobile ?? '';
        $this->customerEmail = $draft->customer_email ?? '';
        $this->customerAddress = $draft->customer_address ?? '';
        $this->customerRelationship = $draft->customer_relationship ?? '';
        $this->customerContactChannel = $draft->customer_contact_channel ?? '';
        $this->privacyNoticeAccepted = $draft->privacy_notice_accepted_at !== null;

        $this->deceasedFullName = $draft->deceased_full_name ?? '';
        $this->deceasedDateOfBirth = $draft->deceased_date_of_birth?->toDateString();
        $this->deceasedDateOfDeath = $draft->deceased_date_of_death?->toDateString();
        $this->deceasedRelationship = $draft->deceased_relationship ?? '';
        $this->deceasedGender = $draft->deceased_gender ?? '';

        $this->paymentMethod = $draft->payment_method ?? '';
        $this->paymentReference = $draft->payment_reference ?? '';
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

    /**
     * DISCOVERY — city, cemetery, package, service type and services, all
     * validated and persisted in ONE call. Replaces the four separate saves
     * the nine-step wizard had (`saveStep1()` city, `saveStep2()` cemetery,
     * the old `saveStep3()` service type, `saveStep4()` services — those
     * method names have since been reused: the current `saveStep2()`/
     * `saveStep3()` are the merged customer+deceased save and the payment
     * save respectively, see their own doc blocks).
     *
     * Structurally this is still the OLD `saveStep1()`, not one of the
     * `saveStepOrShowErrors()` callers: DISCOVERY is the step at which the
     * draft may not exist yet, so it resolves-or-creates via
     * `currentOrNewDraft()` and redirects to the resumable draft URL on
     * success. What changed is only the payload's breadth.
     *
     * `$cemeteryId`/`$serviceType` are NULLABLE even though a complete
     * DISCOVERY payload always carries both. This is a public Livewire
     * method, so it is callable directly by any client no matter what Blade
     * renders, and the component's own backing properties
     * (`?string $cemeteryId`, `?string $serviceType`) are legitimately null
     * until the customer picks. Non-nullable parameters would turn "clicked
     * Lanjutkan too early" into a `TypeError` 500; nullable ones let
     * `SaveBookingDraftStep`'s validators answer with the ordinary inline
     * field errors this screen shows everywhere else. Nothing is trusted
     * either way — the Action re-validates all five fields server-side.
     *
     * @param  list<array{code: string, quantity: int}>  $selectedServices
     */
    public function saveStep1(
        string $cityCode,
        ?string $cemeteryId,
        ?int $cemeteryPackageId,
        ?string $serviceType,
        array $selectedServices,
    ): void {
        $payload = [
            'city_code' => $cityCode,
            'cemetery_id' => $cemeteryId,
            'cemetery_package_id' => $cemeteryPackageId,
            'service_type' => $serviceType,
            'selected_services' => $selectedServices,
        ];
        $idempotencyKey = $this->idempotencyKeyFor(BookingWizardStep::DISCOVERY, $payload);

        try {
            $saved = DB::transaction(function () use ($payload, $idempotencyKey): BookingDraft {
                $draft = $this->currentOrNewDraft();

                // Decided AFTER the draft is resolved, not before. A draft
                // this call just created has no version to be stale against;
                // only a resumed one does. Deciding it up front from
                // `$this->draftId !== null` was wrong, because resolution can
                // FALL BACK to a new draft when the session binding is gone —
                // and then a fresh version-1 row was checked against the
                // version this tab last saw, so the visitor got "changed in
                // another tab" instead of simply starting again.
                //
                // Still correct now that `holdPlotForDiscovery()` can have
                // created the draft in an EARLIER request: that draft is
                // re-fetched here (so `wasRecentlyCreated` is false) and
                // `$this->version` was set from it at creation time, and
                // `HoldPlotForDraft` never writes to the draft row itself —
                // so the version this tab holds is still the live one.
                $expectedVersion = $draft->wasRecentlyCreated ? null : $this->version;

                return (new SaveBookingDraftStep)(
                    $draft,
                    BookingWizardStep::DISCOVERY,
                    $payload,
                    $idempotencyKey,
                    expectedVersion: $expectedVersion,
                );
            });

            $this->hydrateFrom($saved);
            $this->autosaveState = 'saved';

            $this->redirect(route('pemesanan-makam.draft', ['draftId' => $saved->id]), navigate: false);
        } catch (BookingStepValidationException $e) {
            // Every field key the merged validator can raise is surfaced, not
            // just `city_code`: one call now covers four former steps, so
            // hardcoding a single key (as the old city-only version did)
            // would silently swallow a cemetery, service-type or services
            // rejection and leave the customer with a button that did nothing.
            //
            // Deliberately NO hold release here — see
            // `holdPlotForDiscovery()`'s doc block for why a failure on this
            // path must not cost the customer their plot.
            $this->autosaveState = 'failed';

            foreach ($e->getErrors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (BookingDraftVersionConflictException) {
            $this->handleVersionConflict();
        }
    }

    /**
     * The Blade "Lanjutkan" trigger for DISCOVERY — replaces
     * `continueFromStep4()`. A `wire:click` expression is evaluated
     * client-side, so it cannot build the `list<array{code, quantity}>` shape
     * `saveStep1()` needs out of `$stagedServiceCodes`; this zero-arg wrapper
     * reads the component's own selection state server-side instead.
     * Quantity is always 1 — there is no quantity/variant picker UI.
     */
    public function continueFromDiscovery(): void
    {
        $this->saveStep1(
            $this->city,
            $this->cemeteryId,
            $this->cemeteryPackageId,
            $this->serviceType,
            array_map(
                static fn (string $code): array => ['code' => $code, 'quantity' => 1],
                $this->stagedServiceCodes,
            ),
        );
    }

    /**
     * Whether Step 2's cemetery/package selection should show the plot
     * picker instead of the plain "Pilih ..." buttons. Fails toward the
     * EXISTING behaviour (no picker) on any lookup problem — a booking
     * must never be blocked by this feature being unavailable.
     *
     * Resolved through `CemeteryPublicQuery::findPublishedById()`, the same
     * published-only seam every other Step 2 read already goes through, and
     * NOT through a raw `Cemetery::find()`. `openPickerFor()` is a public
     * Livewire method taking a client-supplied id, so a raw lookup let an
     * anonymous visitor point `$pickerCemeteryId` at any granular cemetery
     * whose UUID they held — including a `draft`-status one — and read its
     * blocks and plots through `pickerBlocks()`. That method's own
     * fail-empty guard delegates here, so closing it here closes it for
     * every picker path at once. `findPublishedById()` also carries the
     * UUID-shape guard this method used to duplicate (a non-UUID string
     * compared against a real `uuid` column is a Postgres type error, not a
     * miss — see that method's doc block).
     */
    public function pickerAppliesTo(string $cemeteryId): bool
    {
        $cemetery = CemeteryPublicQuery::findPublishedById($cemeteryId);

        return $cemetery !== null && $cemetery->plot_tracking_mode === PlotTrackingMode::GRANULAR;
    }

    /**
     * Blocks (`code => name` => plots) for the picker, scoped to the
     * cemetery the picker is currently open on. Empty when nothing is
     * selected or the cemetery is not granular-tier — mirrors
     * `App\Filament\Shared\PlotFloorMap\BasePlotFloorMapPage::blocks()`'s
     * own fail-empty shape.
     */
    public function pickerBlocks(): \Illuminate\Support\Collection
    {
        if ($this->pickerCemeteryId === null || ! $this->pickerAppliesTo($this->pickerCemeteryId)) {
            return new \Illuminate\Support\Collection;
        }

        return CemeteryBlock::query()
            ->where('cemetery_id', $this->pickerCemeteryId)
            ->with(['plots' => fn ($query) => $query->orderBy('slot')])
            ->orderBy('code')
            ->get();
    }

    /**
     * The live draft hold for THIS draft, if any — resolved from the
     * database on every render, never from client state, so a stale
     * countdown can never outlive the real row. See design-system.md
     * §8.4: "the indicator is driven by a server-confirmed [value], never
     * by a local timer" — applied here to the hold countdown the same way
     * it already applies to the autosave indicator.
     */
    public function activeDraftPlotHold(): ?PlotReservation
    {
        if ($this->draftId === null) {
            return null;
        }

        $draft = BookingDraftQuery::findBound($this->draftId);

        return $draft === null ? null : PlotReservation::activeForDraft($draft);
    }

    /**
     * DISCOVERY's city choice. Local, non-persisting UI state — the same
     * shape `RenewalStart::selectCity()` established for its own merged
     * screen, and the reason this screen's sections can reveal at all: the
     * merged DISCOVERY step advances `$currentStep` exactly once, when
     * `continueFromDiscovery()` succeeds, so a step-driven reveal would
     * freeze after the first sub-choice.
     *
     * Changing the city drops the cemetery chosen under the previous one:
     * `SaveBookingDraftStep::validateCemeteryAgainstPayloadCity()` rejects a
     * cemetery outside the payload's city, so keeping it would reveal the
     * rest of the screen against a pair that can only fail at "Lanjutkan".
     * A plot already held for the dropped cemetery is left to the hold's own
     * TTL, exactly as `holdPlotForDiscovery()`'s doc block describes for
     * every other abandoned selection.
     */
    public function selectCity(string $cityCode): void
    {
        if ($cityCode === $this->city) {
            return;
        }

        $this->city = $cityCode;
        $this->cemeteryId = null;
        $this->cemeteryPackageId = null;
        $this->pickerCemeteryId = null;
        $this->pickerCemeteryPackageId = null;
    }

    /**
     * The non-picker cemetery selection — a cemetery with no active
     * packages, or a package chosen directly on a cemetery that does not use
     * the plot picker. `holdPlotForDiscovery()` sets these same two
     * properties for the picker path, so both paths converge on the one
     * reveal gate (`@if ($cemeteryId !== null)`) the view uses.
     *
     * Nothing is validated here and nothing is persisted: published status,
     * city membership and package applicability are all re-checked
     * server-side by `SaveBookingDraftStep` when the single DISCOVERY save
     * runs.
     *
     * It DOES close any open plot picker, for the same class of reason
     * `selectCity()` drops a cross-city cemetery. The picker renders from its
     * own state, so a picker left open on cemetery A while this method moved
     * the selection to cemetery B put two live, contradictory choices on one
     * screen — including A's "plot ditahan" alert — and a click on any plot
     * in that stale grid would silently flip the selection back to A.
     *
     * The reverse is deliberately NOT done: `openPickerFor()` leaves
     * `$cemeteryId` alone, because merely opening a map is not a choice (only
     * `holdPlotForDiscovery()` is), and clearing it there would collapse the
     * already-revealed sections below just because the customer looked at
     * another cemetery's plots.
     */
    public function selectCemetery(string $cemeteryId, ?int $cemeteryPackageId = null): void
    {
        $this->cemeteryId = $cemeteryId;
        $this->cemeteryPackageId = $cemeteryPackageId;

        $this->pickerCemeteryId = null;
        $this->pickerCemeteryPackageId = null;
    }

    public function selectServiceType(string $serviceType): void
    {
        $this->serviceType = $serviceType;
    }

    /**
     * Opens the picker for a cemetery/package the customer has not yet
     * confirmed — client-visible UI state only, nothing persisted. Called by
     * the "Pilih {{ cemetery }}" buttons when `pickerAppliesTo()` is true;
     * the actual claim on a plot is made by `holdPlotForDiscovery()` below,
     * and nothing reaches the draft until `saveStep1()` runs.
     */
    public function openPickerFor(string $cemeteryId, ?int $cemeteryPackageId = null): void
    {
        $this->pickerCemeteryId = $cemeteryId;
        $this->pickerCemeteryPackageId = $cemeteryPackageId;
    }

    /**
     * Holds the chosen plot for this draft. Renamed from
     * `holdPlotForStep2()` — there is no numbered "step 2" to save any more.
     *
     * ---------------------------------------------------------------------------
     * Why this no longer saves anything
     * ---------------------------------------------------------------------------
     * It used to hold the plot and then immediately call `saveStep2()` to
     * persist `cemetery_id`/`cemetery_package_id`. Under the DISCOVERY merge
     * that save cannot happen here: city, cemetery, service type and services
     * are validated together in one payload, and the last two are not chosen
     * yet at plot-pick time. So the chosen cemetery/package are held in
     * component state and the one `saveStep1()` call — fired from
     * `continueFromDiscovery()` once everything is chosen — is the only place
     * `SaveBookingDraftStep` runs for this step.
     *
     * The HOLD itself deliberately stays immediate. Deferring it to the final
     * save would let two visitors both "browse" the same plot while one fills
     * in the rest of the form, which defeats the entire purpose of holding
     * scarce inventory.
     *
     * ---------------------------------------------------------------------------
     * Why a failed DISCOVERY save no longer releases the hold
     * ---------------------------------------------------------------------------
     * The old release-on-failure block was correct for the old shape: the
     * hold and the save were one atomic-ish user action, so a rejected save
     * meant the hold had been taken for a step that never happened. Under the
     * merge, four sub-choices share ONE save/validate unit, and the save
     * happens in a LATER request — so "the DISCOVERY save failed" now usually
     * means "the customer mistyped a field unrelated to the plot". Releasing
     * their plot over that would be a worse outcome than holding it.
     *
     * It also could not have been implemented correctly here anyway: the old
     * block discriminated on `$hold->wasRecentlyCreated`, which only means
     * anything on the exact instance whose `create()` inserted the row. A
     * release attempted from `saveStep1()` in a later request must re-fetch
     * the hold via `PlotReservation::activeForDraft()`, and that flag is
     * ALWAYS false on a re-fetched model — so the check would silently never
     * fire.
     *
     * The safety net for a genuinely abandoned attempt is the hold's own TTL
     * plus the scheduled `plot-reservation:expire-stale-draft-holds` command
     * (`routes/console.php`, every minute). The one case that DOES still need
     * an explicit release — the customer picking a DIFFERENT plot than one
     * they already hold — is handled inside `HoldPlotForDraft` itself, under
     * its draft-row lock, and is unaffected by this change.
     */
    public function holdPlotForDiscovery(string $cemeteryId, ?int $cemeteryPackageId, string $plotId): void
    {
        // Cheap, draft-free rejections first, so a malformed or bogus pick
        // never causes an empty draft row to be created as a side effect.
        if (! Str::isUuid($plotId) || ! $this->pickerAppliesTo($cemeteryId)) {
            $this->addError('plot', 'Plot tidak valid.');

            return;
        }

        $plot = GravePlot::query()
            ->whereHas('block', fn ($query) => $query->where('cemetery_id', $cemeteryId))
            ->find($plotId);

        if ($plot === null) {
            $this->addError('plot', 'Plot tidak ditemukan pada TPU/TPS ini.');

            return;
        }

        if ($this->draftId === null) {
            // This is now the FIRST point in the journey that needs a real
            // `booking_drafts` row to exist — a hold's own foreign key
            // requires one, and the merged `saveStep1()` that used to create
            // it no longer runs until service type and services are chosen
            // too. Create it silently, exactly as the old `saveStep1()` did
            // (`StartBookingDraft` also issues the session binding, so the
            // later `saveStep1()` resolves THIS draft rather than starting a
            // second one) — but persist no DISCOVERY field onto it yet, and
            // do not redirect: the customer is still mid-selection.
            $draft = $this->currentOrNewDraft();
            $this->draftId = $draft->getKey();
            $this->version = $draft->version;
        } else {
            $draft = BookingDraftQuery::findBound($this->draftId);

            if ($draft === null) {
                // A draft id that no longer resolves means the resume token
                // is gone. Deliberately NOT a silent fall-back to a new
                // draft: that would strand whatever the customer had already
                // filled in on the old one.
                $this->autosaveState = 'failed';
                $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

                return;
            }
        }

        try {
            (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");
        } catch (PlotNotAvailableException) {
            $this->addError('plot', 'Plot ini baru saja dipilih oleh pengunjung lain. Silakan pilih plot lain.');

            return;
        }

        // Client-visible selection state only — persisted later, by the one
        // `saveStep1()` call, which re-validates both server-side.
        $this->cemeteryId = $cemeteryId;
        $this->cemeteryPackageId = $cemeteryPackageId;

        // Put the resumable draft URL in the address bar WITHOUT navigating.
        //
        // The nine-step flow got this for free: the old `saveStep1($cityCode)`
        // created the draft and redirected to `pemesanan-makam.draft`, so the
        // customer was already on `/pemesanan-makam/draft/{id}` before the
        // picker was reachable. Here the draft is created lazily above and
        // there is deliberately no redirect — a redirect would remount the
        // component and wipe the staged selections the customer is still
        // filling in. That left NOTHING carrying the draft id across a page
        // load: `#[Locked]` state survives Livewire round-trips but not F5 or
        // back-navigation, and `BookingDraftBinding` keys its session secret
        // BY draft id, so an id the browser has forgotten cannot be recovered
        // from the session. The customer would start a SECOND draft, fail to
        // re-hold their OWN plot (`PlotNotAvailableException`), and be told it
        // was "just taken by another visitor" while it stayed squatted under
        // the orphaned first draft for the whole TTL.
        //
        // `history.replaceState` rather than `pushState`: taking a hold is not
        // a new history entry the Back button should walk through, it is the
        // same screen acquiring an identity. Idempotent — replacing the URL
        // with the one already shown is a no-op.
        $this->js(sprintf(
            'history.replaceState(null, "", %s)',
            // `json_encode` (not string interpolation) is what makes this
            // injection-safe: the id is quoted and escaped as a JS string
            // literal rather than pasted into source. UNESCAPED_SLASHES only
            // keeps the emitted URL readable; both forms are valid JSON.
            json_encode(
                route('pemesanan-makam.draft', ['draftId' => $draft->getKey()]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        ));
    }

    /**
     * The merged customer+deceased save — REPLACES the old `saveStep6()`
     * (customer data) and `saveStep7()` (deceased data), which used to be
     * two separate saves against two now-deleted step constants. Under the
     * `CUSTOMER_AND_DECEASED_DATA` merge (Task 1/3) both payloads are
     * validated and persisted together in ONE call, matching
     * `SaveBookingDraftStep`'s own merged validator.
     *
     * No document keys are sent for the deceased fields. Upload is out of
     * scope for this lane and `SaveBookingDraftStep` now REFUSES
     * caller-supplied document paths outright, so sending explicit nulls
     * would only imply a capability that does not exist yet.
     */
    public function saveStep2(): void
    {
        $this->saveStepOrShowErrors(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, [
            'customer_full_name' => $this->customerFullName,
            'customer_mobile' => $this->customerMobile,
            'customer_email' => $this->customerEmail,
            'customer_address' => $this->customerAddress,
            'customer_relationship' => $this->customerRelationship,
            'customer_contact_channel' => $this->customerContactChannel,
            'privacy_notice_accepted' => $this->privacyNoticeAccepted,
            'deceased_full_name' => $this->deceasedFullName,
            'deceased_date_of_birth' => $this->deceasedDateOfBirth,
            'deceased_date_of_death' => $this->deceasedDateOfDeath,
            'deceased_relationship' => $this->deceasedRelationship,
            'deceased_gender' => $this->deceasedGender !== '' ? $this->deceasedGender : null,
        ]);
    }

    /**
     * The MANUAL branch (always rendered; the only one available while
     * `G-PAY-01` is closed). Unlike `openOnlinePayment()`, there is no
     * payment-gateway round-trip to wait for, so the draft is submitted as
     * an Order in the same request the step is saved — the customer's
     * booking becomes real and staff-visible (`BookingOrderResource`)
     * immediately, rather than waiting on a payment confirmation that, for
     * this branch, has no online step to arrive from.
     *
     * Previously this only called `saveStepOrShowErrors()` and stopped: the
     * draft was saved but no `Order` was ever created for a manual
     * submission, so every manual booking — the only payment path live on
     * production while `G-PAY-01` stays closed — was invisible to staff
     * outside a direct database query. Fixed by mirroring
     * `openOnlinePayment()`'s own submission chain (same idempotency key
     * shape, same Action), stopping after order creation since the manual
     * path has no quote/payment-session to open.
     */
    public function saveStep3(string $paymentMethod): void
    {
        if ($this->draftId === null) {
            $this->autosaveState = 'failed';
            $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

            return;
        }

        $draft = BookingDraftQuery::findBound($this->draftId);

        if ($draft === null) {
            $this->autosaveState = 'failed';
            $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

            return;
        }

        try {
            $saved = (new SaveBookingDraftStep)(
                $draft,
                BookingWizardStep::PAYMENT,
                [
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $this->paymentReference,
                ],
                $this->idempotencyKeyFor(BookingWizardStep::PAYMENT, [
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $this->paymentReference,
                ]),
                expectedVersion: $this->version,
            );

            $this->hydrateFrom($saved);
            $this->autosaveState = 'saved';
        } catch (BookingStepValidationException $e) {
            $this->autosaveState = 'failed';

            foreach ($e->getErrors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        } catch (BookingDraftVersionConflictException) {
            $this->handleVersionConflict();

            return;
        }

        // Same idempotency key `openOnlinePayment()` uses on this draft — a
        // resumed or duplicate manual submission returns the SAME order
        // rather than creating a second one (`SubmitBookingDraft`'s own
        // unique-index-backed guarantee). A failure here is reported and
        // swallowed rather than shown as a step error: the draft is already
        // saved and Step 9 renders correctly either way — with the real
        // order reference when this succeeded, or the honest "not yet
        // processed" copy when it did not (`render()`'s `confirmationData`
        // only carries an order reference when one really exists).
        try {
            app(SubmitBookingDraft::class)($saved, 'booking:'.$saved->id.':submit');
        } catch (DraftPlotHoldNoLongerValidException) {
            $this->routeBackToPlotPickerAfterExpiredHold();
        } catch (UnroutableProductTypeException|InvalidArgumentException $e) {
            report($e);
        }
    }

    /**
     * Step 8's ONLINE branch (only rendered when `G-PAY-01` is open): persist
     * the online method choice, run the submission chain — submit the draft
     * as an Order, ensure a current Quote exists, then open the hosted
     * checkout for the quote total — and redirect the customer to the
     * provider's checkout link.
     *
     * ---------------------------------------------------------------------------
     * Order/quote resolution — the P0 submission chain
     * ---------------------------------------------------------------------------
     * `SubmitBookingDraft` turns the draft into a commercial order,
     * idempotently on the draft-scoped key `booking:{draft}:submit` (a
     * re-click returns the SAME order; the database unique index is the
     * backstop), and routes it by product type (At-Need opens a
     * `FuneralCase`, Pre-Need registers interest). The quote is issued by
     * the wizard ONLY when the order has none yet — an operator-issued
     * quote from the off-screen confirmation journey always wins via
     * `Quote::currentFor()`. Quote lines are composed from the draft's
     * selected services through `ComposeQuoteLinesFromBookingDraft` (the
     * same `currentPriceVersion()` seam Step 5's summary prices with), so
     * the amount snapshot is exactly what the customer saw; a service with
     * no current price fails closed honestly rather than quoting a
     * fabricated amount.
     *
     * The six-condition guard then evaluates the REAL created state
     * (`OpenPaymentSession` is still the only path to a session). For a
     * freshly submitted order — confirmation, quote acceptance and opening
     * authorization are operator-side acts — it denies, which lands the
     * honest fail-closed copy with the manual path still live. The
     * redirect happens once those upstream conditions exist and the guard
     * passes.
     *
     * Exactly-once per journey: the opened session is remembered in the PHP
     * session under the draft id, so a double click or a resume re-points at
     * the SAME hosted checkout instead of opening a second one. Terminal
     * states (PAID/FAILED/EXPIRED) are rendered as state cards and never
     * re-opened here.
     */
    public function openOnlinePayment(): void
    {
        $this->onlinePaymentError = null;

        if ($this->draftId === null) {
            $this->onlinePaymentError = 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.';

            return;
        }

        $draft = BookingDraftQuery::findBound($this->draftId);

        if ($draft === null) {
            $this->onlinePaymentError = 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.';

            return;
        }

        $stored = session($this->onlinePaymentSessionKey());

        if (is_array($stored) && isset($stored['session_id'])) {
            $existing = PaymentSession::query()->find($stored['session_id']);

            if ($existing instanceof PaymentSession) {
                $state = SessionState::tryFrom((string) $existing->state);

                if ($state === SessionState::Paid || $state === SessionState::Failed || $state === SessionState::Expired) {
                    // The state card on the screen governs; a terminal
                    // session is never re-opened from here. The manual path
                    // is the recovery route for Failed/Expired.
                    return;
                }

                $link = is_string($stored['link_url'] ?? null) ? $stored['link_url'] : '';

                if ($link !== '') {
                    $this->redirect($link);

                    return;
                }
            }

            session()->forget($this->onlinePaymentSessionKey());
        }

        // The method choice is persisted exactly like the manual branch's —
        // same payload, same idempotency key — so `SaveBookingDraftStep`'s
        // server-side gate check is what admits ONLINE, never the button.
        try {
            $saved = (new SaveBookingDraftStep)(
                $draft,
                BookingWizardStep::PAYMENT,
                [
                    'payment_method' => BookingPaymentMethod::ONLINE,
                    'payment_reference' => $this->paymentReference,
                ],
                $this->idempotencyKeyFor(BookingWizardStep::PAYMENT, [
                    'payment_method' => BookingPaymentMethod::ONLINE,
                    'payment_reference' => $this->paymentReference,
                ]),
                expectedVersion: $this->version,
            );

            $this->hydrateFrom($saved);
            $this->autosaveState = 'saved';
        } catch (BookingStepValidationException $e) {
            $this->autosaveState = 'failed';

            foreach ($e->getErrors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        } catch (BookingDraftVersionConflictException) {
            $this->handleVersionConflict();

            return;
        }

        // The submission chain: submit the draft as an idempotent order,
        // then ensure a current quote exists before the opening attempt.
        // Every failure lands the same honest fail-closed copy with the
        // manual path (and the support escape) still on screen — never a 500.
        try {
            $order = app(SubmitBookingDraft::class)($saved, 'booking:'.$saved->id.':submit');

            $quote = Quote::currentFor($order);

            if (! $quote instanceof Quote) {
                // The quote's actor context comes from the same seam
                // `OpenPaymentSession` reads (`ActorContextResolver`); an
                // anonymous submission names the draft, mirroring
                // `SubmitBookingDraft`'s own initial-event reference — the
                // only stable, non-identifying reference that exists here.
                $actor = app(ActorContextResolver::class)->resolve();

                $quote = (new IssueQuote)(
                    $order,
                    (new ComposeQuoteLinesFromBookingDraft)($saved),
                    now()->addDays(7),
                    $actor->identityReference !== null
                        ? (string) $actor->identityReference
                        : 'booking_draft:'.$saved->id,
                    $actor->isAuthenticated() ? 'customer' : 'guest',
                );
            }
        } catch (UnpricedBookingServiceException) {
            $this->onlinePaymentError = 'Pembayaran online belum dapat dibuka karena harga layanan belum tersedia. Gunakan pembayaran manual atau hubungi dukungan.';
            $this->currentStep = BookingWizardStep::PAYMENT;

            return;
        } catch (UnroutableProductTypeException) {
            $this->onlinePaymentError = 'Pesanan jenis ini belum dapat dibayar secara online. Gunakan pembayaran manual atau hubungi dukungan.';
            $this->currentStep = BookingWizardStep::PAYMENT;

            return;
        } catch (DraftPlotHoldNoLongerValidException) {
            $this->routeBackToPlotPickerAfterExpiredHold();

            return;
        } catch (InvalidArgumentException|OverflowException) {
            // A submission or quote-composition failure: the draft cannot be
            // turned into a payable quote right now (no published package
            // version to quote against, a malformed stored selection, a line
            // amount that overflows the integer range, ...).
            // Same honest fail-closed shape as the guard's denials.
            $this->onlinePaymentError = 'Pesanan belum dapat diproses untuk pembayaran online saat ini. Gunakan pembayaran manual atau hubungi dukungan.';
            $this->currentStep = BookingWizardStep::PAYMENT;

            return;
        }

        try {
            $session = app(OpenPaymentSession::class)(new OpenPaymentSessionCommand(
                orderType: OrderType::Booking,
                orderRef: $order->reference,
                // The current quote's total in integer minor units — the
                // amount the guard's condition 5 verifies; never a
                // client-supplied figure.
                amountMinor: $quote->totalMinor()->toMinorInt(),
                merchantRef: (string) app(SettingsService::class)
                    ->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', '')),
                successReturnUrl: route('payments.return'),
                cancelReturnUrl: route('payments.cancel'),
            ));
        } catch (PaymentSessionOpeningDeniedException) {
            // The six-condition guard denied. Fixed Indonesian copy — the
            // guard's own messages are internal English and stay off-screen.
            $this->onlinePaymentError = 'Pembayaran online belum dapat dibuka saat ini karena konfirmasi pesanan, penawaran harga, atau otorisasi pembayaran belum lengkap. Gunakan pembayaran manual atau hubungi dukungan.';
            $this->currentStep = BookingWizardStep::PAYMENT;

            return;
        } catch (PaymentCheckoutProviderException|PaymentCheckoutUnavailableException) {
            $this->onlinePaymentError = 'Layanan pembayaran online sedang tidak tersedia. Silakan coba lagi atau gunakan pembayaran manual.';
            $this->currentStep = BookingWizardStep::PAYMENT;

            return;
        } catch (PaymentSessionOrderAlreadyPaidException) {
            // The order is already paid; a second session would only allow a
            // second charge. Honest copy instead of the generic denial — and
            // no `report()`: this is a normal customer action, not an error.
            $this->onlinePaymentError = 'Pesanan ini telah dibayar dan tidak perlu dibayar lagi.';
            $this->currentStep = BookingWizardStep::PAYMENT;

            return;
        } catch (Throwable $e) {
            report($e);
            $this->onlinePaymentError = 'Pembayaran online belum dapat diproses. Silakan gunakan pembayaran manual atau hubungi dukungan.';
            $this->currentStep = BookingWizardStep::PAYMENT;

            return;
        }

        session([
            $this->onlinePaymentSessionKey() => [
                'session_id' => $session->id,
                'link_url' => $session->payment_link_url,
            ],
        ]);

        $this->redirect($session->payment_link_url);
    }

    /**
     * The webhook-driven state of the session opened from this draft's
     * journey, for display only. `null` when no session was opened yet (or
     * the stored reference is gone) — the screen then shows the plain online
     * option. The state is ALWAYS read from the row (`ApplyPaymentSettlement`
     * is the row's only terminal-state writer); nothing here is ever taken
     * from a URL.
     *
     * ---------------------------------------------------------------------------
     * No provider status-lookup API exists — confirmed 25 Aug 2026
     * ---------------------------------------------------------------------------
     * A same-day reconciliation feature (`Actions\ReconcilePaymentSession`,
     * `Jobs\ReconcileStalePaymentSessionsJob`, and an earlier version of this
     * method) was built and briefly deployed to poll SumoPod for a session's
     * real status as a fallback when the webhook is delayed or fails. It was
     * reverted the same day: SumoPod's Managed Payment product has no
     * GET-payment-status endpoint at all — confirmed directly by the
     * merchant's own dashboard support, not merely undocumented. The
     * "fetchStatus" endpoint the reverted code called returned a real HTTP
     * 404 in production and was failing on every booking-wizard render for a
     * session still `AWAITING_PAYMENT`, adding ~400-500ms of real customer-
     * facing latency per render until reverted.
     *
     * SumoPod's own confirmation mechanisms are exactly two: the webhook
     * (`payment.completed`/`failed`/`expired`, `Http\Controllers\
     * WebhookController`, the only writer via `Actions\
     * ApplyPaymentSettlement`) and the browser return URL — which this
     * codebase deliberately never trusts for state (`Http\Controllers\
     * PaymentReturnController`'s own doc block, AC4: "never mark paid from
     * browser return URL"). SumoPod's dashboard can resend a failed webhook
     * delivery from its own Webhooks tab; there is no code-side substitute
     * for that pending a real staff-facing stale-session report (a genuinely
     * useful follow-up, not built here).
     *
     * @return array{state: SessionState|null, link_url: string|null}
     */
    private function onlinePaymentDisplayState(): array
    {
        if ($this->draftId === null) {
            return ['state' => null, 'link_url' => null];
        }

        $stored = session($this->onlinePaymentSessionKey());

        if (! is_array($stored) || ! isset($stored['session_id'])) {
            return ['state' => null, 'link_url' => null];
        }

        $session = PaymentSession::query()->find($stored['session_id']);

        if (! $session instanceof PaymentSession) {
            return ['state' => null, 'link_url' => null];
        }

        return [
            'state' => SessionState::tryFrom((string) $session->state),
            'link_url' => is_string($stored['link_url'] ?? null) ? $stored['link_url'] : null,
        ];
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
            $draft = BookingDraftQuery::findBound($this->draftId);

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

        $latest = $this->draftId !== null ? BookingDraftQuery::findBound($this->draftId) : null;

        if ($latest !== null) {
            $this->hydrateFrom($latest);
        }

        $this->addError('draft', 'Pemesanan ini telah diubah di perangkat atau tab lain. Halaman dimuat ulang dengan data terbaru — silakan periksa lalu coba lagi.');
    }

    /**
     * Per the roadmap's decision #7: an expired/lost draft plot hold at
     * submission time does NOT fall back to submitting without a
     * reservation — it blocks, and the customer is routed back to the plot
     * picker to re-pick. Reopens the picker for whichever cemetery the draft
     * had saved, so the customer lands on a live grid rather than the bare
     * cemetery list.
     *
     * The picker lives on DISCOVERY now that the old CEMETERY step has been
     * merged into it, so that is where this routes.
     */
    private function routeBackToPlotPickerAfterExpiredHold(): void
    {
        $this->currentStep = BookingWizardStep::DISCOVERY;
        $this->addError('plot', 'Plot yang Anda pilih sudah tidak lagi ditahan (kedaluwarsa atau diambil pengunjung lain). Silakan pilih plot lain.');

        if ($this->cemeteryId !== null && $this->pickerAppliesTo($this->cemeteryId)) {
            $this->pickerCemeteryId = $this->cemeteryId;
            $this->pickerCemeteryPackageId = $this->cemeteryPackageId;
        }
    }

    public function goToStep(int $step): void
    {
        // Navigating away from a step must not carry that step's "Tersimpan"
        // (or "Gagal menyimpan") indicator with it — nothing has been saved
        // on the step being opened.
        // EXCEPTION: Step 9 (CONFIRMATION) is the journey's terminus — the
        // user arrived there after a successful save and should keep seeing
        // the "draft saved" confirmation until they explicitly leave.
        if ($step !== BookingWizardStep::CONFIRMATION) {
            $this->autosaveState = 'idle';
        }

        if ($this->canReachStep($step)) {
            $this->currentStep = $step;
        }
    }

    /**
     * Screens and steps converge 1:1 post-step-reduction
     * (`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`
     * Decision 9) — kept as its own method (rather than inlining
     * `$this->currentStep` at all 4 Blade call sites) so the Blade template's
     * `@if ($this->currentScreen() === N)` guards read the same as before this
     * change, and so a future re-divergence of screens from steps has one
     * place to change.
     */
    public function currentScreen(): int
    {
        return $this->currentStep;
    }

    /**
     * `CONFIRMATION` is the only remaining special case: it is READ-ONLY, so
     * it is never written to `completed_steps`, which would otherwise lock
     * the user out of it the moment they navigated away. It is instead
     * reachable once the writable step before it (`PAYMENT`) is done — that
     * is the real precondition, since the confirmation screen exists to show
     * accumulated state, and the state is there.
     *
     * The old `SUMMARY`/`CUSTOMER_DATA` special cases existed only because
     * those were read-only-adjacent bridge steps in the nine-step model:
     * `SUMMARY` was a read-only step between `SERVICES` and `CUSTOMER_DATA`,
     * so `CUSTOMER_DATA` needed the same "reachable once its predecessor's
     * predecessor is done" treatment as `SUMMARY` itself. `SUMMARY` is gone
     * (cut, not merged — see `BookingWizardStep`'s own doc block), and
     * `CUSTOMER_DATA` no longer exists as a distinct constant (merged into
     * `CUSTOMER_AND_DECEASED_DATA`, which is a normal writable step already
     * covered by the `completedSteps`/`currentStep` check above). Every
     * remaining step's immediate predecessor is itself always a real,
     * writable, saved step, so no other special case is needed.
     */
    private function canReachStep(int $step): bool
    {
        if ($step === $this->currentStep || in_array($step, $this->completedSteps, true)) {
            return true;
        }

        return match ($step) {
            BookingWizardStep::CONFIRMATION => in_array(BookingWizardStep::PAYMENT, $this->completedSteps, true),
            default => false,
        };
    }

    private function currentOrNewDraft(): BookingDraft
    {
        if ($this->draftId !== null) {
            $existing = $this->resolveDraftById($this->draftId);
            if ($existing !== null) {
                return $existing;
            }
        }

        return (new StartBookingDraft)(auth()->id());
    }

    /**
     * Resolve a draft by id for a request-facing caller — the session
     * secret proves it (`BookingDraftQuery::findBound()`), OR, failing that,
     * the current authenticated user IS the draft's owner. The ownership
     * check is a deliberate, narrow reversal of `BookingDraftBinding`'s
     * original session-only ruling (see that class's doc block): an
     * authenticated `$candidate->user_id === auth()->id()` match is a
     * strictly stronger proof than the session secret it supplements, since
     * it cannot be reconstructed from a mere shared URL the way the
     * session-secret hole could. A successful rescue re-issues the binding
     * (`BookingDraftBinding::issue()`), which also re-establishes normal
     * session-bound resume for the rest of this visit.
     *
     * A guest, or an authenticated user who is not the owner, gets `null`
     * here exactly as a stranger always has — this method draws no
     * distinction between "unknown", "tampered", "not bound", and "real but
     * not yours".
     */
    private function resolveDraftById(string $draftId): ?BookingDraft
    {
        $draft = BookingDraftQuery::findBound($draftId);

        if ($draft !== null) {
            return $draft;
        }

        $candidate = BookingDraftQuery::find($draftId);

        if ($candidate !== null && auth()->check() && $candidate->user_id === auth()->id()) {
            BookingDraftBinding::issue($candidate);

            return $candidate;
        }

        return null;
    }

    /**
     * Step 9's line-item summary — the same `{lines: [{code, label,
     * quantity, unit_price, line_total}], total, all_prices_available}`
     * shape `BookingDraftQuery::summary()` produces, so the Blade table at
     * `wizard.blade.php`'s `$confirmationData['summary']` needs no shape
     * change either way.
     *
     * Prefers the ACTUAL issued quote (`Quote::currentFor($order)->lines`)
     * once one exists: that is the frozen commercial record the customer
     * already agreed to, immune to a catalog price changing between
     * issuance and viewing this screen (unlike a live recompute, which
     * could silently drift). Falls back to the live recompute — exactly
     * today's behaviour — only when no quote has been issued yet, which is
     * the normal case for a MANUAL-payment submission: `saveStep3()`'s
     * chain calls `SubmitBookingDraft` but never `IssueQuote` (only the
     * ONLINE branch in `openOnlinePayment()` does), so there is nothing
     * "issued" to show for those orders and the live recompute is still the
     * honest answer.
     */
    private function confirmationSummary(?Order $order, BookingDraft $draft): array
    {
        $quote = $order !== null ? Quote::currentFor($order) : null;

        if ($quote === null) {
            return BookingDraftQuery::summary($draft);
        }

        $lines = $quote->lines
            ->map(fn (QuoteLine $line): array => [
                'code' => null,
                'label' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_amount_minor,
                'line_total' => $line->line_total_minor,
            ])
            ->all();

        return [
            'lines' => $lines,
            'total' => $quote->totalMinor()->toMinorInt(),
            'all_prices_available' => true,
        ];
    }

    public function render(): View
    {
        $cemeteries = new Collection;
        $packagesByCemetery = [];
        $cemeteryCapabilities = [];
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

                // design-system.md §3.3's normative Cemetery card spec
                // (PUB-011) requires the SAME card content the public
                // directory (`resources/views/livewire/public/directory/
                // index.blade.php`) already renders: type badge, name,
                // photo, address, facilities, attributed price range, and
                // availability. Availability needs the cemetery's resolved
                // capability profile, projected through the same public
                // allowlist the directory uses
                // (`PublicCapabilityProjection::forCemetery()`) — resolved
                // once per cemetery here, exactly mirroring
                // `CemeteryDirectoryIndex::render()`'s own per-card
                // try/catch: one cemetery's capability-resolution failure
                // degrades to AC4's safe defaults rather than blanking the
                // whole step.
                $cemeteryCapabilities = $cemeteries
                    ->mapWithKeys(function (Cemetery $cemetery): array {
                        try {
                            $capabilities = PublicCapabilityProjection::forCemetery($cemetery);
                        } catch (Throwable $e) {
                            report($e);

                            $capabilities = PublicCapabilityProjection::from(
                                new CemeteryCapabilityProfile(CemeteryCapabilityProfile::safeDefaults())
                            );
                        }

                        return [$cemetery->id => $capabilities];
                    })
                    ->all();
            } catch (Throwable $e) {
                report($e);
                $this->cemeteryListUnavailable = true;
                $cemeteries = new Collection;
                $packagesByCemetery = [];
                $cemeteryCapabilities = [];
            }
        }

        // The open picker's own cemetery name, so its heading can say WHICH
        // cemetery's plots are on screen ("Pilih plot di TPU ..."). A bare
        // "Pilih plot" left the one control that can change the cemetery
        // selection unlabelled, which is exactly the confusion that made a
        // stale picker dangerous before `selectCemetery()` started closing
        // one. Same fail-honest discipline as every other read in this
        // method: a failure degrades to the unqualified heading, never a 500.
        $pickerCemeteryName = null;

        if ($this->pickerCemeteryId !== null) {
            try {
                $pickerCemeteryName = CemeteryPublicQuery::findPublishedById($this->pickerCemeteryId)?->name;
            } catch (Throwable $e) {
                report($e);
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
        $servicesCatalogUnavailable = false;

        // Screen 1's Step 4 section can be visible while $currentStep has
        // moved to an earlier step within the same screen (progressive
        // reveal keeps a completed section on screen after "Kembali") — see
        // BookingWizardProgressiveRevealTest and this method's own screen
        // (not step) guard below. This query now runs on every Screen 1
        // render rather than only on the exact SERVICES step, so — same
        // fail-honest discipline as the cemetery-list read above — it must
        // degrade instead of raising: a caught failure earlier in the SAME
        // request (e.g. the cemetery-list catch above) leaves this
        // unrelated read no safer on PostgreSQL, which poisons the whole
        // ambient transaction once any query inside it errors, so every
        // later query — even one touching a completely different table —
        // raises the same generic "current transaction is aborted" error
        // until rollback (SQLSTATE 25P02; SQLite has no such transaction
        // isolation and never reproduced this).
        if ($this->currentScreen() === 1) {
            try {
                $activeServices = ServiceCatalogQuery::allActive();

                $basicServices = $activeServices->filter(
                    static fn ($definition): bool => ServiceCode::isBasic((string) $definition->code),
                )->values();

                $additionalServices = $activeServices->reject(
                    static fn ($definition): bool => ServiceCode::isBasic((string) $definition->code),
                )->values();
            } catch (Throwable $e) {
                report($e);
                $servicesCatalogUnavailable = true;
                $basicServices = new Collection;
                $additionalServices = new Collection;
            }
        }

        // Ringkasan is a persistent summary card across the whole of Screen
        // 2 (steps 5-7), not only while $currentStep === SUMMARY exactly —
        // see this task's own report / the design spec's Screen 2 row. That
        // widening (one exact step to three) is also why this read needs the
        // same fail-honest try/catch the two Screen 1 reads above already
        // have: it now runs on three times as many renders, and
        // `BookingDraftQuery::summary()` reads the service catalogue for
        // every selected line, so a poisoned ambient transaction (SQLSTATE
        // 25P02 — see the Step 4 comment above) reaches it exactly as it
        // reaches them. A silently-null summary would be the dishonest
        // outcome here: the Blade card would simply vanish with no
        // explanation, so the failure gets its own explicit flag.
        $summary = null;
        $summaryUnavailable = false;
        if ($this->currentScreen() === 2 && $this->draftId !== null) {
            try {
                $draft = BookingDraftQuery::findBound($this->draftId);
                if ($draft !== null) {
                    $summary = BookingDraftQuery::summary($draft);
                }
            } catch (Throwable $e) {
                report($e);
                $summaryUnavailable = true;
            }
        }

        // Step 9's read gets the same guard, for the same reason — but its
        // failure mode is NOT the same as a genuine "no order yet" state, and
        // must not be papered over with the same copy. `$confirmationData`
        // stays null in BOTH cases (order genuinely does not exist yet vs.
        // this render simply could not confirm one), so a second,
        // independent flag — `$confirmationUnavailable` — exists specifically
        // to tell those two apart: by Step 9, `saveStep3()`'s submission
        // chain has USUALLY already created a real order (per Global
        // Constraint AC7/idempotency — `SubmitBookingDraft` keys its
        // uniqueness per DRAFT id), so if THIS read throws, we genuinely do
        // not know whether an order already exists. Telling the customer to
        // "start a new booking" in that case is actively dangerous: doing so
        // opens a NEW draft, which `SubmitBookingDraft`'s idempotency cannot
        // recognise as a duplicate of the one that may already exist, and a
        // second real order gets created. `wizard.blade.php`'s Screen 4
        // block renders a different, safe "try reloading — do not start a
        // new booking" state when this flag is true, and only falls back to
        // the original "not yet processed, start over" copy when the read
        // genuinely succeeded and simply found no order.
        $confirmationData = null;
        $confirmationUnavailable = false;
        if ($this->currentStep === BookingWizardStep::CONFIRMATION && $this->draftId !== null) {
            try {
                $draft = BookingDraftQuery::findBound($this->draftId);
            } catch (Throwable $e) {
                report($e);
                $draft = null;
                $confirmationUnavailable = true;
            }

            if ($draft !== null) {
                try {
                    // Only set once `saveStep3()`'s submission chain has
                    // actually created the order (the normal case) — a rare
                    // mid-request failure there is reported and swallowed, not
                    // surfaced here, so this stays null and Step 9 falls back
                    // to its honest "not yet processed" copy rather than
                    // claiming an order that does not exist.
                    $order = Order::query()->where('booking_draft_id', $draft->id)->first();

                    $confirmationData = [
                        'draft_id' => $draft->id,
                        'order_reference' => $order?->reference,
                        'summary' => $this->confirmationSummary($order, $draft),
                        'customer_name' => $draft->customer_full_name,
                        'customer_mobile' => $draft->customer_mobile,
                        'customer_email' => $draft->customer_email,
                        'deceased_name' => $draft->deceased_full_name,
                        'payment_method' => $draft->payment_method,
                        'payment_reference' => $draft->payment_reference,
                        'contact_channel_label' => BookingContactChannel::label($draft->customer_contact_channel),
                        'city_code' => $draft->city_code,
                        'cemetery_id' => $draft->cemetery_id,
                    ];
                } catch (Throwable $e) {
                    report($e);
                    $confirmationUnavailable = true;
                }
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
        // Read through `ModeResolver` rather than poking `G-PAY-01` directly:
        // AC7 wants the UI handed a named mode, and it keeps the view and
        // `SaveBookingDraftStep`'s server-side enforcement reading the same
        // one authority. The view decides what to RENDER from this; it is
        // never the thing that decides what is ALLOWED.
        $modes = app(ModeResolver::class);
        $paymentMode = $modes->paymentMode();

        // Step 9 previously promised "email atau WhatsApp" unconditionally.
        // Whether WhatsApp is a channel we actually have is G-WA-01's answer,
        // not the copywriter's, so the confirmation reads the mode and says
        // only what is true.
        $whatsAppMode = $modes->whatsAppMode();

        $onlinePaymentState = $this->onlinePaymentDisplayState();

        // ADR-0035 item 1's mitigation: "unmissable payment-step labelling
        // ... before any redirect to the sandbox." `PaymentProviders`
        // currently defines only the sandbox slug (production activation
        // is a separate, not-yet-made decision — see that class's own doc
        // block), so `config('payment.default')` resolving to it IS "we
        // are on the sandbox" today; this becomes false automatically the
        // day a real production provider slug is added and selected,
        // without this file changing.
        $isSandboxPayment = config('payment.default') === PaymentProviders::SUMOPOD_SANDBOX;

        // assumptions-and-gates.md §2: manual coordination is `G-PAY-01`'s
        // FALLBACK ("Manual coordination" column), not a second option
        // offered alongside online payment — `PaymentMode::ManualCoordination`'s
        // own doc block: Step 8 "renders manual coordination INSTEAD OF a
        // payment form" while the gate is closed. So the manual card is
        // shown whenever the gate is closed, and otherwise ONLY as the
        // honest recovery route once the online path has actually failed —
        // never simultaneously with a live/untried online option. That
        // matches every existing fail-closed test in
        // `BookingWizardOnlinePaymentTest` (`..._keeps_the_manual_path`
        // asserts `Pembayaran Manual` is visible exactly when
        // `onlinePaymentError` is set) and the Failed/Expired session copy
        // above, which explicitly tells the customer to use it.
        $showManualPayment = $paymentMode !== PaymentMode::Online
            || $this->onlinePaymentError !== null
            || $onlinePaymentState['state'] === SessionState::Failed
            || $onlinePaymentState['state'] === SessionState::Expired;

        return view('livewire.public.booking.wizard', [
            'cities' => CemeteryPublicQuery::launchCities(),
            'cemeteries' => $cemeteries,
            'packagesByCemetery' => $packagesByCemetery,
            'cemeteryCapabilities' => $cemeteryCapabilities,
            'basicServices' => $basicServices,
            'additionalServices' => $additionalServices,
            'servicesCatalogUnavailable' => $servicesCatalogUnavailable,
            'pickerCemeteryName' => $pickerCemeteryName,
            'summary' => $summary,
            'summaryUnavailable' => $summaryUnavailable,
            'confirmationData' => $confirmationData,
            'confirmationUnavailable' => $confirmationUnavailable,
            'paymentMode' => $paymentMode,
            'whatsAppMode' => $whatsAppMode,
            'onlineSessionState' => $onlinePaymentState['state'],
            'onlinePaymentLinkUrl' => $onlinePaymentState['link_url'],
            'isSandboxPayment' => $isSandboxPayment,
            'showManualPayment' => $showManualPayment,
            // Step 8's "Pembayaran Manual" fallback card: admin-configured
            // via SiteSettingsResource (`App\Support\BankTransferInfo`'s own
            // doc block) — null fields mean the operator has not entered a
            // real destination yet, and the view must render an honest
            // "belum dikonfigurasi" state rather than a fabricated one.
            'bankTransferConfigured' => BankTransferInfo::isConfigured(),
            'bankTransferBankName' => BankTransferInfo::bankName(),
            'bankTransferAccountNumber' => BankTransferInfo::accountNumber(),
            'bankTransferAccountHolder' => BankTransferInfo::accountHolder(),
        ])->layout('layouts.app', [
            'title' => 'Pemesanan Makam - Makam.co.id',
            'active' => null,
        ]);
    }
}
