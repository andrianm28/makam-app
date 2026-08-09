<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Exceptions\InvalidPayoutException;

/**
 * A pointer to the bank-transfer record that proves a manual payout happened.
 *
 * ---------------------------------------------------------------------------
 * A reference, and only a reference
 * ---------------------------------------------------------------------------
 * AC9 requires a payout to record "proof". This type is how it does that
 * WITHOUT the proof itself entering the financial module: two short strings
 * naming a document that `platform-document-vault` holds privately.
 *
 * `AGENTS.md` §Authorization puts payment proof in the same category as KTP
 * and death documents — stored privately, access audited. §Observability then
 * forbids restricted data reaching logs or error trackers, and a payout row is
 * read by reports, exports and admin screens. So nothing about the transfer's
 * contents — no account number, no account holder name, no bank statement
 * line, no customer identity — belongs on either field here. A caller that
 * passes one has put restricted data into a financial workflow row, and this
 * type cannot detect that; the rule is stated here so the reviewer of any call
 * site knows what to check for, the same way `Journal::postReversal()`'s
 * `$reason` and `Audit::record()`'s `$reason` carry that discipline.
 *
 * `$documentKind` is a free string rather than a member of a closed list
 * because the document-kind catalogue belongs to `platform-document-vault`,
 * which is being built in a sibling lane right now. Declaring a rival list
 * here would duplicate canonical data (`AGENTS.md` §Documentation) and collide
 * with that lane on merge. Validating it against the real catalogue is a
 * follow-up once that module lands.
 */
final readonly class PayoutProof
{
    /**
     * @param  string  $documentKind  The document-vault kind of the proof
     *                                record, e.g. a bank-transfer receipt.
     * @param  string  $documentReference  The document-vault identifier of that
     *                                     record. Never its contents.
     *
     * @throws InvalidPayoutException when either field is blank — a payout
     *                                whose proof is an empty string is a payout with no proof.
     */
    public function __construct(
        public string $documentKind,
        public string $documentReference,
    ) {
        if (trim($documentKind) === '') {
            throw InvalidPayoutException::forMissingProof('document kind');
        }

        if (trim($documentReference) === '') {
            throw InvalidPayoutException::forMissingProof('document reference');
        }
    }
}
