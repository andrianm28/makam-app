<?php

declare(strict_types=1);

namespace App\Domain\Faq\Models;

use App\Domain\Faq\FaqCategoryCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `faq_categories` — see the migration
 * (`2026_07_26_170000_create_faq_categories_table.php`) for schema
 * reasoning. One of exactly six rows, seeded by
 * `2026_07_26_170400_seed_faq_categories_and_articles.php` from
 * `docs/product/faq-catalog.md` and never expected to change without a
 * product-catalogue decision first (`AGENTS.md` §FAQ: "Seed and preserve six
 * required categories.") — this batch builds no "create category"/"delete
 * category" write action for exactly that reason; only articles are
 * created/edited/published/unpublished/reordered by admins.
 */
final class FaqCategory extends Model
{
    protected $table = 'faq_categories';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $category): void {
            FaqCategoryCode::assertKnown($category->code);
        });
    }

    public function articles(): HasMany
    {
        return $this->hasMany(FaqArticle::class, 'category_id');
    }

    /**
     * The stable public presentation order — `faq-catalog.md`'s own listed
     * category order, mirrored into `sort_order` at seed time. Every public
     * category listing (a later batch's route) should query through this,
     * not a bare `::all()`, so a future re-seed or manual reorder is
     * reflected without a code change.
     */
    public function scopeOrderedForDisplay(Builder $query): void
    {
        $query->orderBy('sort_order');
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', $code)->first();
    }
}
