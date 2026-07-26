<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory\Models;

use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    use HasUuids;

    protected $table = 'cemeteries';

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
            LaunchCityCode::assertKnown($cemetery->city);
        });
    }

    /**
     * Full capability-profile history for this cemetery, newest first.
     * Owned by `CemeteryCapability` — see that module's `CemeteryCapability
     * Profile` model for the append-only versioning shape.
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
