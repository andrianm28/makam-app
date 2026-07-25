<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication\Models;

use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `reauthentication_events` — see the migration
 * (`2026_07_26_160000_create_reauthentication_events_table.php`) for schema
 * and exactly why this is a separate table/concept from
 * `App\Platform\IdentityAccess\Mfa\Models\MfaChallenge`.
 *
 * Rows are written ONLY through
 * `App\Platform\IdentityAccess\Reauthentication\ReauthenticationService` —
 * one row per challenge raised, one row per satisfaction reported. Never
 * carries a credential, TOTP secret, recovery code, or password value — see
 * the migration's own doc block for the Negative-criteria reasoning.
 */
final class ReauthenticationEvent extends Model
{
    protected $table = 'reauthentication_events';

    /**
     * Neither `created_at` nor `updated_at` exists on this table — only
     * `occurred_at` (an explicit, server-set fillable column) — matching
     * `App\Platform\Audit\Models\AuditEvent` and
     * `App\Platform\IdentityAccess\Mfa\Models\MfaChallenge`'s identical
     * choice for the identical reason: this is an append-only event log,
     * not a record meant to look editable.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_ref',
        'actor_role',
        'reason',
        'outcome',
        'ip_address',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $event): void {
            ReauthenticationOutcome::assertKnown($event->outcome);
        });
    }

    public function wasChallenged(): bool
    {
        return $this->outcome === ReauthenticationOutcome::CHALLENGED;
    }

    public function wasSatisfied(): bool
    {
        return $this->outcome === ReauthenticationOutcome::SATISFIED;
    }
}
