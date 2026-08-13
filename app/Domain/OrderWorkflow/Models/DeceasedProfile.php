<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `deceased_profiles` — see
 * `2026_08_12_100030_create_deceased_profiles_table.php` for the schema.
 *
 * HAS NO WRITER IN THIS TASK, deliberately. `Actions\SubmitBookingDraft`
 * creates no row here because `booking_drafts` carries no Step 7 data at
 * all and wizard steps 6-9 are owned by lane L6 and unbuilt — an empty or
 * placeholder profile on a funeral order would be fabricated data about a
 * deceased person. Read the migration's doc block before adding a writer.
 *
 * Every attribute except `order_id` is restricted personal data.
 * `AGENTS.md` §Observability applies exactly as it does to `OrderParty`.
 */
final class DeceasedProfile extends Model
{
    use HasUuids;

    protected $table = 'deceased_profiles';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'full_name',
        'date_of_birth',
        'date_of_death',
        'relationship_to_orderer',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'date_of_death' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
