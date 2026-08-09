<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

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
 */
final class DocumentAccessEvent extends Model
{
    public $timestamps = false;

    protected $table = 'document_access_events';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
