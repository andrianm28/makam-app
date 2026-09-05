<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Memorial;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\Actions\LogMemorialVisitCheckIn;
use App\Domain\Memorial\Actions\PublishMemorial;
use App\Domain\Memorial\Exceptions\MemorialNotVisibleException;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Domain\Memorial\Models\MemorialVisitCheckin;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\Models\FeatureGate;
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

    private function openMemorialGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-MEM-01')->update(['state' => 'open']);
        app(FeatureGateResolver::class)->forget();
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

    public function test_a_successful_check_in_creates_a_row_and_one_audit_event(): void
    {
        $this->openMemorialGate();
        $profile = app(PublishMemorial::class)($this->profile(), 'user:1', 'operator');
        $profile->forceFill(['privacy_mode' => MemorialPrivacyMode::PUBLIC->value])->save();
        $token = MemorialQrToken::issueFor($profile);

        $checkIn = app(LogMemorialVisitCheckIn::class)(
            $token->token,
            null,
            'Anak',
            'Terima kasih sudah dirawat.',
            'visit_session:test-session',
            'guest',
        );

        $this->assertSame($profile->getKey(), $checkIn->memorial_profile_id);
        $this->assertSame('Anak', $checkIn->visitor_label);
        $this->assertSame('Terima kasih sudah dirawat.', $checkIn->note);
        $this->assertNotNull($checkIn->checked_in_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => MemorialAuditActions::MEMORIAL_VISIT_CHECKED_IN,
            'subject_id' => $checkIn->getKey(),
        ]);
    }

    public function test_closed_gate_and_revoked_token_deny_the_check_in_with_the_same_exception_as_the_direct_resolve(): void
    {
        $profile = app(PublishMemorial::class)($this->profile(), 'user:1', 'operator');
        $profile->forceFill(['privacy_mode' => MemorialPrivacyMode::PUBLIC->value])->save();
        $token = MemorialQrToken::issueFor($profile);

        try {
            app(LogMemorialVisitCheckIn::class)($token->token, null, null, null, 'visit_session:test', 'guest');
            $this->fail('A closed gate must deny the check-in path.');
        } catch (MemorialNotVisibleException) {
            // expected — the SAME class ResolveMemorialQr throws directly.
        }

        $this->openMemorialGate();
        $token->revoke();

        try {
            app(LogMemorialVisitCheckIn::class)($token->token, null, null, null, 'visit_session:test', 'guest');
            $this->fail('A revoked token must deny the check-in path.');
        } catch (MemorialNotVisibleException) {
            // expected — same class again, no second oracle.
        }

        $this->assertDatabaseMissing('memorial_visit_checkins', ['memorial_profile_id' => $profile->getKey()]);
        $this->assertDatabaseMissing('audit_events', ['action' => MemorialAuditActions::MEMORIAL_VISIT_CHECKED_IN]);
    }
}
