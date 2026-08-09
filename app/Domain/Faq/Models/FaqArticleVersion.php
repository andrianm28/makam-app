<?php

declare(strict_types=1);

namespace App\Domain\Faq\Models;

use App\Domain\Faq\Exceptions\FaqArticleVersionIsImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `faq_article_versions` — see the migration
 * (`2026_07_26_170200_create_faq_article_versions_table.php`) for schema
 * and the versioning-timing decision this table implements.
 *
 * Append-only: rows are written ONLY by `Actions\PublishFaqArticle`, one row
 * per successful publish, never updated or deleted afterward — mirrors this
 * codebase's established append-only-log pattern
 * (`App\Platform\Audit\Models\AuditEvent`,
 * `App\Platform\IdentityAccess\Mfa\Models\MfaChallenge`,
 * `App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent`).
 * Neither `created_at` nor `updated_at` exists on this table — only
 * `published_at`, an explicit, server-set fillable column — the identical
 * choice `ReauthenticationEvent`'s own doc block makes for the identical
 * reason: this is an append-only event log, not a record meant to look
 * editable.
 *
 * ENFORCEMENT ADDED 09 Aug 2026 (retrofit-faq fix wave) — until then the
 * paragraph above asserted append-only behaviour while citing a precedent
 * this class did not actually follow: `AuditEvent` overrides
 * `update()`/`performUpdate()`/`delete()`, this class overrode nothing, so
 * `$version->update([...])` and `$version->delete()` both simply worked.
 * The three overrides below close that. What they guarantee is exactly what
 * `AuditEvent`'s own doc block claims and no more: Eloquent-level
 * enforcement. A raw `DB::table('faq_article_versions')->update(...)` or a
 * direct SQL statement still bypasses them — there is no database-level
 * constraint behind this.
 */
final class FaqArticleVersion extends Model
{
    protected $table = 'faq_article_versions';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'faq_article_id',
        'version_number',
        'category_id',
        'title',
        'slug',
        'summary',
        'body',
        'published_at',
        'published_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'category_id' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<FaqArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(FaqArticle::class, 'faq_article_id');
    }

    /**
     * Always throws — see the class-level doc block. Blocks
     * `$version->update([...])`.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw FaqArticleVersionIsImmutableException::forOperation('update');
    }

    /**
     * Always throws — see the class-level doc block. Blocks
     * `$version->title = 'x'; $version->save();` on an already-persisted
     * (`exists === true`) instance, which routes through this method rather
     * than `update()`.
     */
    protected function performUpdate(Builder $query): bool
    {
        throw FaqArticleVersionIsImmutableException::forOperation('performUpdate');
    }

    /**
     * Always throws — see the class-level doc block. Blocks
     * `$version->delete()`.
     */
    public function delete(): ?bool
    {
        throw FaqArticleVersionIsImmutableException::forOperation('delete');
    }
}
