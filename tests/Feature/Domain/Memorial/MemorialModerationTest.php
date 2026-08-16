<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Memorial;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\Actions\ModerateMemorialContent;
use App\Domain\Memorial\Actions\ReportMemorialContent;
use App\Domain\Memorial\Actions\SubmitMemorialContent;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\Models\AbuseReport;
use App\Domain\Memorial\Models\MemorialMedia;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\ModerationCase;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Moderation and reporting of family-authored memorial content/media —
 * `.kiro/specs/memorial-and-qr/requirements.md` AC6 (moderate user-
 * generated messages/media, make them reportable) and the media
 * fail-closed rule (usable only when the vault document is accepted).
 */
final class MemorialModerationTest extends TestCase
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

    private function grave(): GraveRecord
    {
        return GraveRecord::factory()->create(['cemetery_id' => $this->cemetery()->getKey()]);
    }

    private function profile(): MemorialProfile
    {
        return app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');
    }

    public function test_submitted_content_starts_pending(): void
    {
        $profile = $this->profile();

        $content = app(SubmitMemorialContent::class)($profile, 'Kenangan indah', 'family:1', 'family');

        $this->assertSame(MemorialModerationState::PENDING->value, $content->moderation_state);
        $this->assertSame('Kenangan indah', $content->body);
        $this->assertDatabaseHas('memorial_contents', [
            'memorial_profile_id' => $profile->getKey(),
            'moderation_state' => 'pending',
        ]);
    }

    public function test_moderation_approves_and_emits_audit_and_outbox(): void
    {
        $profile = $this->profile();
        $content = app(SubmitMemorialContent::class)($profile, 'Kenangan indah', 'family:1', 'family');

        $moderated = app(ModerateMemorialContent::class)($content, MemorialModerationState::APPROVED, 'moderator:1', 'moderator');

        $this->assertSame(MemorialModerationState::APPROVED->value, $moderated->moderation_state);
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_CONTENT_MODERATED]);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'memorial.content_moderated.v1']);
    }

    public function test_moderation_can_reject_or_hide_content(): void
    {
        $profile = $this->profile();
        $rejected = app(SubmitMemorialContent::class)($profile, 'Tolak ini', 'family:1', 'family');
        $hidden = app(SubmitMemorialContent::class)($profile, 'Sembunyikan ini', 'family:1', 'family');

        $this->assertSame(
            MemorialModerationState::REJECTED->value,
            app(ModerateMemorialContent::class)($rejected, MemorialModerationState::REJECTED, 'moderator:1', 'moderator')->moderation_state
        );
        $this->assertSame(
            MemorialModerationState::HIDDEN->value,
            app(ModerateMemorialContent::class)($hidden, MemorialModerationState::HIDDEN, 'moderator:1', 'moderator')->moderation_state
        );
    }

    public function test_moderating_back_to_pending_is_refused(): void
    {
        $profile = $this->profile();
        $content = app(SubmitMemorialContent::class)($profile, 'Kenangan indah', 'family:1', 'family');

        $this->expectException(InvalidArgumentException::class);
        app(ModerateMemorialContent::class)($content, MemorialModerationState::PENDING, 'moderator:1', 'moderator');
    }

    public function test_approved_content_can_be_moderated_again(): void
    {
        $profile = $this->profile();
        $content = app(SubmitMemorialContent::class)($profile, 'Kenangan indah', 'family:1', 'family');
        app(ModerateMemorialContent::class)($content, MemorialModerationState::APPROVED, 'moderator:1', 'moderator');

        $hidden = app(ModerateMemorialContent::class)($content, MemorialModerationState::HIDDEN, 'moderator:1', 'moderator');

        $this->assertSame(MemorialModerationState::HIDDEN->value, $hidden->moderation_state);
    }

    public function test_reporting_content_creates_an_open_case_with_an_abuse_report(): void
    {
        $profile = $this->profile();
        $content = app(SubmitMemorialContent::class)($profile, 'Mencurigakan', 'family:1', 'family');

        $case = app(ReportMemorialContent::class)(
            $profile,
            'memorial_content',
            $content->getKey(),
            'visitor:9',
            'guest',
            'Konten tidak pantas',
        );

        $this->assertSame(ModerationCase::STATUS_OPEN, $case->status);
        $this->assertSame('memorial_content', $case->reported_content_type);
        $this->assertSame((string) $content->getKey(), $case->reported_content_id);

        $report = $case->abuseReports()->first();
        $this->assertInstanceOf(AbuseReport::class, $report);
        $this->assertSame('visitor:9', $report->reporter_ref);
        $this->assertSame('Konten tidak pantas', $report->reason);
    }

    public function test_report_without_a_reason_is_refused(): void
    {
        $profile = $this->profile();
        $content = app(SubmitMemorialContent::class)($profile, 'Mencurigakan', 'family:1', 'family');

        $this->expectException(InvalidArgumentException::class);
        app(ReportMemorialContent::class)(
            $profile,
            'memorial_content',
            $content->getKey(),
            'visitor:9',
            'guest',
            '',
        );
    }

    public function test_media_requires_a_document_that_exists_and_is_accepted(): void
    {
        $profile = $this->profile();

        try {
            MemorialMedia::query()->create([
                'memorial_profile_id' => $profile->getKey(),
                'storage_ref' => (string) Str::uuid(),
            ]);
            $this->fail('A media row referencing a non-existent vault document must be refused.');
        } catch (InvalidArgumentException) {
            // expected — unknown document
        }

        $quarantined = Document::createQuarantined([
            'document_kind' => DocumentKind::ProductImage,
            'owner_type' => 'memorial_profile',
            'owner_id' => $profile->getKey(),
            'original_filename' => 'foto.jpg',
            'storage_prefix' => 'quarantine',
            'storage_key' => Str::random(40),
            'size_bytes' => 1024,
            'mime_declared' => 'image/jpeg',
        ]);

        try {
            MemorialMedia::query()->create([
                'memorial_profile_id' => $profile->getKey(),
                'storage_ref' => $quarantined->getKey(),
            ]);
            $this->fail('A media row referencing a non-accepted document must be refused (fail-closed).');
        } catch (InvalidArgumentException) {
            // expected — quarantined document is not usable
        }

        $accepted = $this->accept($quarantined);

        $media = MemorialMedia::query()->create([
            'memorial_profile_id' => $profile->getKey(),
            'storage_ref' => $accepted->getKey(),
        ]);

        $this->assertSame(MemorialModerationState::PENDING->value, $media->moderation_state);
        $this->assertDatabaseHas('memorial_media', [
            'memorial_profile_id' => $profile->getKey(),
            'storage_ref' => $accepted->getKey(),
        ]);
    }

    private function accept(Document $document): Document
    {
        $document->transitionTo(DocumentState::Scanning);
        $document->promote();

        return $document;
    }
}
