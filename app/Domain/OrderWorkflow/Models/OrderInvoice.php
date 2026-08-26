<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `order_invoices` — see
 * `2026_08_26_100000_create_order_invoices_table.php` for the schema and
 * `Actions\IssueInvoice` for the single writer.
 *
 * No write guard here — same reasoning `OrderDocument`'s own doc block
 * gives: there is no money-bearing column that can be re-written after the
 * fact and no status this model itself owns. A row is created once by
 * `Actions\IssueInvoice` (whose `order_invoices_order_id_unq` unique index
 * makes re-issuance for the same order idempotent) and read thereafter.
 */
final class OrderInvoice extends Model
{
    use HasUuids;

    protected $table = 'order_invoices';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'reference',
        'amount_minor',
        'currency',
        'summary',
        'issued_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'issued_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
