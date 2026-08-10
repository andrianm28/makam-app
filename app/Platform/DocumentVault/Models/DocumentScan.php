<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\ScanVerdict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only evidence for one malware-scanner attempt on a document.
 *
 * Scan rows are written through `recordAttempt()` so the scan Action remains
 * the single writer while callers cannot accidentally revise or remove
 * evidence after it has been recorded.
 */
final class DocumentScan extends Model
{
    public $timestamps = false;

    protected $table = 'document_scans';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function recordAttempt(
        Document $document,
        string $scannerName,
        string $scannerEngineVersion,
        ScanVerdict $verdict,
        array $evidence,
        ?string $checksumSha256,
        int $attempt,
    ): static {
        $scan = new self;
        $scan->forceFill([
            'document_id' => $document->getKey(),
            'scanner_name' => $scannerName,
            'scanner_engine_version' => $scannerEngineVersion,
            'verdict' => $verdict,
            'evidence' => $evidence,
            'checksum_sha256' => $checksumSha256,
            'attempt' => $attempt,
            'scanned_at' => now(),
        ]);
        $scan->save();

        return $scan;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Document scan evidence is immutable.');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Document scan evidence is immutable.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Document scan evidence is immutable.');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verdict' => ScanVerdict::class,
            'evidence' => 'array',
            'attempt' => 'integer',
            'scanned_at' => 'immutable_datetime',
        ];
    }
}
