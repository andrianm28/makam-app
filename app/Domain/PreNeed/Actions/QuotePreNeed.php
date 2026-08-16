<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedGate;
use App\Domain\Quotation\Actions\ComposeQuoteLinesFromBookingDraft;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use Carbon\CarbonInterface;

/**
 * The paid Pre-Need flow, step 3: `proposal|reserved -> quoted`, through
 * the P0 seam — the quote's lines come from the draft's selected services
 * via `ComposeQuoteLinesFromBookingDraft` (the exact mapper Step 5's
 * summary prices with), and `IssueQuote` prices them on the case's
 * pre-need ORDER. The draft is reached through the same submit-time chain
 * as the order: case -> interest -> `booking_draft_id`.
 *
 * Gate first (`PreNeedGate::assertOpen()` — denial audited, then the
 * uniform `PreNeedGateClosedException`). Then, under the case-row lock:
 * the status chain is asserted, the order and the draft are resolved (both
 * null-resistant — honest refusals, never fabricated lines), and the
 * issued quote's id is linked to the case. `IssueQuote` opens its own
 * transaction (locking the ORDER row and superseding the incumbent
 * version) and joins this one, so the quote, the case link + status, and
 * the `PRENEED_QUOTED` audit row commit together.
 */
final readonly class QuotePreNeed
{
    public function __construct(
        private ComposeQuoteLinesFromBookingDraft $composeLines,
        private IssueQuote $issueQuote,
    ) {}

    public function __invoke(
        PreNeedCase $case,
        CarbonInterface $expiresAt,
        int|string $actorReference,
        string $actorRole,
        AuditSource $auditSource = AuditSource::Panel,
    ): PreNeedCase {
        PreNeedGate::assertOpen($actorReference, $actorRole, $auditSource);

        return Audit::wrap(
            mutation: fn (): PreNeedCase => $this->apply($case, $expiresAt, $actorReference, $actorRole),
            action: PreNeedAuditActions::PRENEED_QUOTED,
            subject: new AuditSubject('pre_need_case', $case->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    private function apply(
        PreNeedCase $case,
        CarbonInterface $expiresAt,
        int|string $actorReference,
        string $actorRole,
    ): PreNeedCase {
        $current = PreNeedCase::query()->lockForUpdate()->findOrFail($case->getKey());

        $current->status()->assertAllows(PreNeedCaseStatus::QUOTED);

        $order = $current->order();

        if (! $order instanceof Order) {
            throw IllegalPreNeedCaseTransitionException::missingOrder((string) $current->getKey(), 'quote');
        }

        $draft = $current->interest?->bookingDraft;

        if (! $draft instanceof BookingDraft) {
            throw IllegalPreNeedCaseTransitionException::missingDraft((string) $current->getKey(), 'quote');
        }

        $quote = ($this->issueQuote)(
            order: $order,
            // The P0 seam: the same line shape Step 5's summary uses, so the
            // quoted amounts are exactly what the customer saw.
            lines: ($this->composeLines)($draft),
            expiresAt: $expiresAt,
            actorRef: (string) $actorReference,
            actorRole: $actorRole,
        );

        $current->forceFill([
            'status' => PreNeedCaseStatus::QUOTED->value,
            'quote_id' => $quote->getKey(),
        ])->save();

        return $current;
    }
}
