<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory\Actions;

use App\Domain\CemeteryDirectory\CemeteryAuditActions;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use InvalidArgumentException;

/**
 * The ONLY sanctioned write path to `cemeteries.plot_tracking_mode` —
 * `docs/superpowers/plans/2026-08-26-cemetery-plot-tracking-mode.md` §"Cemetery
 * tracking-tier concept". `AGGREGATE -> GRANULAR` is allowed freely (a
 * cemetery opting into real per-plot inventory). `GRANULAR -> AGGREGATE`
 * is refused — an honest `InvalidArgumentException`, the same style
 * `App\Domain\PlotInventory\Models\GravePlot`'s own delete guard uses —
 * while any `CemeteryBlock` row still exists for the cemetery: this is a
 * deliberate one-way-in-practice switch once real inventory exists,
 * matching the tier being a PERMANENT classification, not a toggle a
 * later data-entry mistake should be able to silently undo. A same-state
 * transition (the target mode already matches the current one) is a safe
 * no-op — no write, no audit row — not an error.
 *
 * This action does not itself check authorization — the same layering
 * `App\Domain\PlotInventory\Actions\CreateCemeteryBlock` uses: the
 * Filament call-site (a later phase — not built here) gates via
 * `MasterDataAdminAuthorizerContract` before invoking this action.
 *
 * ---------------------------------------------------------------------------
 * Auditing
 * ---------------------------------------------------------------------------
 * Wrapped in `Audit::wrap()` so the mode flip and its audit row commit
 * atomically, same as `CreateCemeteryBlock`. Not added to
 * `App\Platform\Audit\SensitiveActions::ACTIONS` — flipping a cemetery's
 * tracking tier is an operational/master-data decision, not a fraud- or
 * harm-shaped one, the same judgement `CemeteryAuditActions`'s own doc
 * block already documents for `CREATED`/`UPDATED`/`DELETED`.
 */
final class SetCemeteryPlotTrackingMode
{
    public function __invoke(
        Cemetery $cemetery,
        string $targetMode,
        int|string $actorReference,
        ?string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): Cemetery {
        PlotTrackingMode::assertKnown($targetMode);

        if ($cemetery->plot_tracking_mode === $targetMode) {
            return $cemetery;
        }

        if ($targetMode === PlotTrackingMode::AGGREGATE) {
            $blockCount = CemeteryBlock::query()->where('cemetery_id', $cemetery->getKey())->count();

            if ($blockCount > 0) {
                throw new InvalidArgumentException(
                    "Cannot switch cemetery [{$cemetery->getKey()}] to 'aggregate' mode: ".
                    "{$blockCount} cemetery block(s) still exist for it."
                );
            }
        }

        return Audit::wrap(
            mutation: function () use ($cemetery, $targetMode): Cemetery {
                $cemetery->update(['plot_tracking_mode' => $targetMode]);

                return $cemetery->fresh();
            },
            action: CemeteryAuditActions::PLOT_TRACKING_MODE_CHANGED,
            subject: fn (Cemetery $updated): AuditSubject => new AuditSubject('cemetery', $updated->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            reason: $reason ?? "Switched cemetery plot tracking mode to '{$targetMode}'.",
        );
    }
}
