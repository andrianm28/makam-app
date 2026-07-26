<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `substitution_policies` — see the migration
 * (`2026_07_26_180500_create_substitution_policies_table.php`) for schema
 * reasoning and the AC5 requirement this backs.
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
