<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use App\Platform\DocumentVault\Models\Document;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `order_documents` — see
 * `2026_08_12_100060_create_order_documents_table.php` for the schema.
 *
 * There is no write guard here because there is nothing to guard: no
 * money-bearing column, no status that a single-writer Action must own.
 * `order_documents` rows are created once by Actions\AttachOrderDocument
 * (whose `firstOrCreate` plus the `(order_id, document_id)` unique index
 * make re-attachment idempotent) and read thereafter. Attach semantics live
 * in Actions\AttachOrderDocument; `order_documents` itself is only ever read
 * through this model.
 *
 * `attached_at` is immutable once set — an attachment's "when" is evidence,
 * not a mutable field. `document_kind` is deliberately NOT duplicated here;
 * it lives on the vault Document (`document()`), the single source of truth.
 */
final class OrderDocument extends Model
{
    use HasUuids;

    protected $table = 'order_documents';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'document_id',
        'attached_by_ref',
        'attached_by_role',
        'attached_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'attached_at' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
