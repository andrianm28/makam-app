<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Memorial;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\Actions\GrantMemorialEditor;
use App\Domain\Memorial\Actions\PublishMemorial;
use App\Domain\Memorial\Actions\SubmitMemorialContent;
use App\Domain\Memorial\Actions\UnpublishMemorial;
use App\Domain\Memorial\Exceptions\MemorialConsentMissingException;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialEditor;
use App\Domain\Memorial\Models\MemorialMedia;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Privacy-first memorial domain — `.kiro/specs/memorial-and-qr/
 * requirements.md` AC1 (private default, consent-gated editor grants)
 * and AC2 (all four privacy modes). See the plan's Task 3 brief for the
 * exact interface contract this file locks in.
 */
final class MemorialProfileTest extends TestCase
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

    public function test_create_defaults_to_private_and_records_audit_and_outbox(): void
    {
        $grave = $this->grave();

        $profile = app(CreateMemorialProfile::class)($grave, 'user:1', 'operator');

        $this->assertSame(MemorialPrivacyMode::PRIVATE->value, $profile->privacy_mode);
        $this->assertNull($profile->published_at);
        $this->assertDatabaseHas('memorial_profiles', [
            'grave_record_id' => $grave->getKey(),
            'privacy_mode' => 'private',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_PROFILE_CREATED]);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'memorial.profile_created.v1']);
    }

    public function test_explicit_privacy_mode_is_honoured(): void
    {
        $grave = $this->grave();

        $profile = app(CreateMemorialProfile::class)($grave, 'user:1', 'operator', MemorialPrivacyMode::PUBLIC->value);

        $this->assertSame(MemorialPrivacyMode::PUBLIC->value, $profile->privacy_mode);
    }

    public function test_unknown_privacy_mode_is_rejected(): void
    {
        $grave = $this->grave();

        $this->expectException(InvalidArgumentException::class);
        app(CreateMemorialProfile::class)($grave, 'user:1', 'operator', 'everyone_everywhere');
    }

    public function test_second_create_for_the_same_grave_returns_the_incumbent(): void
    {
        $grave = $this->grave();
        $first = app(CreateMemorialProfile::class)($grave, 'user:1', 'operator');
        $second = app(CreateMemorialProfile::class)($grave, 'user:2', 'operator');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, MemorialProfile::query()->count());
        $this->assertSame(
            1,
            \DB::table('audit_events')->where('action', MemorialAuditActions::MEMORIAL_PROFILE_CREATED)->count(),
            'An idempotent return is not a creation and must not re-audit.'
        );
    }

    public function test_profile_with_an_editor_cannot_be_deleted(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');
        app(GrantMemorialEditor::class)($profile, 'family:1', 'doc-ref-1', 'user:1', 'operator');

        $this->expectException(InvalidArgumentException::class);
        $profile->delete();
    }

    public function test_profile_with_content_cannot_be_deleted(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');
        app(SubmitMemorialContent::class)($profile, 'Tulisan kenangan', 'family:1', 'family');

        $this->expectException(InvalidArgumentException::class);
        $profile->delete();
    }

    public function test_profile_with_media_cannot_be_deleted(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');
        MemorialMedia::query()->create([
            'memorial_profile_id' => $profile->getKey(),
            'storage_ref' => $this->acceptedDocument()->getKey(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $profile->delete();
    }

    public function test_profile_with_a_qr_token_cannot_be_deleted(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');
        MemorialQrToken::issueFor($profile);

        $this->expectException(InvalidArgumentException::class);
        $profile->delete();
    }

    public function test_empty_profile_can_be_deleted(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');

        $profile->delete();

        $this->assertDatabaseMissing('memorial_profiles', ['id' => $profile->getKey()]);
    }

    public function test_editor_grant_without_consent_evidence_is_refused(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');

        $this->expectException(MemorialConsentMissingException::class);
        app(GrantMemorialEditor::class)($profile, 'family:1', '', 'user:1', 'operator');
    }

    public function test_editor_grant_with_consent_evidence_succeeds_and_is_audited(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');

        $editor = app(GrantMemorialEditor::class)($profile, 'family:1', 'consent-evidence-ref-1', 'user:1', 'operator');

        $this->assertSame('family:1', $editor->actor_id);
        $this->assertSame('consent-evidence-ref-1', $editor->consent_evidence_ref);
        $this->assertNotNull($editor->granted_at);
        $this->assertNull($editor->revoked_at);
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_EDITOR_GRANTED]);
    }

    public function test_revoked_editor_can_be_granted_again(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');
        $editor = app(GrantMemorialEditor::class)($profile, 'family:1', 'consent-evidence-ref-1', 'user:1', 'operator');

        $editor->revoke();
        $this->assertNotNull($editor->fresh()->revoked_at);

        $regranted = app(GrantMemorialEditor::class)($profile, 'family:1', 'consent-evidence-ref-2', 'user:1', 'operator');

        $this->assertNotSame($editor->getKey(), $regranted->getKey());
        $this->assertNull($regranted->revoked_at);
        $this->assertSame(
            2,
            MemorialEditor::query()->where('actor_id', 'family:1')->count(),
            'The partial unique index must release the active slot when the old row is revoked.'
        );
    }

    public function test_grant_for_an_already_active_editor_returns_the_incumbent(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');
        $first = app(GrantMemorialEditor::class)($profile, 'family:1', 'consent-evidence-ref-1', 'user:1', 'operator');
        $second = app(GrantMemorialEditor::class)($profile, 'family:1', 'consent-evidence-ref-1', 'user:1', 'operator');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, MemorialEditor::query()->count());
    }

    public function test_publish_records_published_at_audit_and_outbox(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');

        $published = app(PublishMemorial::class)($profile, 'user:1', 'operator');

        $this->assertNotNull($published->published_at);
        $this->assertNull($published->unpublished_at);
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_PUBLISHED]);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'memorial.published.v1']);
    }

    public function test_publish_when_already_published_is_an_idempotent_noop(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');
        app(PublishMemorial::class)($profile, 'user:1', 'operator');

        app(PublishMemorial::class)($profile, 'user:1', 'operator');

        $this->assertSame(
            1,
            \DB::table('outbox_events')->where('event_name', 'memorial.published.v1')->count(),
            'Re-publishing must not emit a second event.'
        );
    }

    public function test_unpublish_is_immediate_and_emits_the_pre_catalogued_event(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');
        app(PublishMemorial::class)($profile, 'user:1', 'operator');

        $unpublished = app(UnpublishMemorial::class)($profile, 'user:1', 'operator');

        $this->assertNull($unpublished->published_at);
        $this->assertNotNull($unpublished->unpublished_at);
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_UNPUBLISHED]);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'memorial.unpublished.v1']);
    }

    public function test_unpublish_when_not_published_is_an_idempotent_noop(): void
    {
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator');

        app(UnpublishMemorial::class)($profile, 'user:1', 'operator');

        $this->assertSame(
            0,
            \DB::table('outbox_events')->where('event_name', 'memorial.unpublished.v1')->count(),
            'Unpublishing an already-unpublished profile must not emit an event.'
        );
    }

    private function acceptedDocument(): Document
    {
        $document = Document::createQuarantined([
            'document_kind' => DocumentKind::ProductImage,
            'owner_type' => 'memorial_profile',
            'owner_id' => Str::uuid(),
            'original_filename' => 'foto.jpg',
            'storage_prefix' => 'quarantine',
            'storage_key' => Str::random(40),
            'size_bytes' => 1024,
            'mime_declared' => 'image/jpeg',
        ]);

        $document->transitionTo(DocumentState::Scanning);
        $document->promote();

        return $document;
    }
}
