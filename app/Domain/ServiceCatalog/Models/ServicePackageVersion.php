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
 * `published`, `booted()` below refuses to `save()` it again for any
 * reason — including setting the exact same values, or being reached via
 * `touch()`. The one save that IS allowed is the single `draft` ->
 * `published` transition itself (`Actions\PublishServicePackageVersion`),
 * because the guard checks the ORIGINAL (pre-save) status, not the incoming
 * one — a row whose original status is still `draft` may always be saved.
 * `booted()` also refuses to INSERT a row that is already `published`, so a
 * frozen version can only ever come into existence by being published, which
 * is the only path that refuses to freeze an empty one.
 *
 * `deleting()` applies the identical rule, on the same ORIGINAL status: a
 * published version cannot be deleted either. This is intentionally STRICTER
 * than AC2's literal text (which says "modification," not "deletion") —
 * `design.md`'s "never mutate accepted quote contents" reasoning extends
 * naturally to deletion, which is the most destructive possible
 * "modification," and this codebase's `AGENTS.md` §Database rule ("do not
 * rely on destructive production `down()` migrations for rollback") already
 * establishes a general bias against destroying published/accepted state.
 *
 * ---------------------------------------------------------------------------
 * EXACTLY what the guard does and does not cover — corrected 09 Aug 2026
 * (ServiceCatalog Superpowers retrofit, F6)
 * ---------------------------------------------------------------------------
 * This block used to say the guard "refuses to save it again for ANY
 * reason" and disclose exactly one bypass class (a raw
 * `DB::table('service_package_versions')->update(...)`). Both were wrong:
 * "ANY reason" overstated a `saving`-hook guard, and the disclosed list was
 * incomplete. The real boundary:
 *
 * COVERED (the hook fires, the write is refused):
 * - `$version->save()`, `$version->forceFill([...])->save()`,
 *   `$version->update([...])`, `touch()`, and a no-op save — `saving` fires
 *   on all of them, dirty or not.
 * - `$version->delete()`, including after an in-memory `status` downgrade
 *   (the `deleting` guard reads `getOriginal('status')`, so the downgrade
 *   changes nothing).
 * - `ServicePackageVersion::create([... 'status' => 'published'])`.
 *
 * NOT COVERED (no Eloquent model event fires at all):
 * - `$version->increment(...)` / `decrement(...)` — these fire only
 *   `updating`/`updated`, never `saving`, so
 *   `$publishedVersion->increment('version_number')` writes unguarded.
 * - Builder-level mass writes:
 *   `ServicePackageVersion::query()->where(...)->update([...])` or
 *   `->delete()`, and `$package->versions()->delete()`.
 * - `DB::table('service_package_versions')->update(...)` and any raw SQL.
 * - `Model::insert(...)`, which never instantiates a model.
 * - `withoutEvents(fn () => ...)`, which suppresses the hooks by design.
 * - A DATABASE-LEVEL CASCADE. `service_package_items`,
 *   `substitution_policies` and `evidence_requirements` all cascade from
 *   their parents, and a cascade fires no Eloquent event whatsoever.
 *
 * What the 09 Aug 2026 fix wave CLOSED, all at the Eloquent layer and with
 * no migration: the in-memory-downgrade delete (C-1), re-pointing an item off
 * a published version (C-2), a direct published INSERT (C-3),
 * `$package->delete()` cascading through published versions (C-4a), and the
 * complete absence of any guard on `SubstitutionPolicy` /
 * `EvidenceRequirement` (C-5).
 *
 * What remains LEDGERED as a human ruling, because closing it means a
 * migration against tables deployed to `dev.makam.co.id`: the
 * `cascadeOnDelete()` FK itself (F4b), CHECK constraints on the four
 * closed-list string columns (F11), and the missing partial unique index
 * behind `price_versions`' one-current-row invariant (F12). Everything in
 * the NOT COVERED list above is inside those three, or inside the
 * deliberate `withoutEvents()` escape hatch.
 *
 * Beyond the hooks, what makes this hard to get wrong: this doc comment, the
 * fact that `Actions\PublishServicePackageVersion` and
 * `Actions\ReviseServicePackageVersion` are the ONLY documented ways this
 * module itself mutates a version's lifecycle, and
 * `tests/Feature/Domain/ServiceCatalog/ServicePackageVersionImmutabilityTest.php`,
 * which proves the guard fires for save, insert AND delete against a real
 * published row — and, since 09 Aug 2026, pins the uncovered builder-level
 * bypasses as characterization tests so a future database-level guard cannot
 * land silently.
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

            // INSERT: a row may only ever be born `draft`. Without this,
            // `status` being fillable means
            // `ServicePackageVersion::create([... 'status' => 'published'])`
            // mints a frozen, zero-item version in one line and never meets
            // `Actions\PublishServicePackageVersion`'s zero-item refusal.
            if (! $version->exists && $version->status === ServicePackageVersionStatus::PUBLISHED) {
                throw PublishedServicePackageVersionIsImmutableException::forDirectPublishedInsert();
            }

            if ($version->exists && $version->getOriginal('status') === ServicePackageVersionStatus::PUBLISHED) {
                throw PublishedServicePackageVersionIsImmutableException::forVersion($version->getKey());
            }
        });

        self::deleting(function (self $version): void {
            // `getOriginal(...)`, never the live attribute — matching
            // `saving()` above. Reading `$version->status` here let an
            // in-memory downgrade (`$v->status = 'draft'; $v->delete();`)
            // destroy a row that is still `published` in the database.
            if ($version->getOriginal('status') === ServicePackageVersionStatus::PUBLISHED) {
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
