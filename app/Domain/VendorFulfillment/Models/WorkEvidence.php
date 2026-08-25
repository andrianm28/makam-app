<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `work_evidence` — photographic/documentary evidence
 * uploaded against a work order, referencing a `documents` row in the
 * vault. Evidence is private to authorized parties (AC4).
 */
final class WorkEvidence extends Model
{
    use HasUuids;

    protected $table = 'work_evidence';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'work_order_id',
        'document_id',
        'evidence_type',
        'uploaded_by',
    ];

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uploaded_by' => 'integer',
        ];
    }
}
