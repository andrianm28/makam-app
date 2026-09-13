<?php

declare(strict_types=1);

namespace App\Domain\PlotInventory\Actions;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
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
 * against. `$isActive` (default `true`, matching the column default and
 * the plan's Task 1 signature for every existing caller) lets the admin
 * create form's `is_active` toggle control the block row — the form field
 * is wired through this parameter, it is never a silent no-op.
 *
 * ---------------------------------------------------------------------------
 * Tracking-mode guard
 * ---------------------------------------------------------------------------
 * Refuses unless `$cemetery->plot_tracking_mode === PlotTrackingMode::GRANULAR`
 * — an aggregate-tier cemetery's availability is `cemetery_packages
 * .availability_status` (class-level capacity), never a specific plot's
 * existence, so a block/plot row for one would be meaningless inventory
 * an aggregate-tier order could still be handed as if it were real. This
 * is the forward-direction half of the same one-way-in-practice tier
 * boundary `SetCemeteryPlotTrackingMode` already enforces in reverse
 * (`GRANULAR -> AGGREGATE` refused while any block exists). Added
 * 29 Aug 2026 — discovered missing during the TPU/TPS operator dashboard
 * roadmap's Phase F final review, which had proven it absent by direct
 * reproduction; no existing cemetery in this environment's own seed data
 * or migrations had blocks against an aggregate-tier cemetery, so this
 * is a pure forward-looking guard, no backfill required.
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
    /**
     * PERF-16 — an explicit upper bound on `$capacity`, alongside the
     * existing `< 1` lower-bound guard. No cemetery block in this
     * codebase's seed data, tests, or product docs approaches four digits
     * of plots — a real "blok" is physically bounded by land area, and
     * this codebase's own examples describe one in the tens to low
     * hundreds. 10,000 is generous enough that no legitimate operator
     * input is ever rejected by it, while keeping a single request's
     * plot-row materialization bounded to a fixed, small multiple of what
     * any real block will ever need. This is a sanity/abuse guard, not a
     * substitute for the chunked-insert fix below: even 10,000 rows × 6
     * columns (60,000 bind params) would already sit under Postgres's
     * 65,535 bind-parameter ceiling in a single statement, but chunking
     * still protects PHP memory for the row-array itself and keeps this
     * action correct if `MAX_CAPACITY` is ever raised later.
     */
    public const int MAX_CAPACITY = 10_000;

    /**
     * Insert the generated plot rows in chunks of this size — same
     * reasoning as `MAX_CAPACITY`'s doc block: neither PHP memory nor
     * Postgres's bind-parameter limit should be the constraint for a
     * legitimately large but bounded block. 1,000 rows × 6 columns = 6,000
     * bind params per statement, comfortably under Postgres's 65,535
     * ceiling with wide headroom for future columns.
     */
    private const int INSERT_CHUNK_SIZE = 1000;

    public function __invoke(
        Cemetery $cemetery,
        string $code,
        string $name,
        int $capacity,
        int|string $actorReference,
        ?string $actorRole = 'admin',
        ?int $cemeteryPackageId = null,
        ?bool $isActive = true,
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): CemeteryBlock {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Cemetery block capacity must be at least 1.');
        }

        if ($capacity > self::MAX_CAPACITY) {
            throw new InvalidArgumentException(
                'Cemetery block capacity must not exceed '.self::MAX_CAPACITY.' plots.'
            );
        }

        if ($cemetery->plot_tracking_mode !== PlotTrackingMode::GRANULAR) {
            throw new InvalidArgumentException(
                "Cannot create a block for cemetery [{$cemetery->getKey()}]: it is not in granular tracking mode. ".
                'Switch it via SetCemeteryPlotTrackingMode first.'
            );
        }

        return Audit::wrap(
            mutation: function () use ($cemetery, $code, $name, $capacity, $cemeteryPackageId, $isActive, $actorReference, $actorRole, $auditSource, $reason): CemeteryBlock {
                $block = CemeteryBlock::create([
                    'cemetery_id' => $cemetery->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'capacity' => $capacity,
                    'is_active' => $isActive,
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

                // PERF-16 — chunked inserts, not one giant statement: see
                // MAX_CAPACITY's/INSERT_CHUNK_SIZE's doc blocks above.
                foreach (array_chunk($plotRows, self::INSERT_CHUNK_SIZE) as $chunk) {
                    GravePlot::query()->insert($chunk);
                }

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
