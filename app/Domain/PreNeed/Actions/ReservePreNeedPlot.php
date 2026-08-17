<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedGate;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * The paid Pre-Need flow, optional step 2: `proposal -> reserved`, through
 * the P3 seam `ReservePlot` on the case's pre-need ORDER — the reservation
 * is order-scoped (one active hold per order), so it must run against the
 * order the interest was submitted under, not against the case.
 *
 * Gate first (`PreNeedGate::assertOpen()` — denial audited, then the
 * uniform `PreNeedGateClosedException`). Then the case is re-read under
 * its row lock, the status chain asserted, and the submit-time order
 * resolved (`PreNeedCase::order()`); an unresolvable order is an honest
 * refusal (`IllegalPreNeedCaseTransitionException::missingOrder`). The
 * whole sequence — `ReservePlot`'s own mutation (which opens its own
 * transaction and joins this one), the case link + status, and the
 * `PRENEED_RESERVED` audit row — commits together (`Audit::wrap()`).
 */
final readonly class ReservePreNeedPlot
{
    public function __construct(
        private ReservePlot $reservePlot,
    ) {}

    public function __invoke(
        PreNeedCase $case,
        GravePlot $plot,
        int|string $actorReference,
        string $actorRole,
        AuditSource $auditSource = AuditSource::Panel,
    ): PreNeedCase {
        PreNeedGate::assertOpen($actorReference, $actorRole, $auditSource);

        return Audit::wrap(
            mutation: fn (): PreNeedCase => $this->apply($case, $plot, $actorReference, $actorRole, $auditSource),
            action: PreNeedAuditActions::PRENEED_RESERVED,
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
        GravePlot $plot,
        int|string $actorReference,
        string $actorRole,
        AuditSource $auditSource,
    ): PreNeedCase {
        $current = PreNeedCase::query()->lockForUpdate()->findOrFail($case->getKey());

        $current->status()->assertAllows(PreNeedCaseStatus::RESERVED);

        $order = $current->order();

        if (! $order instanceof Order) {
            throw IllegalPreNeedCaseTransitionException::missingOrder((string) $current->getKey(), 'reserve');
        }

        $reservation = ($this->reservePlot)(
            $plot,
            $order,
            $actorReference,
            $actorRole,
            auditSource: $auditSource,
        );

        $current->forceFill([
            'status' => PreNeedCaseStatus::RESERVED->value,
            'plot_reservation_id' => $reservation->getKey(),
        ])->save();

        return $current;
    }
}
