<?php

declare(strict_types=1);

namespace App\Livewire\Public\PreNeed;

use App\Domain\AgreementCertificate\CertificateStatusView;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PreNeed\Actions\RegisterPreNeedInterest;
use App\Domain\PreNeed\Actions\RequestPreNeedConsultation;
use App\Platform\FeatureGate\ModeResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * `/preneed` — Task 5 (`docs/superpowers/plans/
 * 2026-08-16-p5a-certificates-preneed.md`, Lane 3). The PUBLIC Pre-Need
 * surface while `G-LEGAL-01` keeps `feature.preneed_payment` in the
 * caller-facing §6.9 `InterestOnly` state: register interest, request a
 * consultation, or check a certificate's status by subject — state-only.
 *
 * ---------------------------------------------------------------------------
 * Draft usage — the plan's pinned draft-start seam
 * ---------------------------------------------------------------------------
 * `RegisterPreNeedInterest` takes a `BookingDraft`, and its doc block is
 * explicit that the draft carries the interest's `service_area`
 * (`$draft->city_code`). `App\Domain\Booking\Actions\StartBookingDraft`
 * takes NO payload — it creates an EMPTY draft, issues a session binding,
 * and emits `booking.draft_started.v1`, all of which exist to make a
 * booking *resumable through the wizard* (the L7 confirmation re-reads a
 * draft; `binding-wizard-fields.md`: "Draft created at first meaningful
 * input"). A Pre-Need interest's draft is the opposite thing: a minimal,
 * throwaway carrier of `city_code` + `service_type`, never resumed via
 * `/pemesanan-makam/draft/{id}`. The plan's decision therefore has this
 * page create the minimal draft directly through `BookingDraft::query()
 * ->create(...)` with exactly the two saving-hook-valid fields, then hand
 * it to `RegisterPreNeedInterest`. The saving hook re-asserts both
 * (`LaunchCityQuery::isKnown` + `BookingServiceType::assertKnown`)
 * server-side on every write, so a tampered wire value is refused there,
 * not trusted here.
 *
 * ---------------------------------------------------------------------------
 * The mode banner is non-dismissible and comes from the mode, not here
 * ---------------------------------------------------------------------------
 * `$mode = app(ModeResolver::class)->preNeedMode()` is read SERVER-SIDE on
 * every render and its `fallback()` supplies the banner's `intent` and
 * `dismissible`. While the gate is closed that fallback is
 * `new GateFallback('info', dismissible: false)` — §6.9 forbids dismissal
 * for a mode that changes what the visitor receives (here: no payment /
 * no paid booking can be created). The interest flow itself is never
 * gated (Negative criteria: "the interest-registration step is never
 * removed"), so the forms below work in both modes; only the banner
 * changes.
 *
 * ---------------------------------------------------------------------------
 * Certificate status: state-only, by subject
 * ---------------------------------------------------------------------------
 * The section resolves `CertificateStatusView::forSubject` — AC6's
 * customer projection whose PINS the key set to type/status/version/
 * effective_at/issued_by_role. The blade renders ONLY those five fields:
 * the certificate reference, the vault `document_id`, and the subject
 * internal never leave the server through it. The subject is resolved from
 * the wire type+id through `CERTIFICATE_SUBJECT_TYPES` — the only
 * pollable subject on this branch is a settled `Order`
 * (`CertificateEligibilityPolicy`), and the id is a UUID (the same
 * "whoever holds a token sees the state-only view" shape the renewal and
 * memorial token routes already use), so a missing/wrong id yields the
 * honest empty state, never a 404 or a leak. Task 2's route
 * `/sertifikat/{subjectType}/{subjectId}` is the canonical in-page
 * destination once that lane merges; this section is the self-contained
 * seam that must exist while it does not.
 */
final class PreNeedInterestPage extends Component
{
    /**
     * The allowlisted certificate subjects a customer may look up. Key =
     * the wire value, `label` = the public option copy, `model` = the class
     * `CertificateStatusView::forSubject` resolves. Only subjects a
     * certificate can actually be issued against belong here — see the
     * class doc block.
     *
     * @var array<string, array{label: string, model: class-string<Model>}>
     */
    private const array CERTIFICATE_SUBJECT_TYPES = [
        'order' => ['label' => 'Pesanan Pemakaman', 'model' => Order::class],
    ];

    // Interest form.
    public string $interestCity = '';

    public string $interestName = '';

    public string $interestContact = '';

    /**
     * The interest registered on this visit, if any — consulted so the
     * consultation form's request can be linked to it (never trust a
     * client-sent interest id over the wire).
     */
    public ?string $registeredInterestId = null;

    public bool $interestRegistered = false;

    // Consultation form.
    public string $consultName = '';

    public string $consultContact = '';

    public string $consultMessage = '';

    public bool $consultationSubmitted = false;

    // Certificate status section.
    public string $certSubjectType = '';

    public string $certSubjectId = '';

    /**
     * Last lookup's state-only projection (`CertificateStatusView`).
     *
     * @var list<array{
     *     type: string,
     *     status: string,
     *     version: int,
     *     effective_at: string|null,
     *     issued_by_role: string,
     * }>
     */
    public array $certificates = [];

    public bool $certChecked = false;

    /**
     * The §6.9 InterestOnly entry point: register interest with
     * `G-LEGAL-01` closed (and open — the flow is never gated). Writes the
     * minimal `PRE_NEED` draft then hands it to the sole writer.
     */
    public function registerInterest(): void
    {
        if ($this->interestRegistered) {
            return;
        }

        $this->validate([
            'interestCity' => ['required'],
            'interestName' => ['required', 'string', 'max:255'],
            'interestContact' => ['required', 'string', 'max:255'],
        ]);

        // The saving hook asserts city_code/service_type on the DRAFT's own
        // write too, but a failed-hook exception mid-loop would 500 the page;
        // check the city here so a tampered value becomes a field error, not
        // an exception. `LaunchCityQuery::isKnown` fails CLOSED (throws on an
        // unreadable table), so a code that cannot be verified is refused.
        if (! LaunchCityQuery::isKnown($this->interestCity)) {
            $this->addError('interestCity', 'Kota peluncuran tidak dikenali.');

            return;
        }

        $draft = BookingDraft::query()->create([
            'city_code' => $this->interestCity,
            'service_type' => BookingServiceType::PRE_NEED,
        ]);

        $interest = app(RegisterPreNeedInterest::class)($draft);

        $this->registeredInterestId = $interest->getKey();
        $this->interestRegistered = true;
    }

    /**
     * The complementary consultation entry point — `G-LEGAL-01`-independent
     * (see `RequestPreNeedConsultation`'s doc block). Linked to the interest
     * registered on this same visit when there is one.
     */
    public function requestConsultation(): void
    {
        if ($this->consultationSubmitted) {
            return;
        }

        $this->validate([
            'consultName' => ['required', 'string', 'max:255'],
            'consultContact' => ['required', 'string', 'max:255'],
            'consultMessage' => ['required', 'string', 'max:2000'],
        ]);

        app(RequestPreNeedConsultation::class)(
            name: $this->consultName,
            contact: $this->consultContact,
            message: $this->consultMessage,
            preNeedInterestId: $this->registeredInterestId,
        );

        $this->consultationSubmitted = true;
    }

    /**
     * Resolve the subject and re-run AC6's state-only projection. A
     * missing/wrong subject id renders the honest empty state below (never
     * a 404, never a partial projection).
     */
    public function checkCertificateStatus(): void
    {
        $subject = $this->certificateSubject();

        $this->certificates = $subject instanceof Model
            ? app(CertificateStatusView::class)->forSubject($subject)
            : [];

        $this->certChecked = true;
    }

    private function certificateSubject(): ?Model
    {
        if ($this->certSubjectId === '') {
            return null;
        }

        $entry = self::CERTIFICATE_SUBJECT_TYPES[$this->certSubjectType] ?? null;

        if ($entry === null) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = $entry['model'];

        $subject = $model::query()->find($this->certSubjectId);

        return $subject instanceof Model ? $subject : null;
    }

    public function render(): View
    {
        $mode = app(ModeResolver::class)->preNeedMode();

        return view('livewire.public.pre-need.pre-need-interest-page', [
            'cities' => LaunchCityQuery::activeCities(),
            'mode' => $mode,
            'fallback' => $mode->fallback(),
            'certificateSubjectTypes' => self::CERTIFICATE_SUBJECT_TYPES,
        ])->layout('layouts.app', [
            'title' => 'Pre-Need - Makam.co.id',
            // Not one of <x-mk.header>'s four nav keys ('pemesanan' |
            // 'layanan' | 'perpanjangan' | 'faq').
            'active' => null,
        ]);
    }
}
