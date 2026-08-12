<?php

declare(strict_types=1);

namespace App\Domain\Booking\Models;

use App\Domain\Booking\BookingServiceType;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `booking_drafts` — see the migration
 * (`2026_08_08_130000_create_booking_drafts_table.php`) for schema
 * reasoning. Owned by `App\Domain\Booking`
 * (`booking-and-order-orchestration`'s module). Never constructed with
 * `BookingDraft::create()`/`::update()` from outside `app/Domain/Booking/
 * Actions/` — see `App\Domain\Booking\Actions\StartBookingDraft` and
 * `SaveBookingDraftStep`, this module's only write path
 * (`app/Domain/README.md`: domain logic lives here, not in Livewire
 * components).
 *
 * `id` is a UUID (`HasUuids`) — see the migration's own doc block for why
 * it doubles as the anonymous resume token.
 */
final class BookingDraft extends Model
{
    use HasUuids;

    protected $table = 'booking_drafts';

    /**
     * Mirrors the DB defaults in `2026_08_08_130000_create_booking_drafts_
     * table.php` in the model so a fresh draft carries them in memory too —
     * Eloquent does not re-read DB defaults into the model after insert, so
     * without these a `BookingDraft::create([])` would report the four
     * defaulted columns as `null` until refreshed. Same values as the
     * migration's own `default(1)` / `default('[]')`. The JSON columns are
     * stored pre-encoded because values pre-set in `$attributes` never pass
     * through `setAttribute` (so the `array` cast would not serialize them);
     * the cast still decodes `'[]'` back to `[]` on read.
     *
     * @var array<string, int|string>
     */
    protected $attributes = [
        'current_step' => 1,
        'completed_steps' => '[]',
        'selected_services' => '[]',
        'version' => 1,
    ];

    /**
     * `id` is deliberately NOT fillable: `HasUuids` generates it on insert
     * whenever it is absent, and no caller in this codebase passes one
     * (verified across `app/` and `tests/`). Leaving it mass-assignable
     * would let a caller choose a draft's own resume token — the one
     * attribute on this model that must stay unguessable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'current_step',
        'completed_steps',
        'city_code',
        'cemetery_id',
        'cemetery_package_id',
        'service_type',
        'selected_services',
        'customer_full_name',
        'customer_mobile',
        'customer_email',
        'customer_address',
        'customer_relationship',
        'customer_contact_channel',
        'privacy_notice_accepted_at',
        'deceased_full_name',
        'deceased_date_of_birth',
        'deceased_date_of_death',
        'deceased_relationship',
        'deceased_gender',
        'document_ktp_path',
        'document_kk_path',
        'document_death_certificate_path',
        'payment_method',
        'payment_reference',
        'version',
        'last_idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'completed_steps' => 'array',
            'selected_services' => 'array',
            'version' => 'integer',
            'deceased_date_of_birth' => 'date',
            'deceased_date_of_death' => 'date',
            'privacy_notice_accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $draft): void {
            if ($draft->city_code !== null) {
                LaunchCityCode::assertKnown($draft->city_code);
            }

            if ($draft->service_type !== null) {
                BookingServiceType::assertKnown($draft->service_type);
            }
        });
    }

    /**
     * @return BelongsTo<Cemetery, $this>
     */
    public function cemetery(): BelongsTo
    {
        return $this->belongsTo(Cemetery::class, 'cemetery_id');
    }

    /**
     * @return BelongsTo<CemeteryPackage, $this>
     */
    public function cemeteryPackage(): BelongsTo
    {
        return $this->belongsTo(CemeteryPackage::class, 'cemetery_package_id');
    }
}
