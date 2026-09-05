<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Memorial;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\MemorialVisitCheckin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `memorial_visit_checkins` schema + model + relation —
 * `docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md` §4.1.
 */
final class MemorialVisitCheckInTest extends TestCase
{
    use RefreshDatabase;

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    private function profile(): MemorialProfile
    {
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemetery()->getKey()]);

        return app(CreateMemorialProfile::class)($grave, 'user:1', 'operator');
    }

    /**
     * The hard constraint from the assignment, enforced structurally: no
     * such column can ever be populated if it does not exist.
     */
    public function test_the_table_has_no_ip_device_or_geolocation_column(): void
    {
        $columns = Schema::getColumnListing('memorial_visit_checkins');

        foreach (['ip', 'ip_address', 'device', 'device_fingerprint', 'user_agent', 'latitude', 'longitude'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "memorial_visit_checkins must never carry a [{$forbidden}] column.");
        }
    }

    public function test_a_row_can_be_created_and_reached_through_the_profile_relation(): void
    {
        $profile = $this->profile();

        $checkIn = MemorialVisitCheckin::query()->create([
            'memorial_profile_id' => $profile->getKey(),
            'checked_in_at' => now(),
            'visitor_label' => 'Anak',
            'note' => 'Terima kasih sudah dirawat.',
            'moderation_state' => MemorialModerationState::DEFAULT,
        ]);

        $this->assertTrue($profile->visitCheckIns->contains($checkIn));
        $this->assertSame(MemorialModerationState::DEFAULT, $checkIn->fresh()->moderation_state);
        $this->assertNull(MemorialVisitCheckin::query()->create([
            'memorial_profile_id' => $profile->getKey(),
            'checked_in_at' => now(),
        ])->visitor_label, 'visitor_label and note must both be optional.');
    }
}
