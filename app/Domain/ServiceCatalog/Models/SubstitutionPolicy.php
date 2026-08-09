<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `substitution_policies` — see the migration
 * (`2026_07_26_180500_create_substitution_policies_table.php`) for schema
 * reasoning and the AC5 requirement this backs.
 *
 * ---------------------------------------------------------------------------
 * THE AC2 GUARANTEE (per-item child side) — see `Models\ServicePackageVersion`
 * and `Models\ServicePackageItem` for the two levels above this one
 * ---------------------------------------------------------------------------
 * A substitution rule is CONTENT OF A VERSION, not metadata beside it —
 * `design.md` §Components: these "attach per item, not per package, so a
 * substitution rule ... can differ item to item within one package". Flipping
 * `requires_customer_approval` on a quote-referenced published version, or
 * deleting the rule outright, is a modification of that version in exactly
 * the sense AC2 forbids, so `booted()` below derives editability through the
 * owning item's owning version, the same way `Models\ServicePackageItem`
 * derives its own.
 *
 * Both the incoming and (for an already-persisted row) the ORIGINAL
 * `service_package_item_id` are checked, so re-pointing a rule off a
 * published version's item is refused as well — the same one-directional
 * hole `ServicePackageItem` itself had.
 *
 * Same limit as every other guard in this module: Eloquent-level only. A raw
 * `DB::table('substitution_policies')->update(...)` still bypasses it.
 */
final class SubstitutionPolicy extends Model
{
    protected $table = 'substitution_policies';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'service_package_item_id',
        'substitute_service_definition_id',
        'requires_customer_approval',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_customer_approval' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $policy): void {
            self::assertOwningItemIsEditable($policy);
        });

        self::deleting(function (self $policy): void {
            self::assertOwningItemIsEditable($policy);
        });
    }

    private static function assertOwningItemIsEditable(self $policy): void
    {
        ServicePackageItem::assertOwningVersionOfItemIsEditable(
            $policy->service_package_item_id,
            'substitution_policies',
        );

        if ($policy->exists) {
            ServicePackageItem::assertOwningVersionOfItemIsEditable(
                $policy->getOriginal('service_package_item_id'),
                'substitution_policies',
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

    /**
     * @return BelongsTo<ServiceDefinition, $this>
     */
    public function substituteServiceDefinition(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class, 'substitute_service_definition_id');
    }
}
