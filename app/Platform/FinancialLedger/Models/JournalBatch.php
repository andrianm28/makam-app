<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class JournalBatch extends Model
{
    protected $table = 'journal_batches';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'batch_id');
    }

    public function isBalanced(): bool
    {
        if ($this->relationLoaded('entries')) {
            return $this->entries->sum(
                fn (JournalEntry $entry): int => $entry->direction === 'DR'
                    ? $entry->amount_minor
                    : -$entry->amount_minor
            ) === 0;
        }

        $balance = $this->entries()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN direction = 'DR' THEN amount_minor ELSE -amount_minor END), 0) AS balance"
            )
            ->value('balance');

        return (int) $balance === 0;
    }

    /**
     * Return the debit-side total. A balanced batch has the same credit total.
     */
    public function total(): int
    {
        if ($this->relationLoaded('entries')) {
            return $this->entries
                ->where('direction', 'DR')
                ->sum(fn (JournalEntry $entry): int => $entry->amount_minor);
        }

        return (int) $this->entries()->where('direction', 'DR')->sum('amount_minor');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
