<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Eloquent model for `documents`
 * (`2026_08_09_100000_create_documents_table.php`).
 *
 * `App\Platform\DocumentVault\Actions\UploadDocument` is the only class
 * authorized to create a row here, and the only one authorized to write the
 * upload/resume transitions this task owns (module-wide "one write API per
 * table" rule — mirrors `App\Platform\Audit\Audit::record()`/
 * `App\Platform\Outbox\Outbox::record()`'s role for their own tables). Later
 * tasks add their own designated write Actions for their own transitions
 * (Task 5's `ScanDocument`/`PromoteDocument`/`RetainDocument`); this model
 * has no way to enforce "only the designated Action" itself (Eloquent has
 * no built-in single-writer guard, unlike `DocumentAccessEvent`'s
 * append-only guard, which blocks an entire verb outright) — the discipline
 * is structural: no other class in this codebase calls
 * `Document::create()`/`save()`/`update()`.
 *
 * `document_kind`/`state` are cast to their backed PHP enums
 * (`DocumentKind`/`DocumentState`, Laravel's native enum-cast support) so an
 * invalid value — including a correctly-spelled but wrongly-cased one like
 * `'accepted'` instead of `'ACCEPTED'` — throws `ValueError` the instant
 * Eloquent tries to hydrate or set it, independent of the PostgreSQL-only
 * CHECK constraint that this repository's default local test driver
 * (SQLite) does not enforce. See `tests/Unit/Platform/DocumentVault/Models/
 * DocumentTest.php`.
 */
final class Document extends Model
{
    use HasUuids;

    /**
     * Content and storage identity become immutable once scanning begins.
     * Promotion changes `storage_prefix` only through `promote()`.
     *
     * @var list<string>
     */
    private const array IMMUTABLE_AFTER_SCANNING = [
        'document_kind',
        'original_filename',
        'storage_prefix',
        'storage_key',
        'size_bytes',
        'mime_declared',
        'mime_verified',
        'checksum_sha256',
        'scanner_required',
    ];

    protected $table = 'documents';

    protected $keyType = 'string';

    /**
     * `id` is deliberately NOT fillable — `HasUuids` generates it on insert
     * whenever it is absent, same precedent as
     * `App\Domain\Booking\Models\BookingDraft`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'document_kind',
        'owner_type',
        'owner_id',
        'original_filename',
        'storage_prefix',
        'storage_key',
        'size_bytes',
        'mime_declared',
        'mime_verified',
        'checksum_sha256',
        'client_upload_id',
        'legal_hold',
        'evidence_hold',
        'retention_until',
    ];

    /**
     * Create the only state this module may assign during upload. `state` is
     * intentionally excluded from `$fillable`; this explicit factory keeps
     * the quarantine-first invariant while still allowing the upload Action
     * to create a complete row in one save.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createQuarantined(array $attributes): static
    {
        $document = new self;
        $document->fill($attributes);

        if (! $document->document_kind instanceof DocumentKind) {
            throw new LogicException('A document kind is required before deriving scanner policy.');
        }

        $document->writeScannerPolicy($document->document_kind->scannerRequired());
        $document->writeState(DocumentState::Quarantined);
        $document->save();

        return $document;
    }

    /**
     * Update a non-accepted lifecycle state through the state machine. The
     * accepted state has a separate promotion-only method so a scan Action
     * cannot accidentally mark a document usable.
     */
    public function transitionTo(DocumentState $nextState): void
    {
        if ($nextState === DocumentState::Accepted) {
            throw new LogicException('Accepted documents must use the promotion path.');
        }

        $currentState = $this->state;

        if (! $currentState instanceof DocumentState) {
            throw new LogicException('A document must have a persisted state before it can transition.');
        }

        if ($currentState === $nextState) {
            return;
        }

        $allowedStates = match ($currentState) {
            DocumentState::Uploading => [DocumentState::Quarantined],
            DocumentState::Quarantined => [
                DocumentState::Scanning,
                DocumentState::Rejected,
                DocumentState::Expired,
                DocumentState::Deleted,
            ],
            DocumentState::Scanning => [
                DocumentState::Rejected,
                DocumentState::Expired,
                DocumentState::Deleted,
            ],
            DocumentState::Accepted => [DocumentState::Expired, DocumentState::Deleted],
            DocumentState::Rejected => [DocumentState::Expired, DocumentState::Deleted],
            DocumentState::Expired => [DocumentState::Deleted],
            DocumentState::Deleted => [],
        };

        if (! in_array($nextState, $allowedStates, true)) {
            throw new LogicException("Document state cannot transition from {$currentState->value} to {$nextState->value}.");
        }

        $this->writeState($nextState);
        $this->save();
    }

    /**
     * The only model-level path that may write `ACCEPTED`. Task 5's
     * promotion Action must establish its clean-scan and storage guarantees
     * before calling this method.
     */
    public function promote(): void
    {
        if ($this->state !== DocumentState::Scanning) {
            throw new LogicException('Only a scanning document may be promoted.');
        }

        $this->writeStoragePrefix('accepted');
        $this->writeState(DocumentState::Accepted);
        $this->save();
    }

    /**
     * Eloquent's `forceFill()` still routes through `setAttribute()`. Keep
     * state writes private to the explicit methods above, including callers
     * that try to bypass `$fillable` with direct assignment.
     */
    public function setAttribute($key, $value)
    {
        if ($key === 'state' && ! ($this->stateMutationAuthorized ?? false)) {
            throw new LogicException('Document state must be changed through its transition API.');
        }

        if ($key === 'scanner_required') {
            if ($this->exists && ! ($this->scannerPolicyMutationAuthorized ?? false)) {
                throw new LogicException('Scanner policy is derived from document kind and is immutable.');
            }

            if (! (bool) $value && $this->document_kind instanceof DocumentKind && $this->document_kind->scannerRequired()) {
                throw new LogicException('Restricted document kinds require malware scanning.');
            }
        }

        if (
            in_array($key, self::IMMUTABLE_AFTER_SCANNING, true)
            && $this->hasPassedScanningBoundary()
            && ! ($this->storageMutationAuthorized ?? false)
            && $key !== 'scanner_required'
        ) {
            throw new LogicException('Document content and storage metadata are immutable after scanning begins.');
        }

        return parent::setAttribute($key, $value);
    }

    private function writeState(DocumentState $state): void
    {
        $this->stateMutationAuthorized = true;

        try {
            $this->forceFill(['state' => $state]);
        } finally {
            $this->stateMutationAuthorized = false;
        }
    }

    private bool $stateMutationAuthorized = false;

    private bool $scannerPolicyMutationAuthorized = false;

    private bool $storageMutationAuthorized = false;

    private function writeScannerPolicy(bool $scannerRequired): void
    {
        $this->scannerPolicyMutationAuthorized = true;

        try {
            $this->forceFill(['scanner_required' => $scannerRequired]);
        } finally {
            $this->scannerPolicyMutationAuthorized = false;
        }
    }

    private function writeStoragePrefix(string $prefix): void
    {
        $this->storageMutationAuthorized = true;

        try {
            $this->forceFill(['storage_prefix' => $prefix]);
        } finally {
            $this->storageMutationAuthorized = false;
        }
    }

    private function hasPassedScanningBoundary(): bool
    {
        return $this->exists
            && $this->state instanceof DocumentState
            && ! in_array($this->state, [DocumentState::Uploading, DocumentState::Quarantined], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_kind' => DocumentKind::class,
            'state' => DocumentState::class,
            'size_bytes' => 'integer',
            'scanner_required' => 'boolean',
            'legal_hold' => 'boolean',
            'evidence_hold' => 'boolean',
            'retention_until' => 'immutable_datetime',
        ];
    }
}
