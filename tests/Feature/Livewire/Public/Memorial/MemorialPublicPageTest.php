<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Memorial;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\Actions\GrantMemorialEditor;
use App\Domain\Memorial\Actions\ModerateMemorialContent;
use App\Domain\Memorial\Actions\PublishMemorial;
use App\Domain\Memorial\Actions\SubmitMemorialContent;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Livewire\Public\Memorial\MemorialFamilyPage;
use App\Livewire\Public\Memorial\MemorialPublicPage;
use App\Models\User;
use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The two public Livewire surfaces of the Memorial module — `MemorialPublicPage`
 * (`/m/{token}`, the QR resolve page) and `MemorialFamilyPage`
 * (`/memorial/{profileId}`, the consent-gated family surface).
 *
 * `.kiro/specs/memorial-and-qr/requirements.md` AC2–AC5 plus design.md's
 * "Sequence — QR resolve, gate-checked" and Error handling sections.
 *
 * ===========================================================================
 * THE UNIFORM NOT-VISIBLE STATE IS THE POINT OF THIS FILE
 * ===========================================================================
 * AC5's negative criterion: gate-closed, unknown token, revoked token, and
 * privacy-denied all render THE SAME "Memorial tidak tersedia" state — the
 * response never reveals which case applied, and never reveals whether the
 * memorial exists at all. Several tests below assert what is ABSENT as hard
 * as what is present: a page that leaked the `becausePrivacy` message (which
 * embeds the privacy mode) or distinguished "gate closed" from "not found"
 * would pass a present-only assertion while being exactly the defect.
 */
final class MemorialPublicPageTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private const UNIFORM_NOT_VISIBLE = 'Memorial tidak tersedia';

    private const PRIVACY_MODE_MARKER = 'privacy mode';

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Throwaway object-storage root per test — the container-resolved
        // UploadDocument the family page uses must never touch the real
        // dev storage tree (same precedent as UploadDocumentTest).
        $this->storageRoot = sys_get_temp_dir().'/memorial-page-test-'.Str::random(12);
        $this->app->instance(ObjectStorage::class, new LocalFilesystemObjectStorage($this->storageRoot));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);

        parent::tearDown();
    }

    private function openMemorialGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-MEM-01')->update(['state' => 'open']);
    }

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
        $profile = app(CreateMemorialProfile::class)($this->grave(), 'user:1', 'operator', $privacyMode);
        $profile->forceFill(['display_name' => 'Almarhum Ahmad Uji'])->save();

        return $profile;
    }

    private function tokenFor(MemorialProfile $profile): MemorialQrToken
    {
        return MemorialQrToken::issueFor($profile);
    }

    private function editorFor(MemorialProfile $profile, User $user): void
    {
        app(GrantMemorialEditor::class)(
            $profile,
            $user->id,
            (string) Str::uuid(),
            'admin:1',
            'admin',
        );
    }

    private function approvedContent(MemorialProfile $profile, string $body): void
    {
        $content = app(SubmitMemorialContent::class)($profile, $body, 'family:1', 'family');
        app(ModerateMemorialContent::class)($content, MemorialModerationState::APPROVED, 'moderator:1', 'moderator');
    }

    // =====================================================================
    // PUBLIC PAGE — the uniform not-visible state (AC5)
    // =====================================================================

    /**
     * The seeded `G-MEM-01` state is closed, so the uniform state is what an
     * unmodified environment renders for a valid token — no token lookup is
     * ever attempted while the gate is closed (design.md's sequence).
     */
    public function test_gate_closed_renders_the_uniform_state_even_for_a_valid_token(): void
    {
        $this->assertSame(
            'closed',
            FeatureGate::query()->where('gate_id', 'G-MEM-01')->firstOrFail()->state,
            'This suite assumes the real seeded default (closed); update it if that default ever changes.'
        );

        $token = $this->tokenFor($this->profile(MemorialPrivacyMode::PUBLIC->value));

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE)
            ->assertDontSee('Almarhum Ahmad Uji');
    }

    /**
     * Gate open + a token that never existed: the same uniform state.
     */
    public function test_unknown_token_renders_the_same_uniform_state(): void
    {
        $this->openMemorialGate();

        Livewire::test(MemorialPublicPage::class, ['token' => Str::random(48)])
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE);
    }

    /**
     * Gate open + a REVOKED token: identical to a token that never existed
     * (design.md's Error handling: "revoked/rotated token responds
     * identically to a token that never existed").
     */
    public function test_revoked_token_renders_the_same_uniform_state(): void
    {
        $this->openMemorialGate();

        $token = $this->tokenFor($this->profile(MemorialPrivacyMode::PUBLIC->value));
        $token->revoke();

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE);
    }

    /**
     * Gate open + valid token + a PRIVATE profile + a guest resolver (the
     * token alone is never sufficient for private — AC2's matrix).
     */
    public function test_private_profile_without_an_editor_renders_the_same_uniform_state(): void
    {
        $this->openMemorialGate();

        $token = $this->tokenFor($this->profile(MemorialPrivacyMode::PRIVATE->value));

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE);
    }

    /**
     * The REVIEW-WATCH rule: the /m/{token} render never surfaces privacy
     * details. The `becausePrivacy` exception message embeds the mode — it
     * is log-only. Assert the message wording NEVER appears in the HTML
     * for the denied case (present-only assertions would miss exactly this
     * leak). The mode VALUES are deliberately not asserted as bare
     * substrings here — "public" legitimately appears in Livewire's own
     * `wire:name` scaffold — so the check targets the one string only the
     * exception message can introduce.
     */
    public function test_the_because_privacy_message_never_appears_in_the_html(): void
    {
        $this->openMemorialGate();

        $token = $this->tokenFor($this->profile(MemorialPrivacyMode::FAMILY_ONLY->value));

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE)
            ->assertDontSee(self::PRIVACY_MODE_MARKER);
    }

    // =====================================================================
    // PUBLIC PAGE — the allowlist projection (AC3)
    // =====================================================================

    /**
     * Gate open + a PUBLIC profile: the allowlist renders — display name,
     * published date, approved bodies, accepted media count — and nothing
     * else: a pending body and the privacy mode must stay absent.
     */
    public function test_public_profile_renders_only_the_allowlist(): void
    {
        $this->openMemorialGate();

        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $this->approvedContent($profile, 'Kenangan yang disetujui moderator.');
        app(SubmitMemorialContent::class)($profile, 'Masih menunggu moderasi.', 'family:1', 'family');
        $token = $this->tokenFor($profile);

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee('Almarhum Ahmad Uji')
            ->assertSee('Kenangan yang disetujui moderator.')
            ->assertDontSee('Masih menunggu moderasi.')
            ->assertDontSee(self::PRIVACY_MODE_MARKER);
    }

    /**
     * AC2's matrix: family_only needs token + an active editor for the
     * actor. With an editor the projection renders; the mode value itself
     * stays out of the HTML (it is not allowlisted).
     */
    public function test_family_only_profile_renders_for_an_active_editor(): void
    {
        $this->openMemorialGate();

        $profile = $this->profile(MemorialPrivacyMode::FAMILY_ONLY->value);
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $this->approvedContent($profile, 'Catatan keluarga.');
        $token = $this->tokenFor($profile);

        $editor = User::factory()->create();
        $this->editorFor($profile, $editor);
        $this->actingAs($editor);

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee('Almarhum Ahmad Uji')
            ->assertSee('Catatan keluarga.')
            ->assertDontSee(self::PRIVACY_MODE_MARKER);
    }

    /**
     * The gate is re-checked on render: a projection that resolved while
     * open falls back to the uniform state the moment the gate closes.
     */
    public function test_the_gate_is_re_checked_on_render(): void
    {
        $this->openMemorialGate();

        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $token = $this->tokenFor($profile);

        $component = Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee('Almarhum Ahmad Uji');

        FeatureGate::query()->where('gate_id', 'G-MEM-01')->update(['state' => 'closed']);
        app(FeatureGateResolver::class)->forget();

        $component->call('$refresh')
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE)
            ->assertDontSee('Almarhum Ahmad Uji');
    }

    // =====================================================================
    // FAMILY PAGE — consent-gated (AC1)
    // =====================================================================

    public function test_the_old_memorial_path_redirects_permanently_to_kenangan(): void
    {
        $profile = $this->profile();

        $this->get("/memorial/{$profile->getKey()}")
            ->assertRedirect("/kenangan/{$profile->getKey()}")
            ->assertStatus(301);
    }

    /**
     * An actor who is not an active editor of the profile gets the same
     * uniform not-visible state — cross-family access never reveals whether
     * the memorial exists (kiro tasks.md §6.4).
     */
    public function test_family_page_denies_a_non_editor_with_the_uniform_state(): void
    {
        $profile = $this->profile();

        $stranger = User::factory()->create();
        $this->actingAs($stranger);

        Livewire::test(MemorialFamilyPage::class, ['profileId' => $profile->getKey()])
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE)
            ->assertDontSee('Almarhum Ahmad Uji');
    }

    /**
     * An unknown profile id renders the same uniform state (no existence
     * leak on this route either).
     */
    public function test_family_page_denies_an_unknown_profile_with_the_uniform_state(): void
    {
        Livewire::test(MemorialFamilyPage::class, ['profileId' => (string) Str::uuid()])
            ->assertOk()
            ->assertSee(self::UNIFORM_NOT_VISIBLE);
    }

    /**
     * An active editor can submit content; the submission lands pending —
     * nothing family-authored renders publicly until moderation acts.
     */
    public function test_family_editor_submits_content_into_pending(): void
    {
        $profile = $this->profile();
        $editor = User::factory()->create();
        $this->editorFor($profile, $editor);
        $this->actingAs($editor);

        Livewire::test(MemorialFamilyPage::class, ['profileId' => $profile->getKey()])
            ->assertOk()
            ->assertSee('Almarhum Ahmad Uji')
            ->set('body', 'Tulisan dari keluarga.')
            ->call('submitContent')
            ->assertHasNoErrors()
            ->assertSee('Tulisan dari keluarga.');

        $this->assertDatabaseHas('memorial_contents', [
            'memorial_profile_id' => $profile->getKey(),
            'body' => 'Tulisan dari keluarga.',
            'moderation_state' => 'pending',
        ]);
    }

    /**
     * A family media upload enters the document vault QUARANTINED — never
     * previewable and never attachable as memorial_media before scan
     * acceptance (the model's Accepted-document creating guard).
     */
    public function test_family_editor_uploads_media_into_quarantine(): void
    {
        Queue::fake();

        $profile = $this->profile();
        $editor = User::factory()->create();
        $this->editorFor($profile, $editor);
        $this->actingAs($editor);

        Livewire::test(MemorialFamilyPage::class, ['profileId' => $profile->getKey()])
            ->upload('mediaFile', [UploadedFile::fake()->createWithContent('foto.png', $this->minimalPng())])
            ->call('uploadMedia')
            ->assertHasNoErrors()
            ->assertSee('Menunggu pemindaian');

        $document = Document::query()->sole();

        $this->assertSame(DocumentState::Quarantined, $document->state);
        $this->assertSame('memorial_profile', $document->owner_type);
        $this->assertSame($profile->getKey(), $document->owner_id);
        $this->assertSame(DocumentKind::ProductImage, $document->document_kind);
        $this->assertDatabaseMissing('memorial_media', ['memorial_profile_id' => $profile->getKey()]);
    }

    /**
     * The family page displays the active token's QR SVG and rotation
     * revokes the old token and mints a new one (AC5) with the audit row.
     */
    public function test_family_editor_sees_the_qr_and_rotates_the_token(): void
    {
        $profile = $this->profile();
        $old = $this->tokenFor($profile);
        $editor = User::factory()->create();
        $this->editorFor($profile, $editor);
        $this->actingAs($editor);

        Livewire::test(MemorialFamilyPage::class, ['profileId' => $profile->getKey()])
            ->assertOk()
            ->assertSee('<svg', false)
            ->call('rotateToken')
            ->assertHasNoErrors();

        $this->assertNotNull($old->fresh()->revoked_at, 'The old token must be revoked in place.');
        $new = MemorialQrToken::activeFor($profile);
        $this->assertNotNull($new, 'A new active token must exist after rotation.');
        $this->assertNotSame($old->token, $new->token);
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_QR_ROTATED]);
    }

    /**
     * Changing privacy from the family page is audited; the new mode
     * governs the QR resolve immediately (family_only → public opens the
     * projection to any token holder).
     */
    public function test_family_privacy_change_is_audited_and_takes_effect(): void
    {
        $this->openMemorialGate();

        $profile = $this->profile(MemorialPrivacyMode::FAMILY_ONLY->value);
        $this->approvedContent($profile, 'Catatan keluarga.');
        $token = $this->tokenFor($profile);
        $editor = User::factory()->create();
        $this->editorFor($profile, $editor);
        $this->actingAs($editor);

        Livewire::test(MemorialFamilyPage::class, ['profileId' => $profile->getKey()])
            ->assertOk()
            ->set('privacyMode', MemorialPrivacyMode::PUBLIC->value)
            ->call('changePrivacy')
            ->assertHasNoErrors();

        $this->assertSame(MemorialPrivacyMode::PUBLIC->value, $profile->fresh()->privacy_mode);
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_PRIVACY_CHANGED]);

        // The QR resolve now serves the projection to a guest (the
        // profile is published — an unpublished profile never resolves,
        // the whole-branch review fix).
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertSee('Almarhum Ahmad Uji')
            ->assertSee('Catatan keluarga.');
    }

    /**
     * An accepted (scanned) upload attaches as a PENDING memorial_media row
     * on the next family render — the media lifecycle completes:
     * quarantine → scan → attach → moderate. The public projection only
     * renders it after approval (AC6), so it must NOT appear there yet.
     */
    public function test_an_accepted_upload_attaches_as_pending_media_after_scan(): void
    {
        $this->openMemorialGate();

        $profile = $this->profile(MemorialPrivacyMode::PUBLIC->value);
        $token = $this->tokenFor($profile);
        $editor = User::factory()->create();
        $this->editorFor($profile, $editor);
        $this->actingAs($editor);

        $document = Document::createQuarantined([
            'document_kind' => DocumentKind::ProductImage,
            'owner_type' => 'memorial_profile',
            'owner_id' => $profile->getKey(),
            'original_filename' => 'foto.png',
            'storage_prefix' => 'quarantine',
            'storage_key' => Str::random(40),
            'size_bytes' => 1024,
            'mime_declared' => 'image/png',
        ]);
        $document->transitionTo(DocumentState::Scanning);
        $document->promote();

        Livewire::test(MemorialFamilyPage::class, ['profileId' => $profile->getKey()])
            ->assertOk()
            ->assertSee('Menunggu moderasi');

        $this->assertDatabaseHas('memorial_media', [
            'memorial_profile_id' => $profile->getKey(),
            'storage_ref' => $document->getKey(),
            'moderation_state' => 'pending',
        ]);

        // And the public page must not render it (pending is not
        // approved). The profile is published so the projection actually
        // resolves — the assertion stays about moderation, not about the
        // unpublished uniform state.
        app(PublishMemorial::class)($profile, 'moderator:1', 'moderator');
        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();

        Livewire::test(MemorialPublicPage::class, ['token' => $token->token])
            ->assertOk()
            ->assertDontSee('foto.png');
    }

    /**
     * A real 1x1 PNG (GD-free — the CI runner has no gd extension, so
     * `UploadedFile::fake()->image()` is unusable here).
     */
    private function minimalPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            true,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($directory);
    }
}
