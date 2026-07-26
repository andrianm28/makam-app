<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `evidence_requirements` — see the migration
 * (`2026_07_26_180600_create_evidence_requirements_table.php`) for schema
 * reasoning and the AC6 requirement this backs.
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

    public function item(): BelongsTo
    {
        return $this->belongsTo(ServicePackageItem::class, 'service_package_item_id');
    }
}
