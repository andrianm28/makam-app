<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Models;

use App\Platform\FinancialLedger\ReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a reconciliation run concluded for one badan usaha and one period.
 *
 * Written only by `App\Platform\FinancialLedger\Actions\RunReconciliation`.
 * `$guarded = ['*']` follows the module's other models, so no request payload
 * can mass-assign a status or a statement total onto it.
 *
 * ---------------------------------------------------------------------------
 * There is deliberately no `close()`, `rollOver()` or `complete()` here
 * ---------------------------------------------------------------------------
 * AC10 is explicit that no exception resolves by period closure: closing,
 * ending or rolling over a period must never flip an `open` exception to
 * `resolved` as a side effect. The most reliable way to guarantee that is for
 * the vocabulary not to exist — this model exposes no lifecycle verb at all,
 * and `Actions\ResolveException` is the only writer in the codebase that sets
 * an exception's status to `resolved`.
 * `tests/Feature/FinancialLedger/ResolveReconciliationExceptionTest.php`
 * asserts that structurally, over the whole module tree, so a future
 * "close the period" helper cannot quietly acquire the power.
 */
final class Reconciliation extends Model
{
    protected $table = 'reconciliations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    /**
     * @return HasMany<ReconciliationException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(ReconciliationException::class, 'reconciliation_id');
    }

    /**
     * The statement could not be fetched. Not a completed period, not a
     * partial one, and NOT a period whose statement totalled zero — see
     * `ReconciliationStatus`.
     */
    public function isStatementMissing(): bool
    {
        return $this->status === ReconciliationStatus::STATEMENT_MISSING;
    }

    /**
     * True when at least one finding for this period still awaits a decision.
     * Derived from the status the run wrote, which the run itself derives by
     * counting open exception rows.
     */
    public function hasOpenExceptions(): bool
    {
        return $this->status === ReconciliationStatus::EXCEPTIONS_OPEN;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'statement_total_minor' => 'integer',
            'journal_total_minor' => 'integer',
            'ran_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $reconciliation): void {
            ReconciliationStatus::assertKnown((string) $reconciliation->status);
        });
    }
}
