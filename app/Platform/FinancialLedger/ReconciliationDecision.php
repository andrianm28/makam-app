<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use InvalidArgumentException;

/**
 * The closed list of `reconciliation_exceptions.decision` values (AC10, AC12) —
 * what an authorised human concluded about a difference between the journal and
 * a provider statement.
 *
 * ---------------------------------------------------------------------------
 * `POST_CORRECTION` does NOT mean "edit the ledger"
 * ---------------------------------------------------------------------------
 * This is the single most important thing about this list, and it is the
 * misreading this whole module is written to make unexpressible. A correction
 * is a NEW batch posted through `Contracts\Journal` — a reversing batch via
 * `postReversal()`, or an adjusting batch via `post()`, both carried by
 * `ReconciliationCorrection`. `journal_batches` and `journal_entries` take zero
 * `UPDATE` and zero `DELETE`, ever; Task 6 revokes both from the application
 * role at the database level.
 *
 * A reader who takes `post_correction` to mean "go and fix the wrong row" has
 * inverted AC14's forward-only correction model. The name says POST, not
 * amend, precisely because the corrective act is a posting.
 */
final class ReconciliationDecision
{
    /**
     * The journal is wrong and a corrective batch is posted forward — never an
     * edit of what is already there. See the class doc block.
     */
    public const string POST_CORRECTION = 'post_correction';

    /**
     * The difference is real, understood, and accepted as-is. Nothing is
     * posted. The recorded reason is the whole substance of this decision,
     * which is why `RECONCILIATION_EXCEPTION_RESOLVED` is a sensitive action.
     */
    public const string ACCEPT_VARIANCE = 'accept_variance';

    /**
     * The difference is not resolvable at this level and has been handed to
     * someone who can resolve it. The exception is closed HERE with a reason
     * naming where it went; it is not left open pretending to be actionable.
     */
    public const string ESCALATE = 'escalate';

    /**
     * @var list<string>
     */
    public const array KNOWN_DECISIONS = [
        self::POST_CORRECTION,
        self::ACCEPT_VARIANCE,
        self::ESCALATE,
    ];

    public static function isKnown(string $decision): bool
    {
        return in_array($decision, self::KNOWN_DECISIONS, true);
    }

    /**
     * @throws InvalidArgumentException when `$decision` is not one of
     *                                  `self::KNOWN_DECISIONS`.
     */
    public static function assertKnown(string $decision): void
    {
        if (! self::isKnown($decision)) {
            throw new InvalidArgumentException(
                "Unknown reconciliation decision [{$decision}]. Known decisions: "
                .implode(', ', self::KNOWN_DECISIONS).'.'
            );
        }
    }
}
