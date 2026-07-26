<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability\Models;

use App\Domain\CemeteryCapability\AvailabilityMode;
use App\Domain\CemeteryCapability\BookingMode;
use App\Domain\CemeteryCapability\CertificateMode;
use App\Domain\CemeteryCapability\MapMode;
use App\Domain\CemeteryCapability\RegistryMode;
use App\Domain\CemeteryCapability\VisitationMode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `cemetery_capability_profiles` — see the migration
 * (`2026_07_26_190100_create_cemetery_capability_profiles_table.php`) for
 * schema reasoning. Owns the `CemeteryCapability` module's documented
 * responsibility (`docs/architecture/overview.md` §5): "Availability/
 * booking/map/registry modes and activation evidence."
 *
 * ---------------------------------------------------------------------------
 * Append-only, versioned — mirrors `App\Domain\ServiceCatalog\Models\
 * PriceVersion` / `App\Domain\Faq\Models\FaqArticleVersion` exactly
 * ---------------------------------------------------------------------------
 * `design.md`'s Data section names this table's own columns:
 * `cemetery_capability_profiles(version, modes, source, effective_at,
 * evidence)`. A row is never updated after insert except to stamp
 * `superseded_at` when a newer version for the same cemetery is recorded.
 * `superseded_at IS NULL` means "this is the current profile for this
 * cemetery" — `scopeCurrent()` below is the one place that check lives.
 * `$timestamps = false`: `effective_at` is the explicit, server-set "when
 * this version became effective" column; there is no separate
 * `created_at`/`updated_at` pair, the same choice `PriceVersion`/
 * `FaqArticleVersion` make for the identical reason.
 *
 * ---------------------------------------------------------------------------
 * AC4 — "use safe defaults" when a profile is missing
 * ---------------------------------------------------------------------------
 * requirements.md AC4: "THE SYSTEM SHALL maintain an explicit versioned
 * capability profile for every cemetery. WHEN a capability profile is
 * missing THE SYSTEM SHALL use safe defaults." This batch seeds a real
 * `version_number = 1` profile for every seeded cemetery (so "missing" is
 * not the normal case), but the fallback path itself — for a cemetery that
 * somehow has none — is `App\Domain\CemeteryCapability\Actions\
 * ResolveCemeteryCapabilityProfile`, which returns an UNSAVED instance
 * built from `safeDefaults()` below rather than silently persisting one.
 * `safeDefaults()` is also this migration's own column-default source
 * (`AvailabilityMode::DEFAULT` etc.), so the "safe default" is defined
 * exactly once and read from both places.
 *
 * ---------------------------------------------------------------------------
 * One evidence trail per VERSION, not one per individual mode — a
 * judgement call, documented explicitly
 * ---------------------------------------------------------------------------
 * This batch's brief described each of the six modes as carrying "an
 * activation-evidence trail: owner, evidence, effective date, rollback
 * plan." `design.md`'s own Data line lists `source`/`effective_at`/
 * `evidence` as flat columns of the WHOLE table, not six times over, and
 * `overview.md` §6 presents the six modes as one combined profile, not six
 * independently-activated features. Read together, this batch takes the
 * combined-profile reading: ONE evidence trail justifies a given VERSION's
 * whole six-mode combination, not six separate trails per mode. Six
 * independent trails (24 extra columns) is not supported by either cited
 * document's actual column list and would be scope invention, not
 * implementation — flagged here explicitly as a real ambiguity this batch
 * resolved rather than silently picked.
 */
final class CemeteryCapabilityProfile extends Model
{
    protected $table = 'cemetery_capability_profiles';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cemetery_id',
        'version_number',
        'availability_mode',
        'booking_mode',
        'map_mode',
        'registry_mode',
        'certificate_mode',
        'visitation_mode',
        'source',
        'owner',
        'evidence',
        'rollback_plan',
        'effective_at',
        'superseded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'effective_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $profile): void {
            AvailabilityMode::assertKnown($profile->availability_mode);
            BookingMode::assertKnown($profile->booking_mode);
            MapMode::assertKnown($profile->map_mode);
            RegistryMode::assertKnown($profile->registry_mode);
            CertificateMode::assertKnown($profile->certificate_mode);
            VisitationMode::assertKnown($profile->visitation_mode);
        });
    }

    public function cemetery(): BelongsTo
    {
        return $this->belongsTo(Cemetery::class, 'cemetery_id');
    }

    /**
     * THE base guarantee for "the current profile" — every read that wants
     * "the" (singular) capability profile for a cemetery must compose this,
     * not assume the highest `version_number` or the most recently
     * inserted row.
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('superseded_at');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * requirements.md AC4's safe defaults — `INDICATIVE` / `REQUEST_
     * CONFIRMATION` / `LOCATION_ONLY` / `NONE` / `NONE` / `NONE`, exactly
     * as `overview.md` §6 and this batch's own brief state them. The one
     * place these six values are written; the migration's column defaults
     * and `Actions\ResolveCemeteryCapabilityProfile`'s fallback both read
     * from here rather than restating the six strings.
     *
     * @return array{availability_mode: string, booking_mode: string, map_mode: string, registry_mode: string, certificate_mode: string, visitation_mode: string}
     */
    public static function safeDefaults(): array
    {
        return [
            'availability_mode' => AvailabilityMode::DEFAULT,
            'booking_mode' => BookingMode::DEFAULT,
            'map_mode' => MapMode::DEFAULT,
            'registry_mode' => RegistryMode::DEFAULT,
            'certificate_mode' => CertificateMode::DEFAULT,
            'visitation_mode' => VisitationMode::DEFAULT,
        ];
    }

    /**
     * True when every one of the six modes on this profile equals the safe
     * default — used by tests proving AC4's default resolution rather than
     * comparing six columns by hand at every call site.
     */
    public function matchesSafeDefaults(): bool
    {
        foreach (self::safeDefaults() as $column => $default) {
            if ($this->{$column} !== $default) {
                return false;
            }
        }

        return true;
    }
}
