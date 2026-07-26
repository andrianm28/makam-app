<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `actor_sessions` — see the migration
 * (`2026_07_26_100000_create_actor_sessions_table.php`) for the schema and
 * exactly what this batch does and does not implement (AC7 revocation is
 * NOT implemented here; only the column that a later batch will write to).
 *
 * Rows are written by `Listeners\RecordActorSessionOnLogin` (on the
 * standard `Illuminate\Auth\Events\Login` event, which Laravel's session
 * guard — and Filament's built-in login page, since it authenticates
 * through the same `web` guard — both dispatch automatically; this batch
 * does not need its own login controller to populate this table) and
 * updated by `Listeners\RecordActorSessionOnLogout`.
 *
 * Never store a credential, TOTP secret, or recovery code here (batch
 * brief's negative constraint, mirroring requirements.md's own Negative
 * criteria) — this table only ever carries session/device metadata.
 */
final class ActorSession extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'guard',
        'ip_address',
        'user_agent',
        'last_authenticated_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_authenticated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
