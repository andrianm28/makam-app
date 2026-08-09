<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
        'state',
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
        'scanner_required',
        'retention_until',
    ];

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
            'retention_until' => 'immutable_datetime',
        ];
    }
}
