<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Actions;

use App\Platform\Audit\AuditOutcome;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Contracts\StoragePathResolver;
use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Exceptions\DocumentAccessDeniedException;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\SignedUrlGrant;
use App\Platform\DocumentVault\Policies\DocumentAccessPolicy;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class DownloadDocument
{
    public function __construct(
        private DocumentAccessPolicy $policy,
        private RecordDocumentAccess $recordAccess,
        private ObjectStorage $storage,
        private StoragePathResolver $paths,
    ) {}

    /** @throws DocumentAccessDeniedException */
    public function download(ActorContext $actor, string $documentId, string $token, ?string $ipAddress = null): StreamedResponse
    {
        $document = Str::isUuid($documentId) ? Document::query()->find($documentId) : null;
        $grant = $document instanceof Document
            ? SignedUrlGrant::query()->where('document_id', $document->getKey())->where('token', $token)->first()
            : null;

        if (! $document instanceof Document || ! $grant instanceof SignedUrlGrant) {
            $this->deny($actor, $document, $ipAddress);
        }

        if (
            $grant->actor_ref === null
            || (string) $grant->actor_ref !== (string) $actor->identityReference
            || $grant->purpose !== DocumentAccessPurpose::Download
            || $grant->consumed_at !== null
            || CarbonImmutable::now()->greaterThanOrEqualTo($grant->expires_at)
            || ! $this->policy->canView($actor, $document)
            || $document->state !== DocumentState::Accepted
            || $document->storage_prefix !== 'accepted'
        ) {
            $this->deny($actor, $document, $ipAddress);
        }

        try {
            $stream = $this->storage->read($this->paths->acceptedPath($document->document_kind, $document->storage_key));
        } catch (Throwable) {
            $this->deny($actor, $document, $ipAddress, AuditOutcome::Failed);
        }

        if (! $grant->consume()) {
            fclose($stream);
            $this->deny($actor, $document, $ipAddress);
        }

        $this->recordAccess->record($document, $actor, DocumentAccessPurpose::Download, AuditOutcome::Allowed, $ipAddress);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) $document->original_filename)) ?: 'document';
        $mime = (string) ($document->mime_verified ?: $document->mime_declared ?: 'application/octet-stream');

        return response()->streamDownload(static function () use ($stream): void {
            $output = fopen('php://output', 'wb');
            stream_copy_to_stream($stream, $output);
            fclose($output);
            fclose($stream);
        }, $filename, ['Content-Type' => $mime]);
    }

    private function deny(
        ActorContext $actor,
        ?Document $document,
        ?string $ipAddress,
        AuditOutcome $outcome = AuditOutcome::Denied,
    ): never {
        if ($document instanceof Document) {
            $this->recordAccess->record($document, $actor, DocumentAccessPurpose::Download, $outcome, $ipAddress);
        }

        throw DocumentAccessDeniedException::denied();
    }
}
