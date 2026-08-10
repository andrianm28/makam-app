<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\DocumentAccessPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `signed_url_grants`
 * (`2026_08_09_100030_create_signed_url_grants_table.php`, plus
 * `2026_08_10_100020_add_signed_url_grant_actor_binding.php`).
 *
 * One row per signed-URL issuance: a single purpose, a single opaque token, a
 * hard expiry, and the actor the grant was minted for. Rows are written
 * exclusively through `issueGrant()` so `Actions\IssueSignedUrl` stays the
 * module's single write API for this table (the same discipline
 * `DocumentScan::recordAttempt()` already applies to `document_scans`, and
 * the reason `$guarded = ['*']` blocks mass assignment here).
 *
 * `expires_at` is set by the Action, which clamps every lifetime to
 * `IssueSignedUrl::MAX_TTL_SECONDS` (300 s, AC6). PostgreSQL independently
 * enforces `expires_at <= created_at + interval '5 minutes'`; that CHECK is
 * pgsql-only, so on the local SQLite test driver the Action's clamp is the
 * only thing enforcing it.
 *
 * This class deliberately exposes no `consume()`/`isExpired()` helper. Task 7
 * owns the redemption path and its rules (single-use, expiry, actor binding,
 * and a fresh `DocumentAccessPolicy` re-check); adding speculative accessors
 * here before that path exists would guess at them.
 */
final class SignedUrlGrant extends Model
{
    public $timestamps = false;

    protected $table = 'signed_url_grants';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    public static function issueGrant(
        Document $document,
        int|string $actorRef,
        DocumentAccessPurpose $purpose,
        string $token,
        CarbonImmutable $issuedAt,
        CarbonImmutable $expiresAt,
    ): static {
        $grant = new self;
        $grant->forceFill([
            'document_id' => $document->getKey(),
            'actor_ref' => (string) $actorRef,
            'purpose' => $purpose,
            'token' => $token,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_at' => $issuedAt,
        ]);
        $grant->save();

        return $grant;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => DocumentAccessPurpose::class,
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
