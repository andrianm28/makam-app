<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<JournalBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(JournalBatch::class, 'batch_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }
}
