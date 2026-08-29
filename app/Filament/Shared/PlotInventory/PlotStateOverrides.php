<?php

declare(strict_types=1);

namespace App\Filament\Shared\PlotInventory;

use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotInventoryAuditActions;
use App\Domain\PlotInventory\PlotState;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * The ONE write path for an admin plot-state override, shared by
 * `GravePlotsTable`'s three row actions and by the Phase D Floor/Block
 * Map's three cell actions.
 *
 * It exists because the from-state rule below is a security control, not a
 * cosmetic one (finding I2 on the P3 admin lane): Filament's `mountAction`
 * re-checks authorization and disabled state but NOT visibility, and a
 * Livewire method is addressable over the wire regardless of what was
 * rendered — so "the button was not drawn" is never enough, and the rule
 * must be re-asserted against a fresh re-read at write time. Two surfaces
 * now offer the same three overrides; a second hand-maintained copy of
 * that rule is exactly how the two would eventually disagree, and the
 * disagreement would free a plot behind an active reservation.
 *
 * Deliberately NOT in `app/Domain/` — it sends Filament notifications and
 * is therefore presentation, not domain. The domain invariants it leans on
 * (`PlotState::assertKnown()` in `GravePlot::booted()`, and the
 * `Audit::wrap` transaction) stay where they are.
 *
 * This class does NOT check authorization or re-authentication freshness.
 * Each calling surface owns those, because each has a different return URL
 * for the re-authentication redirect.
 */
final class PlotStateOverrides
{
    /**
     * The allowed from-state set for each override target — the SINGLE
     * source of truth consumed by both surfaces' render-time visibility
     * AND by `apply()`'s run-time re-read, so meaning and enforcement
     * cannot drift:
     * - `available` is only reachable FROM maintenance/occupied — never
     *   from `available` (a no-op) and never from `reserved`, whose claim
     *   belongs to an active reservation.
     * - `occupied` from available/reserved/maintenance.
     * - `maintenance` from any other state.
     *
     * @return list<string>
     */
    public static function fromStates(string $toState): array
    {
        return match ($toState) {
            PlotState::AVAILABLE => [PlotState::MAINTENANCE, PlotState::OCCUPIED],
            PlotState::OCCUPIED => [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::MAINTENANCE],
            PlotState::MAINTENANCE => [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::OCCUPIED],
            default => [],
        };
    }

    /**
     * Applies one override inside `Audit::wrap` +
     * `GRAVE_PLOT_STATE_CHANGED`, so the row change and its `audit_events`
     * entry commit in one transaction. The model's `saving` guard
     * (`PlotState::assertKnown`) runs inside that same transaction, so an
     * `InvalidArgumentException` rolls BOTH back and surfaces as a danger
     * notification rather than a 500.
     *
     * `fresh()` is re-read BEFORE the write and the from-state rule
     * re-asserted against it: a wire call against a view that has since
     * gone stale — `markAvailable` on a plot another actor just reserved —
     * is refused with no write.
     *
     * Returns whether a write actually happened, so a caller can decide
     * what to do next (e.g. skip a follow-up action when the override was
     * refused).
     */
    public static function apply(
        GravePlot $record,
        string $toState,
        string $successTitle,
        string $actorRole,
    ): bool {
        $fresh = $record->fresh() ?? $record;
        $fromState = $fresh->plot_state;

        if (! in_array($fromState, self::fromStates($toState), true)) {
            Notification::make()
                ->title('Status plot tidak dapat diubah.')
                ->body('Status plot saat ini tidak mengizinkan tindakan ini; tidak ada perubahan yang ditulis.')
                ->danger()
                ->send();

            return false;
        }

        try {
            Audit::wrap(
                fn (): bool => $fresh->update(['plot_state' => $toState]),
                action: PlotInventoryAuditActions::GRAVE_PLOT_STATE_CHANGED,
                subject: new AuditSubject('grave_plot', (string) $fresh->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: app(ActorContext::class)->identityReference,
                actorRole: $actorRole,
                source: AuditSource::Panel,
                reason: sprintf('Admin state override: plot %s → %s.', $fromState, $toState),
            );
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Status plot tidak dapat diubah.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()->title($successTitle)->success()->send();

        return true;
    }
}
