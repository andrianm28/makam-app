<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordProjection;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Domain\Renewal\Actions\QuoteRenewal;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Domain\Renewal\RenewalQuoteDraft;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\GraveSearchMode;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Step 4, PUB-032 — the fee screen. AC6 (tariff amount, source, effective
 * time) and AC7 (no invented late fine).
 *
 * ---------------------------------------------------------------------------
 * This screen reads. It does not write.
 * ---------------------------------------------------------------------------
 * Rendering calls `QuoteRenewal`, which calculates a `RenewalQuoteDraft` and
 * touches no table. Persistence happens only in `terimaDanLanjutkan()` — a
 * Livewire action, i.e. a POST the family triggers by accepting the quoted
 * figure — which calls `OpenRenewal`, the only writer.
 *
 * An earlier revision persisted from `mount()`. Every GET of this URL then
 * created a `renewals` row and claimed the AC11 unique business key for that
 * grave and period, so a refresh collided with the constraint and, worse, the
 * squatted key blocked the admin AC10 external-marking path for the same
 * grave. A GET must not write.
 *
 * ---------------------------------------------------------------------------
 * AC14 is enforced here, not left to the template
 * ---------------------------------------------------------------------------
 * The grave is resolved into a `GraveRecordProjection` — the same value object
 * the public search surface uses — and only the projection reaches the view,
 * so a field the record's `access_mode` withholds is genuinely absent rather
 * than merely unrendered. `GraveRecordProjection`'s own doc block makes that
 * argument in full.
 *
 * Neither the projection nor the draft is a public property. Both are derived
 * fresh inside `render()` and handed to the view as render data, so nothing
 * about the grave or the price rides in Livewire's serialised component
 * payload, and nothing a crafted payload could rewrite is ever read back.
 *
 * A record whose mode is anything other than `open` is restricted, and a
 * restricted record gets design-system.md §6.2's privacy-limited state with the
 * manual-assistance path instead of a fee: quoting a tariff for a grave whose
 * identity and dates are withheld would disclose, by implication, that the
 * withheld record is the one the visitor named.
 */
final class RenewalFee extends Component
{
    #[Url(as: 'makam', history: true)]
    public string $makam = '';

    /**
     * Set only by `terimaDanLanjutkan()`, to report a handled failure of the
     * acceptance itself. Read-path messages are derived in `render()`.
     */
    #[Locked]
    public string $actionMessage = '';

    /**
     * The family's explicit acceptance of the quoted figure — the only path
     * that persists a renewal, and what makes the quote's `accepted_at` stamp
     * a true statement (`AGENTS.md` §Domain and financial invariants: "Never
     * create payment before an accepted quote").
     *
     * Re-resolves the grave and re-quotes server-side rather than trusting any
     * value carried on the component. AC11's duplicate guard surfaces as a
     * handled message, never an unhandled database error.
     */
    public function terimaDanLanjutkan(): mixed
    {
        if ($this->makam === '') {
            return null;
        }

        if (app(ModeResolver::class)->graveSearchMode() === GraveSearchMode::ManualAssistance) {
            return null;
        }

        $grave = GraveRecord::query()->find($this->makam);

        if (! $grave instanceof GraveRecord) {
            return null;
        }

        if ((string) $grave->access_mode !== GraveRecordAccessMode::OPEN) {
            return null;
        }

        try {
            $renewal = app(OpenRenewal::class)($grave);
        } catch (DuplicateRenewalPeriodException) {
            $this->actionMessage = 'Perpanjangan untuk periode ini sudah tercatat. Silakan hubungi petugas kami untuk memeriksa statusnya.';

            return null;
        } catch (\InvalidArgumentException) {
            $this->actionMessage = 'Tarif tidak tersedia. Silakan hubungi petugas kami.';

            return null;
        }

        return $this->redirect(
            route('perpanjangan.pembayaran', ['perpanjangan' => $renewal->id]),
            navigate: true
        );
    }

    public function render(): View
    {
        $state = $this->resolveState();

        return view('livewire.public.renewal.fee', [
            ...$state,
            'currentStep' => RenewalJourneyStep::FEE,
            'stepLabels' => RenewalJourneyStep::labels(),
        ])->layout('layouts.app', [
            'title' => 'Biaya Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }

    /**
     * Resolves this render's screen state from server-side data only.
     *
     * @return array{errorMessage: string, privacyRestricted: bool, quoteUnavailable: bool, graveView: ?GraveRecordProjection, quote: ?RenewalQuoteDraft}
     */
    private function resolveState(): array
    {
        $empty = [
            'errorMessage' => '',
            'privacyRestricted' => false,
            'quoteUnavailable' => false,
            'graveView' => null,
            'quote' => null,
        ];

        if ($this->makam === '') {
            return [...$empty, 'errorMessage' => 'Data makam tidak ditemukan.'];
        }

        if (app(ModeResolver::class)->graveSearchMode() === GraveSearchMode::ManualAssistance) {
            return [
                ...$empty,
                'errorMessage' => 'Pencarian data makam secara online belum tersedia. Silakan hubungi petugas kami.',
            ];
        }

        $grave = GraveRecord::query()->with('cemetery')->find($this->makam);

        if (! $grave instanceof GraveRecord) {
            return [...$empty, 'errorMessage' => 'Data makam tidak ditemukan.'];
        }

        $graveView = GraveRecordProjection::fromRecord($grave, $grave->cemetery?->name);

        if ($graveView->isRestricted()) {
            return [...$empty, 'graveView' => $graveView, 'privacyRestricted' => true];
        }

        if ($this->actionMessage !== '') {
            return [...$empty, 'graveView' => $graveView, 'errorMessage' => $this->actionMessage];
        }

        try {
            $quote = app(QuoteRenewal::class)($grave);
        } catch (\InvalidArgumentException) {
            return [...$empty, 'graveView' => $graveView, 'quoteUnavailable' => true];
        }

        return [...$empty, 'graveView' => $graveView, 'quote' => $quote];
    }
}
