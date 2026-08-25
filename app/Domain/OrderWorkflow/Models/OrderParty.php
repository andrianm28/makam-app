<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use App\Domain\OrderWorkflow\OrderPartyRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `order_parties` — see
 * `2026_08_12_100020_create_order_parties_table.php` for the schema, for why
 * every field except the role is nullable, and for why there is no
 * identity-number column.
 *
 * Every attribute here except `role` and `order_id` is restricted personal
 * data about a bereaved customer. `AGENTS.md` §Observability forbids placing
 * it in logs, Pulse, Horizon tags or error trackers; nothing in this module
 * puts any of it into an outbox payload, audit metadata, or an exception
 * message.
 */
final class OrderParty extends Model
{
    use HasUuids;

    protected $table = 'order_parties';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'user_id',
        'role',
        'full_name',
        'contact_phone',
        'contact_email',
        'address',
        'relationship_to_deceased',
        'preferred_contact_channel',
    ];

    public function role(): OrderPartyRole
    {
        return OrderPartyRole::from($this->role);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
