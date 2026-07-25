<?php

declare(strict_types=1);

namespace App\Domain\Faq\Models;

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

    public function article(): BelongsTo
    {
        return $this->belongsTo(FaqArticle::class, 'faq_article_id');
    }
}
