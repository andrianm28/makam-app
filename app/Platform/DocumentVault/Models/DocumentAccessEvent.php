<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use App\Platform\Audit\AuditOutcome;
use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\Exceptions\DocumentAccessEventIsImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `document_access_events`
 * (`2026_08_09_100020_create_document_access_events_table.php`).
 *
 * Access events are append-only historical evidence. `$guarded = ['*']`
 * prevents accidental mass assignment, while the instance-method guards
 * below stop Eloquent update/save/delete mutation paths. The database-level
 * application-role REVOKE for UPDATE/DELETE is intentionally owned by Task 8;
 * query-builder and raw SQL writes remain outside this model-level defense
 * until that deployment change is applied.
 *
 * Rows are written exclusively through `recordAccess()`, called by
 * `Actions\RecordDocumentAccess` (every real access) and
 * `Actions\IssueSignedUrl` (every issuance, allowed or denied) — the module's
 * "one write API per table" rule, same shape as
 * `DocumentScan::recordAttempt()`.
 *
 * `actor_role` is NOT NULL by schema and has no corresponding single field on
 * `ActorContext`, so it is always derived by
 * `Policies\DocumentAccessPolicy::auditRoleFor()`; see that method for the
 * deterministic mapping (including the `'guest'` value used on
 * unauthenticated denial paths).
 */
final class DocumentAccessEvent extends Model
{
    public $timestamps = false;

    protected $table = 'document_access_events';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    public static function recordAccess(
        Document $document,
        int|string|null $actorRef,
        string $actorRole,
        DocumentAccessPurpose $purpose,
        AuditOutcome $outcome,
        ?string $ipAddress,
    ): static {
        $event = new self;
        $event->forceFill([
            'document_id' => $document->getKey(),
            'actor_ref' => $actorRef === null ? null : (string) $actorRef,
            'actor_role' => $actorRole,
            'purpose' => $purpose,
            'outcome' => $outcome->value,
            'ip_address' => $ipAddress,
            'occurred_at' => now(),
        ]);
        $event->save();

        return $event;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => DocumentAccessPurpose::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw DocumentAccessEventIsImmutableException::forOperation('update');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw DocumentAccessEventIsImmutableException::forOperation('performUpdate');
    }

    public function delete(): ?bool
    {
        throw DocumentAccessEventIsImmutableException::forOperation('delete');
    }
}
