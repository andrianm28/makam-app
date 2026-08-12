<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * The actor holds no finance or restricted-admin authority for a
 * money-moving payment action (recording a reversal, deciding a manual
 * payment verification).
 *
 * `docs/security/rbac-matrix.md` puts "Payout/refund, incl. manual payment
 * verification" at `No` for every role except a restricted admin and a
 * dedicated finance role — that row was extended to name manual payment
 * verification by the 12 Aug 2026 ruling, so it now covers both of the
 * actions above rather than only the reversal half. The check that
 * raises this is deliberately fail-closed: no role grant means no
 * authorisation, never "unrestricted until someone configures it" — see
 * `App\Platform\Payment\FinanceOrRestrictedAdminPaymentAuthorizer` for the
 * mechanism.
 *
 * Structural twin of
 * `FinancialLedger\Exceptions\PayoutNotAuthorisedException`: a plain
 * `RuntimeException` with NO `render()`. This application has no
 * framework-level exception-to-HTTP mapping; the HTTP status is chosen by
 * the caller, which for both payment routes means the controller catching
 * this type and calling `abort(403)` — the convention
 * `app/Http/Controllers/Admin/FinanceExportController.php` already
 * established for `LedgerReadNotAuthorisedException`. Adding a `render()`
 * here would put that decision in two places.
 *
 * ---------------------------------------------------------------------------
 * One identical refusal for every way of failing
 * ---------------------------------------------------------------------------
 * There is exactly one named constructor, and it names nothing beyond the
 * actor reference — no amount, no reference, no verification id, no role
 * list. A caller with no identity, a caller with no roles, and a caller with
 * a real-but-unauthorised role are indistinguishable to the actor who
 * receives the refusal. That matters most for the verification route, whose
 * `{paymentVerification}` segment is a real record id: because the
 * authorization check is record-independent it can run BEFORE the lookup, so
 * an unauthorised caller gets this refusal for a nonexistent id and a real
 * id alike, and the endpoint is not an existence oracle over verification
 * UUIDs. Same defence the ledger module's manual payout Action documents,
 * reached more cheaply here.
 */
final class PaymentActionNotAuthorisedException extends RuntimeException
{
    /**
     * `$actorReference` is `null` for an actor with no resolved identity.
     * Stringified into the message because the reference itself is already
     * in the audit trail for these routes; nothing else about the actor is.
     */
    public static function forActorContext(int|string|null $actorReference = null): self
    {
        $reference = $actorReference === null ? 'unauthenticated' : (string) $actorReference;

        return new self(
            "Actor [{$reference}] holds no explicit finance or restricted-admin "
            .'authority for this payment action.'
        );
    }
}
