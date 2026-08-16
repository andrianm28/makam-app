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
use App\Domain\Memorial\Actions\ModerateMemorialContent;
use App\Domain\Memorial\Actions\PublishMemorial;
use App\Domain\Memorial\Actions\ResolveMemorialQr;
use App\Domain\Memorial\Actions\RotateMemorialQrToken;
use App\Domain\Memorial\Actions\SubmitMemorialContent;
use App\Domain\Memorial\Exceptions\MemorialNotVisibleException;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\MemorialPublicProjection;
use App\Domain\Memorial\Models\MemorialMedia;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\IdentityAccess\ActorContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QR token opacity and the gate-checked resolve —
 * `.kiro/specs/memorial-and-qr/requirements.md` AC4 (opaque, revocable,
 * never-derived token), AC5 (uniform not-visible response), and the
 * privacy matrix of AC1/AC2.
 */
final class MemorialQrTest extends TestCase
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

    private function profile(string $privacyMode = MemorialPrivacyMode::DEFAULT): MemorialProfile
    {
        return app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator', $privacyMode);
    }

    private function openMemorialGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-MEM-01')->update(['state' => 'open']);
        app(FeatureGateResolver::class)->forget();
    }

    private function actor(?string $identityReference): ActorContext
    {
        return new ActorContext(identityReference: $identityReference);
    }

    public function test_tokens_are_random_and_never_derived_from_any_identifier(): void
    {
        $grave = $this->grave();
        $profile = app(CreateMemorialProfile::class)($grave, 'user:1', 'operator');

        $first = MemorialQrToken::issueFor($profile);
        $first->revoke();
        $second = MemorialQrToken::issueFor($profile);
        $second->revoke();
        $third = MemorialQrToken::issueFor($profile);

        $tokens = [$first->token, $second->token, $third->token];

        $this->assertSame(48, strlen($first->token));
        $this->assertSame(3, count(array_unique($tokens)), 'Two generated tokens must never coincide.');
        $this->assertNotContains((string) $profile->getKey(), $tokens, 'A token must never be derived from the profile id.');
        $this->assertNotContains((string) $grave->getKey(), $tokens, 'A token must never be derived from the grave record id.');
        $this->assertNotSame($first->token, $profile->getKey());
        $this->assertNotSame($first->token, $grave->getKey());
    }

    public function test_rotate_revokes_the_old_token_and_issues_a_new_active_one(): void
    {
        $profile = $this->profile();
        $original = MemorialQrToken::issueFor($profile);

        $replacement = app(RotateMemorialQrToken::class)($profile, 'user:1', 'operator');

        $this->assertNotSame($original->getKey(), $replacement->getKey());
        $this->assertNotSame($original->token, $replacement->token);
        $this->assertNotNull($original->fresh()->revoked_at);
        $this->assertNotNull($original->fresh()->rotated_at);
        $this->assertNull($replacement->revoked_at);
        $this->assertSame($replacement->getKey(), MemorialQrToken::activeFor($profile)?->getKey());
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_QR_ROTATED]);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'memorial.qr_token_rotated.v1']);
    }

    public function test_second_active_token_for_the_same_profile_is_rejected_by_the_partial_unique_index(): void
    {
        $profile = $this->profile();
        MemorialQrToken::issueFor($profile);

        $this->expectException(QueryException::class);
        MemorialQrToken::issueFor($profile);
    }

    public function test_closed_gate_throws_the_uniform_not_visible_exception_before_any_lookup(): void
    {
        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        $token = MemorialQrToken::issueFor($profile);

        $this->expectException(MemorialNotVisibleException::class);
        app(ResolveMemorialQr::class)($token->token, $this->actor('user:1'));
    }

    public function test_unknown_token_throws_the_same_uniform_exception_as_gate_closed(): void
    {
        $this->openMemorialGate();

        $this->expectException(MemorialNotVisibleException::class);
        app(ResolveMemorialQr::class)(Str::random(48), $this->actor('user:1'));
    }

    public function test_private_profile_resolves_with_the_same_uniform_exception(): void
    {
        $this->openMemorialGate();
        $profile = $this->profile(MemorialPrivacyMode::PRIVATE->value);
        $token = MemorialQrToken::issueFor($profile);

        try {
            app(ResolveMemorialQr::class)($token->token, $this->actor('user:9'));
            $this->fail('A private profile must never resolve for a non-editor actor.');
        } catch (MemorialNotVisibleException $exception) {
            $this->assertStringContainsString('privacy', $exception->getMessage());
        }
    }

    /**
     * AC5's uniformity: gate-closed, unknown token, and privacy-denied are
     * the SAME exception class — nothing in the error surface reveals
     * which case applied.
     */
    public function test_closed_unknown_and_privacy_denials_are_the_same_exception_class(): void
    {
        $profile = $this->profile(MemorialPrivacyMode::PRIVATE->value);
        $token = MemorialQrToken::issueFor($profile);

        $denials = [];

        try {
            app(ResolveMemorialQr::class)($token->token, $this->actor('user:1'));
        } catch (MemorialNotVisibleException $denial) {
            $denials[] = $denial;
        }

        $this->openMemorialGate();

        try {
            app(ResolveMemorialQr::class)($token->token, $this->actor('user:1'));
        } catch (MemorialNotVisibleException $denial) {
            $denials[] = $denial;
        }

        try {
            app(ResolveMemorialQr::class)(Str::random(48), $this->actor('user:1'));
        } catch (MemorialNotVisibleException $denial) {
            $denials[] = $denial;
        }

        $this->assertCount(3, $denials, 'Gate-closed, privacy-denied, and unknown-token must each throw the uniform exception.');
    }

    /**
     * The full privacy matrix: mode × (guest / non-editor actor / active
     * editor actor), always with `hasToken: true` because every QR
     * resolver holds the token.
     */
    public function test_privacy_matrix_for_token_holders(): void
    {
        $publicProfile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        $unlistedProfile = $this->profile(MemorialPrivacyMode::UNLISTED->value);
        $familyOnlyProfile = $this->profile(MemorialPrivacyMode::FAMILY_ONLY->value);
        $privateProfile = $this->profile(MemorialPrivacyMode::PRIVATE->value);

        app(GrantMemorialEditor::class)($familyOnlyProfile, 'family:1', 'consent-ref-1', 'user:1', 'operator');
        app(GrantMemorialEditor::class)($privateProfile, 'family:2', 'consent-ref-2', 'user:1', 'operator');

        $cases = [
            [$publicProfile, null, true, 'public × guest'],
            [$publicProfile, 'stranger:1', true, 'public × non-editor actor'],
            [$publicProfile, 'family:1', true, 'public × editor'],

            [$unlistedProfile, null, true, 'unlisted × guest'],
            [$unlistedProfile, 'stranger:1', true, 'unlisted × non-editor actor'],
            [$unlistedProfile, 'family:1', true, 'unlisted × editor'],

            [$familyOnlyProfile, null, false, 'family_only × guest'],
            [$familyOnlyProfile, 'stranger:1', false, 'family_only × non-editor actor'],
            [$familyOnlyProfile, 'family:1', true, 'family_only × editor'],

            [$privateProfile, null, false, 'private × guest'],
            [$privateProfile, 'stranger:1', false, 'private × non-editor actor'],
            [$privateProfile, 'family:2', true, 'private × editor'],
        ];

        foreach ($cases as [$profile, $identity, $expected, $label]) {
            $this->assertSame(
                $expected,
                $profile->isVisibleTo($this->actor($identity), hasToken: true),
                "[{$label}] must be visible={$expected} for a token holder."
            );
        }
    }

    public function test_resolve_returns_the_allowlisted_projection_for_a_public_profile(): void
    {
        $this->openMemorialGate();
        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        $profile->update(['display_name' => 'Almarhumah Siti']);
        app(PublishMemorial::class)($profile, 'user:1', 'operator');
        $token = MemorialQrToken::issueFor($profile);

        app(SubmitMemorialContent::class)($profile, 'Doa-doa terbaik', 'family:1', 'family');
        app(SubmitMemorialContent::class)($profile, 'Belum dimoderasi', 'family:1', 'family');
        $approved = app(SubmitMemorialContent::class)($profile, 'Tersenyumlah di sana', 'family:1', 'family');
        app(ModerateMemorialContent::class)($approved, MemorialModerationState::APPROVED, 'moderator:1', 'moderator');

        MemorialMedia::query()->create([
            'memorial_profile_id' => $profile->getKey(),
            'storage_ref' => $this->acceptedDocument()->getKey(),
            'moderation_state' => MemorialModerationState::APPROVED->value,
        ]);

        $projection = app(ResolveMemorialQr::class)($token->token, null);

        $this->assertInstanceOf(MemorialPublicProjection::class, $projection);
        $this->assertSame((string) $profile->getKey(), $projection->profileId);
        $this->assertSame('Almarhumah Siti', $projection->displayName);
        $this->assertNotNull($projection->publishedAt);
        $this->assertSame(['Tersenyumlah di sana'], $projection->approvedContentBodies);
        $this->assertCount(1, $projection->acceptedMediaRefs);
    }

    public function test_projection_never_carries_private_fields(): void
    {
        $this->openMemorialGate();
        $grave = $this->grave();
        $profile = app(CreateMemorialProfile::class)($grave, 'user:1', 'operator', MemorialPrivacyMode::PUBLIC->value);
        $token = MemorialQrToken::issueFor($profile);

        $projection = app(ResolveMemorialQr::class)($token->token, null);

        $this->assertNull($projection->displayName);
        $this->assertNotSame((string) $grave->getKey(), $projection->profileId);
        $this->assertObjectNotHasProperty('graveRecordId', $projection);
        $this->assertObjectNotHasProperty('privacyMode', $projection);
        $this->assertObjectNotHasProperty('unpublishedAt', $projection);
        $this->assertObjectNotHasProperty('token', $projection);
        $this->assertObjectNotHasProperty('editors', $projection);
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
