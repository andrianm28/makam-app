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
 * records WHO marked it, with WHAT evidence, and WHY. It is written by
 * `Actions\MarkExternalRenewal` (an EXTERNAL-sourced renewal is created
 * together with its marking) or by `Actions\MarkRenewalPaidExternally` (an
 * already-open renewal of ANY source — including ONLINE — settled with
 * money that changed hands off-platform), so an ONLINE-originated renewal
 * settled offline carries a marking row here too.
 *
 * `marked_by_actor_ref` is an opaque reference, matching
 * `App\Domain\GraveRegistry\Models\GraveRecord`'s
 * `heir_contact_reference` precedent for "reference, not raw PII" — an
 * actor identity is resolved through `App\Platform\IdentityAccess`, not
 * stored redundantly here.
 *
 * Both privileged write paths that populate this table now exist:
 * `Actions\MarkExternalRenewal` (gated by `RenewalMarkingPolicy`) and
 * `Actions\MarkRenewalPaidExternally` (gated by the admin order authorizer
 * and the re-authentication guard for finance actors) — see those actions'
 * own doc blocks for the gate details.
 */
final class RenewalExternalMarking extends Model
{
    use HasUuids;

    protected $table = 'renewal_external_markings';

    /**
     * @var list<string>
     */
    protected $fillable = [
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
