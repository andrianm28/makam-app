<?php

declare(strict_types=1);

namespace App\Domain\PlotInventory\Actions;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotInventoryAuditActions;
use App\Domain\PlotInventory\PlotState;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a `cemetery_blocks` row and bulk-generates its `grave_plots`
 * rows in one transaction — the ONLY way a block enters the system
 * (`docs/superpowers/specs/2026-08-16-plot-inventory-reservation-design.md`
 * §4.1). Every plot is born `available`, with a zero-padded slot
 * (`001..N`); an optional `$cemeteryPackageId` links every generated plot
 * to the cemetery's package/class row the operator generated the block
 * against.
 *
 * ---------------------------------------------------------------------------
 * Auditing
 * ---------------------------------------------------------------------------
 * Both `CEMETERY_BLOCK_CREATED` and `GRAVE_PLOTS_GENERATED` are recorded
 * in the same transaction as the writes (AC4, `Audit::wrap`). The brief's
 * metadata (`capacity` / `plot_count`) is deliberately NOT on
 * `App\Platform\Audit\MetadataAllowlist::ALLOWED_KEYS` and the allowlist
 * is not extended for this module — the two numbers travel in the audit
 * `reason` text instead. Neither action is on
 * `SensitiveActions::ACTIONS` (content master-data, the same judgement
 * `CemeteryPackageAuditActions` documents), so `$reason` is optional; a
 * caller-supplied one is honoured verbatim.
 *
 * The plot rows are inserted in bulk (one statement, explicit UUID ids —
 * `HasUuids` only fires on model saves, not query-builder inserts), so
 * the model's `saving` state guard is bypassed by construction — the
 * values are fixed constants (`available`, zero-padded slots), which is
 * exactly the guarantee the guard exists for.
 */
final class CreateCemeteryBlock
{
    public function __invoke(
        Cemetery $cemetery,
        string $code,
        string $name,
        int $capacity,
        int|string $actorReference,
        ?string $actorRole = 'admin',
        ?int $cemeteryPackageId = null,
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): CemeteryBlock {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Cemetery block capacity must be at least 1.');
        }

        return Audit::wrap(
            mutation: function () use ($cemetery, $code, $name, $capacity, $cemeteryPackageId, $actorReference, $actorRole, $auditSource, $reason): CemeteryBlock {
                $block = CemeteryBlock::create([
                    'cemetery_id' => $cemetery->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'capacity' => $capacity,
                    'is_active' => true,
                ]);

                $plotRows = [];
                for ($i = 1; $i <= $capacity; $i++) {
                    $plotRows[] = [
                        'id' => (string) Str::uuid(),
                        'block_id' => $block->getKey(),
                        'slot' => str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                        'plot_state' => PlotState::AVAILABLE,
                        'cemetery_package_id' => $cemeteryPackageId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                GravePlot::query()->insert($plotRows);

                Audit::record(
                    action: PlotInventoryAuditActions::GRAVE_PLOTS_GENERATED,
                    subject: new AuditSubject('cemetery_block', $block->getKey()),
                    outcome: AuditOutcome::Allowed,
                    actorRef: $actorReference,
                    actorRole: $actorRole,
                    source: $auditSource,
                    reason: $reason ?? "Bulk-generated {$capacity} grave plots for cemetery block {$code}.",
                );

                return $block;
            },
            action: PlotInventoryAuditActions::CEMETERY_BLOCK_CREATED,
            subject: fn (CemeteryBlock $block): AuditSubject => new AuditSubject('cemetery_block', $block->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            reason: $reason ?? "Created cemetery block {$code} with capacity {$capacity}.",
        );
    }
}
