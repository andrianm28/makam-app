<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use App\Domain\ServiceCatalog\ServicePackageVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `service_packages` — see the migration
 * (`2026_07_26_180100_create_service_packages_table.php`) for schema
 * reasoning. Ships with zero seeded rows in this batch — see that
 * migration's own doc block for why.
 *
 * All of this row's real content (items, price, evidence, substitution
 * policy) lives on its `versions()`, never here — this row is only the
 * stable name/code a package keeps across every version.
 */
final class ServicePackage extends Model
{
    protected $table = 'service_packages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Every version, newest first — draft and published alike. ADMIN-FACING
     * (mirrors `App\Domain\Faq\Models\FaqArticle::relatedArticles()`'s own
     * "the un-prefixed relation sees everything" convention).
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ServicePackageVersion::class, 'service_package_id')->orderByDesc('version_number');
    }

    /**
     * The most recent version with `status = published` — what a public
     * quote-expansion caller (a later, out-of-scope-for-this-batch
     * consumer) would read from. `null` if this package has never been
     * published.
     */
    public function currentPublishedVersion(): ?ServicePackageVersion
    {
        return $this->versions()
            ->where('status', ServicePackageVersionStatus::PUBLISHED)
            ->orderByDesc('version_number')
            ->first();
    }

    /**
     * The one open draft version, if any — `Actions\DefineServicePackage`
     * and `Actions\ReviseServicePackageVersion` both enforce that a package
     * never has more than one draft at a time (see the latter's own doc
     * block).
     */
    public function draftVersion(): ?ServicePackageVersion
    {
        return $this->versions()->where('status', ServicePackageVersionStatus::DRAFT)->first();
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', $code)->first();
    }
}
