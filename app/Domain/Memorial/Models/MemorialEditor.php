<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `memorial_editors` — consent-gated family
 * authority (AC1). One row per grant; the row MUTATES on revocation
 * (`revoked_at` set, row kept — the audit trail stays intact), which is
 * exactly why the one-active-editor index is the partial unique
 * `WHERE revoked_at IS NULL` (releases on revoke — a revoked editor can
 * be granted again).
 *
 * `actor_id` is an identity reference (`ActorContext::$identityReference`
 * for whichever backend the actor came from); `consent_evidence_ref` is
 * the vault `documents.id` of the consent evidence `GrantMemorialEditor`
 * requires.
 */
final class MemorialEditor extends Model
{
    use HasUuids;

    protected $table = 'memorial_editors';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'memorial_profile_id',
        'actor_id',
        'consent_evidence_ref',
        'granted_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MemorialProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemorialProfile::class, 'memorial_profile_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * The revocation transition. Idempotent: revoking an already-revoked
     * editor is a no-op success (design.md's Error handling section —
     * a moderator retrying after an ambiguous response never gets
     * stuck), and it releases the partial-unique active slot so the
     * actor can be granted again.
     */
    public function revoke(): void
    {
        if (! $this->isActive()) {
            return;
        }

        $this->revoked_at = now();
        $this->save();
    }
}
