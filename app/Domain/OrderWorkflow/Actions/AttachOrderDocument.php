<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderDocument;
use App\Platform\DocumentVault\Actions\UploadDocument;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\StreamInterface;

/**
 * The single writer of `order_documents` (Task 5, AC10). Composes the
 * platform Document Vault's `UploadDocument` with
 * `ownerType = ScopeEntityType::ORDER` and `ownerId = $order->id` — the
 * vault owns storage, quarantine, validation, scanning, promotion, signed
 * URLs, and access recording; this Action owns only the order-side binding
 * and its attribution.
 *
 * Idempotency is structural, in two layers that cannot disagree:
 * 1. The vault's own resume: a repeated `$clientUploadId` for the same
 *    owner returns the SAME vault `Document` (no second upload, no second
 *    `document.uploaded.v1` outbox event, no re-scan).
 * 2. This Action's `firstOrCreate` on `(order_id, document_id)`: even if
 *    the same vault document were somehow presented twice, the unique
 *    `order_documents_order_document_unq` index returns the existing
 *    registry row instead of creating a duplicate binding.
 *
 * The whole operation runs in one transaction: the vault upload and the
 * registry row commit or roll back together, so an order can never point at
 * a registry row whose vault document does not exist.
 *
 * `ownerId` is cast to string because the vault's `documents.owner_id` is a
 * string column regardless of the owning aggregate's key type.
 */
final readonly class AttachOrderDocument
{
    public function __construct(
        private UploadDocument $upload,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  Recognized keys: `original_filename`
     *                                      and `mime_declared` — see
     *                                      `UploadDocument`'s class doc block.
     */
    public function __invoke(
        Order $order,
        DocumentKind $kind,
        UploadedFile|StreamInterface $file,
        ?string $clientUploadId,
        array $meta,
        string $actorRef,
        string $actorRole,
    ): OrderDocument {
        return DB::transaction(function () use (
            $order,
            $kind,
            $file,
            $clientUploadId,
            $meta,
            $actorRef,
            $actorRole,
        ): OrderDocument {
            $document = $this->upload->upload(
                $kind,
                $file,
                ScopeEntityType::ORDER,
                $order->getKey(),
                $clientUploadId,
                $meta,
            );

            return OrderDocument::query()->firstOrCreate(
                [
                    'order_id' => $order->getKey(),
                    'document_id' => $document->getKey(),
                ],
                [
                    'attached_by_ref' => $actorRef,
                    'attached_by_role' => $actorRole,
                    'attached_at' => CarbonImmutable::now(),
                ],
            );
        });
    }
}
