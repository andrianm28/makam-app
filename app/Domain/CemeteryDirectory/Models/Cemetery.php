<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory\Models;

use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use Database\Factories\CemeteryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Eloquent model for `cemeteries` — see the migration
 * (`2026_07_26_190000_create_cemeteries_table.php`) for schema reasoning.
 * Owns the `CemeteryDirectory` module's documented responsibility
 * (`docs/architecture/overview.md` §5): "TPU/TPS identity, facilities,
 * media, location." Availability/booking/map/registry state lives in the
 * sibling `CemeteryCapability` module (`capabilityProfiles()`/`packages()`
 * below reach into it as ordinary Eloquent relations — a normal
 * cross-module read, not a table-ownership violation; this model's own
 * migration never touches `cemetery_capability_profiles` or
 * `cemetery_packages`).
 *
 * Known fact, stated because the two relations below no longer enumerate
 * everything that depends on this table: `GraveRegistry` and `Booking` have
 * both since attached foreign keys to `cemeteries` without an owning
 * relation here — `grave_records.cemetery_id` (RESTRICT on delete,
 * `2026_08_08_100000`) and `booking_drafts.cemetery_id` (SET NULL,
 * `2026_08_08_130000`). Those are deliberately NOT modelled as relations on
 * this model: the dependency points inward from modules this one does not
 * own, and adding `hasMany` accessors here would invert that ownership.
 * Recorded so nobody reads `capabilityProfiles()`/`packages()` as the
 * complete inbound-dependency picture — it is not, and
 * `2026_07_26_190300`'s `down()` carries the full four-FK table.
 *
 * `id` is a UUID, not an auto-increment integer — the one deliberate
 * deviation from `App\Domain\Faq\Models\FaqArticle`'s otherwise-matched
 * conventions in this batch. `docs/contracts/openapi.yaml`'s `CemeteryId`
 * parameter and `CemeterySummary.id` schema both fix `format: uuid` for
 * this exact resource, and every other domain-facing path id in that same
 * contract (`DraftId`, `OrderId`, `CaseId`, `PlotId`, `ReservationId`) does
 * the same — a real, already-committed external contract this batch
 * checked directly, not a guess. `2026_07_26_130000_create_scope_
 * assignments_table.php`'s own doc block explicitly left `entity_id` as a
 * bare string specifically because "no real domain model exists yet whose
 * primary key type this batch could commit to" — that gap is now closed
 * for `cemetery`, in the direction the contract already committed to.
 */
final class Cemetery extends Model
{
    /**
     * `HasFactory` added by the P3 plot-inventory lane (Task 1 ride-along,
     * not a review finding — the same pattern `Renewal` documents for its
     * own addition): `Cemetery::factory()` is needed by the P3
     * plot-inventory tests, and no `CemeteryFactory` existed before
     * `database/factories/CemeteryFactory.php` (see that file's doc block
     * for the pre-existing "pick the first seeded cemetery" workaround in
     * `GraveRecordFactory`). `HasFactory`'s default resolver maps this
     * model to `Database\Factories\CemeteryFactory` — the convention
     * `GraveRecord` follows.
     *
     * @use HasFactory<CemeteryFactory>
     */
    use HasFactory, HasUuids;

    protected $table = 'cemeteries';

    /**
     * `HasFactory`'s default resolver strips a leading `App\Models\` and
     * nothing else, so it cannot find a factory for a model namespaced
     * under `App\Domain\...\Models` — the same explicit override
     * `GraveRecord` documents.
     */
    protected static function newFactory(): CemeteryFactory
    {
        return CemeteryFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'type',
        'publication_status',
        'name',
        'slug',
        'city',
        'address',
        'latitude',
        'longitude',
        'google_maps_url',
        'primary_photo_path',
        'facilities',
        'price_min',
        'price_max',
        'price_currency',
        'price_source',
        'price_effective_at',
        'operator_name',
        'published_at',
        'unpublished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'facilities' => 'array',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'price_effective_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'unpublished_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $cemetery): void {
            CemeteryType::assertKnown($cemetery->type);
            CemeteryPublicationStatus::assertKnown($cemetery->publication_status);

            // City codes are table-backed (`launch_cities`, admin-
            // extendable) with the canonical `LaunchCityCode::KNOWN_CODES`
            // as fallback — `LaunchCityQuery::isKnown` is the single
            // definition.
            if (! LaunchCityQuery::isKnown($cemetery->city)) {
                throw new InvalidArgumentException("Unknown launch city code [{$cemetery->city}].");
            }
        });
    }

    /**
     * Full capability-profile history for this cemetery, newest first.
     * Owned by `CemeteryCapability` — see that module's `CemeteryCapability
     * Profile` model for the append-only versioning shape.
     *
     * @return HasMany<CemeteryCapabilityProfile, $this>
     */
    public function capabilityProfiles(): HasMany
    {
        return $this->hasMany(CemeteryCapabilityProfile::class, 'cemetery_id')
            ->orderByDesc('version_number');
    }

    /**
     * Package/class-level availability rows — requirements.md AC6 ("THE
     * SYSTEM SHALL present Makam Tumpang availability explicitly at the
     * location/package/class level"). Owned by `CemeteryCapability`.
     *
     * @return HasMany<CemeteryPackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(CemeteryPackage::class, 'cemetery_id');
    }

    /**
     * requirements.md AC2's base guarantee: every public directory read
     * must start here (or a helper composing it), never a bare
     * `Cemetery::query()`.
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('publication_status', CemeteryPublicationStatus::PUBLISHED);
    }

    public function scopeInCity(Builder $query, string $cityCode): void
    {
        $query->where('city', $cityCode);
    }

    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    public function isPublished(): bool
    {
        return $this->publication_status === CemeteryPublicationStatus::PUBLISHED;
    }

    /**
     * requirements.md AC11: "provide an external navigation link from
     * coordinates/address via Google Maps. WHEN the map provider fails THE
     * SYSTEM SHALL NOT block viewing of the textual address." An explicit
     * `google_maps_url` (operator-supplied) always wins; otherwise this
     * derives a search-query URL from coordinates when present. Returns
     * `null` when neither is available — callers MUST still render
     * `address` in that case, never block on this method's result. This is
     * a small, pure derivation living on the model, not a public "map
     * provider" integration — nothing here calls out to Google or renders
     * UI (both out of this batch's S4-T1 scope).
     */
    public function googleMapsUrl(): ?string
    {
        if ($this->google_maps_url !== null) {
            return $this->google_maps_url;
        }

        if ($this->latitude !== null && $this->longitude !== null) {
            return sprintf(
                'https://www.google.com/maps/search/?api=1&query=%s,%s',
                $this->latitude,
                $this->longitude,
            );
        }

        return null;
    }
}
