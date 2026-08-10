<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Models;

use App\Platform\FinancialLedger\ReconciliationDecision;
use App\Platform\FinancialLedger\ReconciliationExceptionStatus;
use App\Platform\FinancialLedger\ReconciliationExceptionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One difference between the journal and a provider statement, and the record
 * of who decided what about it.
 *
 * Written only by `App\Platform\FinancialLedger\Actions\RunReconciliation`
 * (which creates it `open`) and `Actions\ResolveException` (which is the ONLY
 * writer that ever sets it `resolved`). `$guarded = ['*']` follows the module's
 * other models, so no request payload can mass-assign a decision or a decider
 * onto it.
 *
 * ---------------------------------------------------------------------------
 * No `resolve()` method on this model, deliberately
 * ---------------------------------------------------------------------------
 * A model method that flips the status would be reachable from anywhere and
 * would carry none of AC12's requirements with it — the finance-scoped
 * authorization, the mandatory reason, the `RECONCILIATION_EXCEPTION_RESOLVED`
 * audit event, and the transactional pairing with a `post_correction` batch all
 * live in `Actions\ResolveException` and are the whole substance of a
 * resolution. Exactly the relationship `Models\AuditEvent` has with
 * `Audit::record()` and `Models\JournalBatch` has with `Journal::post()`.
 *
 * The amounts are integer minor units (AC11), and either may be NULL — NULL
 * means nothing was observed on that side, never that a zero was observed. See
 * the migration's own doc block.
 */
final class ReconciliationException extends Model
{
    protected $table = 'reconciliation_exceptions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Reconciliation, $this>
     */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class, 'reconciliation_id');
    }

    public function isOpen(): bool
    {
        return $this->status === ReconciliationExceptionStatus::OPEN;
    }

    /**
     * A resolution is only real if a human is attached to it. Deliberately not
     * `status === 'resolved'` alone: the three columns are written together by
     * the one Action that may write them, and the PostgreSQL CHECKs make that
     * an invariant, so reading all three back is what proves the invariant
     * survived rather than assuming it.
     */
    public function isResolved(): bool
    {
        return $this->status === ReconciliationExceptionStatus::RESOLVED
            && $this->decided_by !== null
            && $this->decided_at !== null
            && $this->decision !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journal_amount_minor' => 'integer',
            'statement_amount_minor' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $exception): void {
            ReconciliationExceptionType::assertKnown((string) $exception->type);
            ReconciliationExceptionStatus::assertKnown((string) $exception->status);

            if ($exception->decision !== null) {
                ReconciliationDecision::assertKnown((string) $exception->decision);
            }
        });
    }
}
