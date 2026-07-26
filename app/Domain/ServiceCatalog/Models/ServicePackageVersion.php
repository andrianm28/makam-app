<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use App\Domain\ServiceCatalog\Exceptions\PublishedServicePackageVersionIsImmutableException;
use App\Domain\ServiceCatalog\ServicePackageVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Eloquent model for `service_package_versions` — see the migration
 * (`2026_07_26_180200_create_service_package_versions_table.php`) for
 * schema reasoning.
 *
 * ---------------------------------------------------------------------------
 * THE AC2 GUARANTEE (version side) — read this before writing any query
 * against this model
 * ---------------------------------------------------------------------------
 * `requirements.md` AC2: "THE SYSTEM SHALL NOT allow modification of a
 * published package version." Once a row's `status` has EVER been
 * `published`, `booted()` below refuses to save it again for ANY reason —
 * including setting the exact same values, or being reached via `touch()`.
 * The one save that IS allowed is the single `draft` -> `published`
 * transition itself (`Actions\PublishServicePackageVersion`), because the
 * guard checks the ORIGINAL (pre-save) status, not the incoming one — a row
 * whose original status is still `draft` may always be saved.
 *
 * `deleting()` applies the identical rule: a published version cannot be
 * deleted either. This is intentionally STRICTER than AC2's literal text
 * (which says "modification," not "deletion") — `design.md`'s "never
 * mutate accepted quote contents" reasoning extends naturally to deletion,
 * which is the most destructive possible "modification," and this
 * codebase's `AGENTS.md` §Database rule ("do not rely on destructive
 * production `down()` migrations for rollback") already establishes a
 * general bias against destroying published/accepted state.
 *
 * Like `App\Domain\Faq\Models\FaqArticle`'s AC6 guarantee, this is NOT
 * structurally unbypassable — a raw `DB::table('service_package_versions')
 * ->update(...)` query would skip Eloquent events entirely. What actually
 * makes it hard to get wrong: this doc comment, the fact that
 * `Actions\PublishServicePackageVersion` and `Actions\
 * ReviseServicePackageVersion` are the ONLY documented ways this module
 * itself mutates a version's lifecycle, and
 * `tests/Feature/Domain/ServiceCatalog/ServicePackageVersionImmutabilityTest.php`,
 * which proves the guard fires for save AND delete against a real
 * published row.
 */
final class ServicePackageVersion extends Model
{
    protected $table = 'service_package_versions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'service_package_id',
        'version_number',
        'status',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $version): void {
            ServicePackageVersionStatus::assertKnown($version->status);

            if ($version->exists && $version->getOriginal('status') === ServicePackageVersionStatus::PUBLISHED) {
                throw PublishedServicePackageVersionIsImmutableException::forVersion($version->getKey());
            }
        });

        self::deleting(function (self $version): void {
            if ($version->status === ServicePackageVersionStatus::PUBLISHED) {
                throw PublishedServicePackageVersionIsImmutableException::forVersion($version->getKey());
            }
        });
    }

    /**
     * @return BelongsTo<ServicePackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    /**
     * This version's included/optional/excluded lines, in insertion order.
     *
     * @return HasMany<ServicePackageItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ServicePackageItem::class, 'service_package_version_id');
    }

    /**
     * This version's own price history, if a package-level price (as
     * opposed to a per-service price) is ever recorded against it — see
     * `Models\PriceVersion`'s own class-level doc block for why this is
     * `morphMany`, shared with `Models\ServiceDefinition`.
     *
     * @return MorphMany<PriceVersion, $this>
     */
    public function priceVersions(): MorphMany
    {
        return $this->morphMany(PriceVersion::class, 'priceable');
    }

    public function isDraft(): bool
    {
        return $this->status === ServicePackageVersionStatus::DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === ServicePackageVersionStatus::PUBLISHED;
    }
}
