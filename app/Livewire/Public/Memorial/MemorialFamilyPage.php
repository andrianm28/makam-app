<?php

declare(strict_types=1);

namespace App\Livewire\Public\Memorial;

use App\Domain\Memorial\Actions\ChangeMemorialPrivacy;
use App\Domain\Memorial\Actions\RotateMemorialQrToken;
use App\Domain\Memorial\Actions\SubmitMemorialContent;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\MemorialQrImage;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Platform\DocumentVault\Actions\UploadDocument;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\IdentityAccess\ActorContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * `/memorial/{profileId}` — the family surface: a private, consent-gated
 * page where active editors of a memorial manage it
 * (`.kiro/specs/memorial-and-qr/requirements.md` AC1/AC2/AC6; the plan's
 * Task 4 brief: "family content management — private, consent-gated").
 *
 * ===========================================================================
 * CONSENT GATE FIRST, UNIFORM STATE AFTER
 * ===========================================================================
 * `mount()` requires an ACTIVE `memorial_editors` row for the actor
 * (`MemorialProfile::hasActiveEditor`). Anyone else — a guest, a stranger,
 * or a revoked editor — gets the SAME uniform "Memorial tidak tersedia"
 * state the public page renders (kiro tasks.md §6.4: cross-family access
 * must not reveal whether the memorial exists), and render() re-checks the
 * editor gate so a revocation mid-session closes the surface immediately.
 * An unknown profile id resolves to the same uniform state (no existence
 * leak on this route either).
 *
 * ===========================================================================
 * MEDIA LIFECYCLE: upload → quarantine → scan → attach → moderate
 * ===========================================================================
 * `uploadMedia()` goes through the platform document vault
 * (`UploadDocument`) with `DocumentKind::ProductImage` — the closed
 * `DocumentKind` enum is CHECK-constraint-backed and has no memorial case,
 * so the brief's fallback ("reuse an existing suitable kind") applies:
 * `ProductImage` is the image-only kind (jpg/jpeg/png, 10 MB) closest to
 * memorial photos. The vault quarantines and queues the scan; the upload
 * NEVER attaches a `memorial_media` row — the model's creating guard only
 * admits ACCEPTED vault documents (fail-closed), so nothing is attachable
 * before the scan accepts it.
 *
 * Attachment happens on render: accepted vault documents owned by this
 * profile attach as PENDING `memorial_media` rows (a small, documented
 * sync step — the queue has no memorial-side consumer, and this page is
 * the family's own reload point). The unique
 * `(memorial_profile_id, storage_ref)` index backstops the exists-then-
 * create against concurrent renders (the loser's `QueryException` is
 * classified and swallowed — the incumbent row is the result either
 * way). The family therefore sees, in order: uploaded-but-unscanned
 * documents as "Menunggu pemindaian", attached pending media as
 * "Menunggu moderasi", and nothing else until a moderator approves.
 *
 * ===========================================================================
 * PRIVACY + QR
 * ===========================================================================
 * The privacy selector offers the brief's three non-private modes
 * (family_only/unlisted/public), each with its consequence stated; the
 * change runs `ChangeMemorialPrivacy` (audited). The active token's QR SVG
 * renders from `MemorialQrImage::svg(route('memorial.show', $token))` and
 * `rotateToken()` runs `RotateMemorialQrToken` (audited, outboxed).
 *
 * The display-name field is the ONE place `memorial_profiles.display_name`
 * is authored by the family — the allowlist field `MemorialPublicProjection`
 * renders for QR visitors (family-set, never copied from the grave record —
 * AC7). Plain model save, no audit (no transition of state).
 *
 * Upload idempotency note: `uploadMedia()` passes a null `clientUploadId`
 * (a fresh quarantine per attempt) — see the Task 4 report; the vault's
 * resume path needs a client-held token this surface does not yet carry.
 */
final class MemorialFamilyPage extends Component
{
    use WithFileUploads;

    public ?MemorialProfile $profile = null;

    public bool $visible = false;

    /**
     * The display-name field — family-authored, allowlisted downstream.
     */
    public string $displayName = '';

    /**
     * The current privacy mode, bound to the three-option selector.
     */
    public string $privacyMode = MemorialPrivacyMode::PRIVATE->value;

    /**
     * The new tribute/message body awaiting moderation.
     */
    public string $body = '';

    /**
     * The media file chosen on the page.
     */
    public $mediaFile;

    public string $notice = '';

    public string $error = '';

    public function mount(string $profileId): void
    {
        $this->profile = MemorialProfile::query()->find($profileId);

        if (! $this->profile instanceof MemorialProfile || ! $this->profile->hasActiveEditor(app(ActorContext::class))) {
            $this->visible = false;

            return;
        }

        $this->visible = true;
        $this->displayName = (string) ($this->profile->display_name ?? '');
        $this->privacyMode = (string) $this->profile->privacy_mode;
    }

    public function render(): View
    {
        if ($this->visible) {
            // Re-check the editor gate on render — a revocation mid-session
            // closes the surface immediately (uniform state).
            if (! $this->profile->hasActiveEditor(app(ActorContext::class))) {
                $this->visible = false;
            } else {
                $this->attachAcceptedUploads();
            }
        }

        return view('livewire.public.memorial.family-page', $this->viewData());
    }

    public function updateDisplayName(): void
    {
        if (! $this->assertEditor()) {
            return;
        }

        $validated = Validator::make(
            ['displayName' => $this->displayName],
            ['displayName' => ['nullable', 'string', 'max:160']],
        )->validate();

        $this->profile->forceFill(['display_name' => trim((string) $validated['displayName']) ?: null])->save();
        $this->displayName = (string) ($this->profile->fresh()->display_name ?? '');
        $this->notice = 'Nama tampilan diperbarui.';
    }

    public function submitContent(): void
    {
        if (! $this->assertEditor()) {
            return;
        }

        $validated = Validator::make(
            ['body' => $this->body],
            ['body' => ['required', 'string', 'min:3', 'max:2000']],
        )->validate();

        $actor = app(ActorContext::class);

        app(SubmitMemorialContent::class)(
            $this->profile,
            $validated['body'],
            $actor->identityReference ?? 'anonymous',
            'family',
        );

        $this->body = '';
        $this->notice = 'Catatan dikirim dan menunggu moderasi.';
    }

    public function uploadMedia(): void
    {
        if (! $this->assertEditor()) {
            return;
        }

        $validated = Validator::make(
            ['mediaFile' => $this->mediaFile],
            ['mediaFile' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240']],
        )->validate();

        /** @var UploadedFile $file */
        $file = $validated['mediaFile'];

        $mime = $file->getMimeType();

        app(UploadDocument::class)->upload(
            DocumentKind::ProductImage,
            $file,
            'memorial_profile',
            (string) $this->profile->getKey(),
            null,
            $mime !== false && $mime !== null ? ['mime_declared' => $mime] : [],
        );

        $this->mediaFile = null;
        $this->notice = 'Media diunggah dan menunggu pemindaian keamanan.';
    }

    public function rotateToken(): void
    {
        if (! $this->assertEditor()) {
            return;
        }

        $actor = app(ActorContext::class);

        app(RotateMemorialQrToken::class)(
            $this->profile,
            $actor->identityReference ?? 'anonymous',
            'family',
        );

        $this->notice = 'Kode QR baru diterbitkan; kode lama tidak lagi berlaku.';
    }

    public function changePrivacy(): void
    {
        if (! $this->assertEditor()) {
            return;
        }

        $validated = Validator::make(
            ['privacyMode' => $this->privacyMode],
            ['privacyMode' => ['required', Rule::in([
                MemorialPrivacyMode::FAMILY_ONLY->value,
                MemorialPrivacyMode::UNLISTED->value,
                MemorialPrivacyMode::PUBLIC->value,
            ])]],
        )->validate();

        $actor = app(ActorContext::class);

        app(ChangeMemorialPrivacy::class)(
            $this->profile,
            $validated['privacyMode'],
            $actor->identityReference ?? 'anonymous',
            'family',
        );

        $this->privacyMode = (string) $this->profile->fresh()->privacy_mode;
        $this->notice = 'Privasi diperbarui.';
    }

    /**
     * Fail-closed gate for every mutating action: an actor who lost their
     * editor row between requests gets the uniform state and no write.
     */
    private function assertEditor(): bool
    {
        if ($this->visible && $this->profile->hasActiveEditor(app(ActorContext::class))) {
            return true;
        }

        $this->visible = false;
        $this->error = '';

        return false;
    }

    /**
     * Attach accepted vault documents owned by this profile as PENDING
     * `memorial_media` rows — see the class doc block for the lifecycle.
     */
    private function attachAcceptedUploads(): void
    {
        $accepted = Document::query()
            ->where('owner_type', 'memorial_profile')
            ->where('owner_id', (string) $this->profile->getKey())
            ->where('state', DocumentState::Accepted->value)
            ->pluck('id');

        foreach ($accepted as $documentId) {
            try {
                $this->profile->media()->firstOrCreate(
                    ['storage_ref' => (string) $documentId],
                    ['moderation_state' => MemorialModerationState::DEFAULT],
                );
            } catch (QueryException $exception) {
                // Two renders raced the exists-then-create: the unique
                // index (memorial_media_profile_storage_unique) refused the
                // loser. The incumbent row IS the result — re-raising would
                // turn an already-correct state into an error page.
                if (! $this->isDuplicateAttach($exception)) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Narrow duplicate classifier for the attach race — mirrors
     * `UploadDocument::isDuplicateClientUploadId()`: only the unique
     * violation on `(memorial_profile_id, storage_ref)` is swallowed,
     * never an unrelated query failure. The two drivers name the same
     * constraint differently: PostgreSQL reports the index name
     * (`memorial_media_profile_storage_unique`), SQLite reports the
     * column pair ("unique constraint failed: memorial_media.
     * memorial_profile_id, memorial_media.storage_ref").
     */
    private function isDuplicateAttach(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'memorial_media_profile_storage_unique')
            || str_contains(
                $message,
                'unique constraint failed: memorial_media.memorial_profile_id',
            );
    }

    /**
     * The page's read models, resolved fresh per render.
     *
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        if (! $this->visible || ! $this->profile instanceof MemorialProfile) {
            return [
                'contents' => [],
                'media' => [],
                'pendingUploads' => [],
                'qrSvg' => null,
                'activeToken' => null,
            ];
        }

        $contents = $this->profile->contents()->orderByDesc('created_at')->get();
        $media = $this->profile->media()->orderByDesc('created_at')->get();

        $pendingUploads = Document::query()
            ->where('owner_type', 'memorial_profile')
            ->where('owner_id', (string) $this->profile->getKey())
            ->where('state', '!=', DocumentState::Accepted->value)
            ->orderByDesc('created_at')
            ->get();

        $activeToken = MemorialQrToken::activeFor($this->profile);
        $qrSvg = $activeToken instanceof MemorialQrToken
            ? app(MemorialQrImage::class)->svg(route('memorial.show', ['token' => $activeToken->token]))
            : null;

        return [
            'contents' => $contents,
            'media' => $media,
            'pendingUploads' => $pendingUploads,
            'qrSvg' => $qrSvg,
            'activeToken' => $activeToken,
        ];
    }
}
