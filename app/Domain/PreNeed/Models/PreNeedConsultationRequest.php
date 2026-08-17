<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `pre_need_consultation_requests` — see
 * `2026_08_17_100030_create_pre_need_consultation_requests_table.php` for
 * the schema and for why the table carries nothing financial and is
 * `G-LEGAL-01`-independent.
 *
 * Created only by `App\Domain\PreNeed\Actions\RequestPreNeedConsultation`.
 *
 * No write guard, for the reason `App\Domain\PreNeed\Models\PreNeedInterest`
 * sets out verbatim: this record settles no money and releases no unique
 * index, and the writer's `Audit::wrap` transaction pairs every row with
 * its `PRENEED_CONSULTATION_REQUESTED` audit event.
 */
final class PreNeedConsultationRequest extends Model
{
    use HasUuids;

    protected $table = 'pre_need_consultation_requests';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'contact',
        'message',
        'pre_need_interest_id',
    ];

    /**
     * @return BelongsTo<PreNeedInterest, $this>
     */
    public function interest(): BelongsTo
    {
        return $this->belongsTo(PreNeedInterest::class, 'pre_need_interest_id');
    }
}
