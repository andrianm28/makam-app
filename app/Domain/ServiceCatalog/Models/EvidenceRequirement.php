<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `evidence_requirements` — see the migration
 * (`2026_07_26_180600_create_evidence_requirements_table.php`) for schema
 * reasoning and the AC6 requirement this backs.
 *
 * ---------------------------------------------------------------------------
 * THE AC2 GUARANTEE (per-item child side) — see `Models\ServicePackageVersion`
 * and `Models\ServicePackageItem` for the two levels above this one
 * ---------------------------------------------------------------------------
 * A completion-evidence requirement is CONTENT OF A VERSION, not metadata
 * beside it — `design.md` §Components: these "attach per item, not per
 * package, so a ... completion-evidence requirement can differ item to item
 * within one package". Deleting one from a published version, or flipping its
 * `is_required`, is a modification of that version in exactly the sense AC2
 * forbids, so `booted()` below derives editability through the owning item's
 * owning version, the same way `Models\ServicePackageItem` derives its own.
 *
 * Both the incoming and (for an already-persisted row) the ORIGINAL
 * `service_package_item_id` are checked, so re-pointing a requirement off a
 * published version's item is refused as well — the same one-directional
 * hole `ServicePackageItem` itself had.
 *
 * Same limit as every other guard in this module: Eloquent-level only. A raw
 * `DB::table('evidence_requirements')->update(...)` still bypasses it.
 */
final class EvidenceRequirement extends Model
{
    protected $table = 'evidence_requirements';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'service_package_item_id',
        'description',
        'is_required',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $requirement): void {
            self::assertOwningItemIsEditable($requirement);
        });

        self::deleting(function (self $requirement): void {
            self::assertOwningItemIsEditable($requirement);
        });
    }

    private static function assertOwningItemIsEditable(self $requirement): void
    {
        ServicePackageItem::assertOwningVersionOfItemIsEditable(
            $requirement->service_package_item_id,
            'evidence_requirements',
        );

        if ($requirement->exists) {
            ServicePackageItem::assertOwningVersionOfItemIsEditable(
                $requirement->getOriginal('service_package_item_id'),
                'evidence_requirements',
            );
        }
    }

    /**
     * @return BelongsTo<ServicePackageItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ServicePackageItem::class, 'service_package_item_id');
    }
}
