<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `renewal_external_markings` — AC10's evidence trail
 * for a payment settled outside the platform ("Admin/operator SHALL be able
 * to mark a renewal as paid externally, with evidence").
 *
 * This table does NOT participate in the AC11 duplicate-period guard
 * itself. The guard sits on `renewals_grave_period_unique`
 * (`grave_record_id`, `target_due_period`) on the parent `renewals` row —
 * `Renewal`'s own doc block explains why one `renewals` row per write path
 * (online or external) is what lets one index cover both. This table only
 * records WHO marked it, with WHAT evidence, and WHY, once that parent row
 * already exists with `source = RenewalSource::EXTERNAL`.
 *
 * `marked_by_actor_ref` is an opaque reference, matching
 * `App\Domain\GraveRegistry\Models\GraveRecord`'s
 * `heir_contact_reference` precedent for "reference, not raw PII" — an
 * actor identity is resolved through `App\Platform\IdentityAccess`, not
 * stored redundantly here.
 *
 * The privileged write path that populates this table
 * (`Actions\MarkExternalRenewal`, gated by `RenewalMarkingPolicy`) is a
 * later task in this lane (Task 7) and does not exist yet; this task only
 * creates the schema and the model.
 */
final class RenewalExternalMarking extends Model
{
    use HasUuids;

    protected $table = 'renewal_external_markings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'renewal_id',
        'marked_by_actor_ref',
        'evidence_reference',
        'reason',
        'marked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Renewal, $this>
     */
    public function renewal(): BelongsTo
    {
        return $this->belongsTo(Renewal::class, 'renewal_id');
    }
}
