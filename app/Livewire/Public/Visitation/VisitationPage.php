<?php

declare(strict_types=1);

namespace App\Livewire\Public\Visitation;

use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\VisitationMode;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Actions\RequestVisitation;
use App\Domain\Visitation\Exceptions\VisitationBlackoutDateException;
use App\Domain\Visitation\Exceptions\VisitationCapacityExceededException;
use App\Domain\Visitation\Exceptions\VisitationClosedDayException;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBlackoutDate;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Domain\Visitation\VisitationPublicQuery;
use App\Livewire\Public\Directory\Support\PublicCapabilityProjection;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * The public `/kunjungan` page — Task 2 (Lane 2 — Visitation),
 * `.kiro/specs/visitation-booking` AC1/AC3/AC5/AC7, mode-aware per the
 * kiro design ("The visual difference must be unmistakable").
 *
 * Route shape: `/kunjungan` (slug-less → the cemetery picker) and
 * `/kunjungan/{cemeterySlug}` (the mode-aware page), registered in
 * `routes/web.php` under `kunjungan.index` / `kunjungan.cemetery`.
 *
 * ---------------------------------------------------------------------------
 * The mode authority, and the degraded fallback
 * ---------------------------------------------------------------------------
 * `$mode = PublicCapabilityProjection::forCemetery($cemetery)->visitationMode`
 * — the server-side capability read, never a front-end flag (design.md
 * §6.9). A `Throwable` during resolution degrades to the AC4 safe
 * defaults projected through the same allowlist (`CemeteryDetail`'s
 * pattern): the page renders an honest "informasi belum dapat dimuat"
 * banner and NO bookable control — a degraded page must not become a hole
 * in the projection, and a family must never mistake a failure for a
 * booking surface.
 *
 * INFORMATION_ONLY (and NONE) render the info banner with the policy's
 * visiting hours when the policy exists, else the generic "jam kunjungan
 * berlaku" line — NO form, no control that looks bookable. BOOKABLE
 * renders the request form.
 *
 * ---------------------------------------------------------------------------
 * 404 discipline
 * ---------------------------------------------------------------------------
 * `CemeteryPublicQuery::findPublishedBySlug` is consulted on mount AND
 * re-consulted on every render (an unpublished cemetery must stop being
 * visible immediately, not at the next full load) — the same rule as
 * `CemeteryDetail`, and unknown/draft slugs are indistinguishable.
 *
 * ---------------------------------------------------------------------------
 * The slot picker and the blackout tooltip job
 * ---------------------------------------------------------------------------
 * `VisitationPublicQuery::bookableDates()` returns only the pickable set
 * with its remaining capacity (blackouts EXCLUDED by that class's own
 * contract, which names this page as the disabled-date tooltip owner —
 * see its doc block). This page therefore reads the blackout window
 * itself (one `VisitationBlackoutDate` query, the reason being the
 * visitor-visible payload) and renders those dates as real, disabled
 * options carrying their reason — never silently absent. Dates with zero
 * `capacity_left` stay enabled-looking only in the data: they render
 * disabled ("penuh") so a family sees an honest reason rather than a
 * bare missing option (design.md §6.2).
 *
 * ---------------------------------------------------------------------------
 * The idempotency key and the confirmation card
 * ---------------------------------------------------------------------------
 * The key is derived server-side, session-scoped: sha256 of
 * (cemetery, date, contact) + the PHP session id — the same deterministic
 * "same logical submit, same key" shape the BookingWizard uses
 * (`idempotencyKeyFor()`/`onlinePaymentSessionKey()`), so a double-tap or
 * a retried round-trip re-points at the incumbent booking and renders the
 * SAME confirmation reference (AC7 — one row, never two). The reference
 * is ALSO remembered in the PHP session under a per-cemetery key, so a
 * refresh (or a resumed journey) restores the same confirmation card, and
 * the card carries AC5's instructions, change/cancel note, and the
 * fallback contact (`route('bantuan.index')`).
 *
 * The confirmation card never binds the booking model to the wire: the
 * component holds only the reference, and `render()` re-reads the booking
 * server-side — contact phone/email stay out of the Livewire payload.
 */
final class VisitationPage extends Component
{
    /**
     * How many days ahead the slot picker offers — a deliberately modest
     * window (two weeks) for an operator-managed calendar; nothing in the
     * spec fixes a horizon, so this is a named, single constant rather
     * than a magic number.
     */
    private const int BOOKABLE_WINDOW_DAYS = 14;

    /**
     * The allowlisted facility-request options (kiro design: "optional
     * facilities on a visitation request"). Keys are the stored values,
     * labels the public copy — an unknown key in a wire call is refused,
     * never silently stored.
     *
     * @var array<string, string>
     */
    private const array FACILITY_OPTIONS = [
        'kursi_roda' => 'Kursi roda',
        'pemandu' => 'Pemandu',
        'toilet' => 'Toilet',
        'parkir' => 'Parkir',
    ];

    public string $cemeterySlug = '';

    /**
     * Capability resolution failed and the AC4 safe defaults were
     * substituted — the page says plainly that the visitation picture is
     * degraded rather than presenting a fallback as if it were the
     * operator's own answer (the `CemeteryDetail` §6.5 pattern).
     */
    public bool $capabilitiesDegraded = false;

    /**
     * The reference of the session's incumbent booking for this cemetery,
     * when one exists — the confirmation card replaces the form.
     */
    public ?string $confirmationReference = null;

    // Form state (BOOKABLE mode only).
    public string $visitDate = '';

    public string $visitorCount = '';

    public string $contactPhone = '';

    public string $contactEmail = '';

    public string $accessibilityNeeds = '';

    /**
     * @var list<string>
     */
    public array $facilityRequests = [];

    public function mount(?string $cemeterySlug = null): void
    {
        $this->cemeterySlug = $cemeterySlug ?? '';

        if ($this->cemeterySlug === '') {
            return;
        }

        if (CemeteryPublicQuery::findPublishedBySlug($this->cemeterySlug) === null) {
            abort(404);
        }

        $this->confirmationReference = session($this->confirmationSessionKey());
    }

    public function render(): View
    {
        if ($this->cemeterySlug === '') {
            // The slug-less picker state: every published cemetery links
            // to its own mode-aware page — the destination, not the
            // picker, decides what the mode allows.
            $cemeteries = CemeteryPublicQuery::published();

            return view('livewire.public.visitation.page', [
                'cemetery' => null,
                'cemeteries' => $cemeteries,
                'mode' => null,
                'capabilitiesDegraded' => false,
                'policy' => null,
                'bookableDates' => [],
                'blackoutDates' => [],
                'facilityOptions' => self::FACILITY_OPTIONS,
                'confirmation' => null,
            ])->layout('layouts.app', [
                'title' => 'Kunjungan - Makam.co.id',
                'active' => null,
            ]);
        }

        $cemetery = CemeteryPublicQuery::findPublishedBySlug($this->cemeterySlug);

        if ($cemetery === null) {
            abort(404);
        }

        $this->capabilitiesDegraded = false;

        try {
            $capabilities = PublicCapabilityProjection::forCemetery($cemetery);
        } catch (Throwable $e) {
            report($e);

            $this->capabilitiesDegraded = true;
            $capabilities = PublicCapabilityProjection::from(
                new CemeteryCapabilityProfile(CemeteryCapabilityProfile::safeDefaults())
            );
        }

        $policy = null;
        $bookableDates = [];
        $blackoutDates = [];

        if ($capabilities->visitationMode === VisitationMode::BOOKABLE) {
            try {
                $policy = app(VisitationPublicQuery::class)->policyFor($cemetery);
            } catch (Throwable $e) {
                // The policy read failed — treat it exactly like "no policy
                // configured": the honest empty state below, never a 500.
                report($e);
            }

            if ($policy instanceof CemeteryVisitationPolicy) {
                $from = CarbonImmutable::today();
                $to = $from->addDays(self::BOOKABLE_WINDOW_DAYS - 1);

                $bookableDates = app(VisitationPublicQuery::class)->bookableDates($cemetery, $from, $to);
                $blackoutDates = $this->blackoutDatesFor($policy, $from, $to);
            }
        } elseif (! $this->capabilitiesDegraded) {
            // INFORMATION_ONLY / NONE still deserve the policy's hours
            // when they exist (the "else the capability default" branch
            // of the brief: the capability profile carries no hours, so
            // the policy is the source).
            try {
                $policy = app(VisitationPublicQuery::class)->policyFor($cemetery);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $confirmation = null;

        if ($this->confirmationReference !== null) {
            $confirmation = VisitationBooking::query()
                ->where('reference', $this->confirmationReference)
                ->first();

            if (! $confirmation instanceof VisitationBooking) {
                // A stale session entry (the booking row is gone) must not
                // trap the visitor on a phantom card — clear it and show
                // the page's normal state instead.
                session()->forget($this->confirmationSessionKey());
                $this->confirmationReference = null;
            }
        }

        return view('livewire.public.visitation.page', [
            'cemetery' => $cemetery,
            'mode' => $capabilities->visitationMode,
            'capabilitiesDegraded' => $this->capabilitiesDegraded,
            'policy' => $policy,
            'bookableDates' => $bookableDates,
            'blackoutDates' => $blackoutDates,
            'facilityOptions' => self::FACILITY_OPTIONS,
            'confirmation' => $confirmation,
        ])->layout('layouts.app', [
            'title' => 'Kunjungan - Makam.co.id',
            // Not one of <x-mk.header>'s four nav keys (see
            // CemeteryDirectoryIndex::render()'s note).
            'active' => null,
        ]);
    }

    /**
     * The AC3/AC7 submit path: validate, re-check the mode/date/capacity
     * the form implies (a wire call is not bound by the options a select
     * rendered), derive the session-scoped idempotency key, and run
     * `RequestVisitation` — which returns the INCUMBENT booking for a
     * duplicate key, so a repeated submission renders the same
     * confirmation and never a second row.
     */
    public function requestVisit(): void
    {
        if ($this->cemeterySlug === '' || $this->confirmationReference !== null) {
            return;
        }

        $cemetery = CemeteryPublicQuery::findPublishedBySlug($this->cemeterySlug);

        if ($cemetery === null) {
            abort(404);
        }

        $this->validate([
            'visitDate' => ['required'],
            'visitorCount' => ['required', 'integer', 'min:1'],
            'contactPhone' => ['required', 'string', 'max:32'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'facilityRequests' => ['array'],
        ]);

        // AC7's courtesy fast path, BEFORE the availability checks: a
        // duplicate submission of the SAME logical request must render the
        // incumbent confirmation even when the date has since filled up —
        // the date/capacity checks below would otherwise refuse a replay
        // whose booking already exists. The authoritative backstop remains
        // the idempotency_key unique constraint + `RequestVisitation`'s
        // classifier (which also returns the incumbent).
        $idempotencyKey = $this->idempotencyKeyFor();

        $incumbent = VisitationBooking::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($incumbent instanceof VisitationBooking) {
            $this->confirmationReference = $incumbent->reference;
            session([$this->confirmationSessionKey() => $incumbent->reference]);

            return;
        }

        $capabilities = $this->capabilitiesFor($cemetery);

        if ($capabilities->visitationMode !== VisitationMode::BOOKABLE) {
            $this->addError('visitDate', 'Kunjungan untuk lokasi ini belum dapat dipesan saat ini.');

            return;
        }

        $policy = app(VisitationPublicQuery::class)->policyFor($cemetery);

        if (! $policy instanceof CemeteryVisitationPolicy) {
            $this->addError('visitDate', 'Tanggal kunjungan belum tersedia untuk lokasi ini.');

            return;
        }

        $from = CarbonImmutable::today();
        $to = $from->addDays(self::BOOKABLE_WINDOW_DAYS - 1);
        $bookable = app(VisitationPublicQuery::class)->bookableDates($cemetery, $from, $to);

        if (! array_key_exists($this->visitDate, $bookable)) {
            $this->addError('visitDate', 'Tanggal yang dipilih tidak tersedia. Pilih tanggal lain.');

            return;
        }

        if ((int) $this->visitorCount > $bookable[$this->visitDate]['capacity_left']) {
            $this->addError('visitorCount', 'Jumlah pengunjung melebihi sisa kuota pada tanggal tersebut.');

            return;
        }

        $unknownFacilities = array_diff($this->facilityRequests, array_keys(self::FACILITY_OPTIONS));

        if ($unknownFacilities !== []) {
            $this->addError('facilityRequests', 'Permintaan fasilitas tidak dikenali.');

            return;
        }

        $actor = app(ActorContextResolver::class)->resolve();

        // TOCTOU window: the capacity/date checks above are courtesy fast
        // paths against `bookableDates()` — between that read and the
        // action's own locked, authoritative checks another request can
        // fill the date (or the policy can gain a blackout/close that
        // weekday). The three domain refusals are expected outcomes, not
        // failures: translate each to the SAME inline field error the
        // pre-check would have shown, never a 500 on the Livewire error
        // surface.
        try {
            $booking = app(RequestVisitation::class)(
                $cemetery,
                $this->visitDate,
                (int) $this->visitorCount,
                trim($this->contactPhone),
                filled($this->contactEmail) ? $this->contactEmail : null,
                filled($this->accessibilityNeeds) ? $this->accessibilityNeeds : null,
                $this->facilityRequests,
                $idempotencyKey,
                $actor->identityReference !== null
                    ? (string) $actor->identityReference
                    : 'visit_session:'.session()->getId(),
                $actor->isAuthenticated() ? 'customer' : 'guest',
                AuditSource::Api,
            );
        } catch (VisitationCapacityExceededException) {
            $this->addError('visitorCount', 'Jumlah pengunjung melebihi sisa kuota pada tanggal tersebut.');

            return;
        } catch (VisitationBlackoutDateException|VisitationClosedDayException) {
            $this->addError('visitDate', 'Tanggal yang dipilih tidak tersedia. Pilih tanggal lain.');

            return;
        }

        $this->confirmationReference = $booking->reference;
        session([$this->confirmationSessionKey() => $booking->reference]);

        $this->resetValidation();
    }

    /**
     * The card's "book another visit" escape: forget the session's
     * incumbent reference and reset the form, so one cemetery can receive
     * a second (distinct) request from the same session.
     */
    public function startAnotherRequest(): void
    {
        session()->forget($this->confirmationSessionKey());
        $this->confirmationReference = null;
        $this->visitDate = '';
        $this->visitorCount = '';
        $this->contactPhone = '';
        $this->contactEmail = '';
        $this->accessibilityNeeds = '';
        $this->facilityRequests = [];
        $this->resetValidation();
    }

    private function idempotencyKeyFor(): string
    {
        return hash('sha256', implode(':', [
            'visitation',
            $this->cemeterySlug,
            $this->visitDate,
            trim($this->contactPhone),
            session()->getId(),
        ]));
    }

    private function confirmationSessionKey(): string
    {
        return 'visitation.booking.'.$this->cemeterySlug;
    }

    /**
     * @return array<string, string> `Y-m-d` → reason
     */
    private function blackoutDatesFor(CemeteryVisitationPolicy $policy, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return VisitationBlackoutDate::query()
            ->where('policy_id', $policy->getKey())
            ->whereBetween('date', [$from, $to])
            ->get()
            ->mapWithKeys(fn (VisitationBlackoutDate $row): array => [
                $row->date->toDateString() => (string) $row->reason,
            ])
            ->all();
    }

    private function capabilitiesFor(Cemetery $cemetery): PublicCapabilityProjection
    {
        try {
            return PublicCapabilityProjection::forCemetery($cemetery);
        } catch (Throwable $e) {
            report($e);

            return PublicCapabilityProjection::from(
                new CemeteryCapabilityProfile(CemeteryCapabilityProfile::safeDefaults())
            );
        }
    }
}
