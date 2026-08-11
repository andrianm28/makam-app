<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * The batch this one reverses, if it is itself a reversal.
     *
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_batch_id');
    }

    /**
     * The batch that reverses this one, if any. `HasOne` and not `HasMany`
     * because `reverses_batch_id` carries a UNIQUE index — at most one
     * reversal per batch is a database invariant, not a convention. See
     * `App\Platform\FinancialLedger\Journal::postReversal()`.
     *
     * @return HasOne<self, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_batch_id');
    }

    /**
     * Ruling 1 (Wave 1b, user-approved): a batch is reversed if and only if a
     * batch exists whose `reverses_batch_id` points at it. This is DERIVED by
     * lookup and is deliberately not a stored attribute, not a `status` value,
     * and not anything an `UPDATE` ever writes — `journal_batches` is
     * append-only (AC2/AC14) and Task 6 revokes UPDATE/DELETE on it from the
     * application role outright.
     *
     * `status` keeps the `posted` it was inserted with, forever.
     */
    public function isReversed(): bool
    {
        if ($this->relationLoaded('reversedBy')) {
            return $this->getRelation('reversedBy') !== null;
        }

        return $this->reversedBy()->exists();
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
