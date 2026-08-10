<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable marker for an object-storage cleanup that must survive worker loss.
 * It is operational evidence, not a document-content payload.
 */
final class DocumentStorageCleanup extends Model
{
    public const string QUARANTINE_DELETE = 'QUARANTINE_DELETE';

    protected $table = 'document_storage_cleanups';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    public static function recordPending(Document $document): static
    {
        $cleanup = new self;
        $cleanup->forceFill([
            'document_id' => $document->getKey(),
            'operation' => self::QUARANTINE_DELETE,
            'attempt_count' => 0,
            'available_at' => now(),
        ]);
        $cleanup->save();

        return $cleanup;
    }

    public function markAttempt(): void
    {
        $this->forceFill([
            'attempt_count' => $this->attempt_count + 1,
            'last_error' => null,
        ])->save();
    }

    public function markFailed(): void
    {
        $delay = min(3600, 60 * (2 ** min($this->attempt_count - 1, 4)));

        $this->forceFill([
            'available_at' => now()->addSeconds($delay),
            'last_error' => 'cleanup_failed',
        ])->save();
    }

    public function markCompleted(): void
    {
        $this->forceFill([
            'completed_at' => now(),
            'last_error' => null,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'available_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
