<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\Exceptions\SignedUrlGrantImmutableException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
 * The model and its custom query builder reject post-issuance mutation of the
 * document, actor binding, purpose, token, expiry, and deletion. The only
 * permitted transition is the conditional, single-use `consume()` write to
 * `consumed_at`; Task 7 owns when to call it after redemption checks pass.
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

        $grant->setAttribute(
            $grant->getKeyName(),
            DB::table($grant->getTable())->insertGetId($grant->getAttributes()),
        );
        $grant->exists = true;
        $grant->syncOriginal();

        return $grant;
    }

    /**
     * Allow exactly one concurrent caller to mark the grant consumed.
     */
    public function consume(): bool
    {
        if (! $this->exists || $this->consumed_at !== null) {
            return false;
        }

        $consumedAt = CarbonImmutable::now();
        $updated = self::query()->consume((string) $this->getKey(), $consumedAt);

        if (! $updated) {
            return false;
        }

        $this->setAttribute('consumed_at', $consumedAt);

        return true;
    }

    public function save(array $options = []): bool
    {
        throw SignedUrlGrantImmutableException::forOperation('save');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw SignedUrlGrantImmutableException::forOperation('update');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw SignedUrlGrantImmutableException::forOperation('performUpdate');
    }

    public function delete(): ?bool
    {
        throw SignedUrlGrantImmutableException::forOperation('delete');
    }

    public function newEloquentBuilder($query): SignedUrlGrantQueryBuilder
    {
        return new SignedUrlGrantQueryBuilder($query);
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
