<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Throwable;

/**
 * Writes the one `AuditOutcome::Denied` row that a refused money-moving admin
 * payment action leaves behind, immediately before the 403.
 *
 * Both admin controllers used to `catch (PaymentActionNotAuthorisedException)
 * { abort(403); }` with a non-capturing catch, so a refused attempt left no
 * audit row, no log line and no metric. Neither route carries a rate limit
 * either, so probing the two highest-value admin endpoints in the application
 * was unlimited AND invisible. This closes the second half of that: an
 * authorization control whose refusals are not observable cannot be monitored.
 *
 * Precedent followed: `DocumentVault\Actions\IssueSignedUrl` writes a `Denied`
 * row on every policy refusal, and `GuardPaymentSession` writes
 * `PaymentAuditActions::GUARD_DENIED` with `AuditOutcome::Denied` on every
 * guard denial. `FinancialLedger`'s Actions do not, which is why the reviewer
 * called this a SHOULD-FIX rather than a hole; between the two precedents the
 * `IssueSignedUrl` one is the right fit for an admin money-moving endpoint.
 *
 * ---------------------------------------------------------------------------
 * One shared writer, not a copy in each controller
 * ---------------------------------------------------------------------------
 * The two controllers refuse for identical reasons and must leave identical
 * evidence. Duplicating the write would let the two drift — one gaining a
 * field, a metadata key or a different outcome the other never got — and the
 * whole value of this row is that a reviewer can count refusals across both
 * endpoints with one query.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately NOT recorded
 * ---------------------------------------------------------------------------
 * No request payload, and no metadata at all. `AGENTS.md` §Observability
 * forbids restricted data in any audit payload, and this write happens BEFORE
 * `$request->validate()` has run — every field the caller sent is unvalidated,
 * unbounded free text at this point. In particular:
 *
 * - the reversal `reference` and the reason the caller typed are theirs, not
 *   the system's, and neither is evidence of anything;
 * - the `{paymentVerification}` route segment is caller-chosen and is exactly
 *   the value `IssueSignedUrl` refuses to write for a malformed id, because
 *   `audit_events.subject_id` is a bounded string column and an over-length
 *   attacker-supplied id would raise a `QueryException` on PostgreSQL — the
 *   500 this guard exists to avoid;
 * - `MetadataAllowlist::ALLOWED_KEYS` gains no key here. Nothing needs one.
 *
 * The subject is therefore the ACTION that was refused, named by the calling
 * controller's own server-side `REASON` constant (`payment_reversal` /
 * `payment_manual_verification`), so an operator can still tell the two
 * endpoints apart. The actor reference is the only caller-derived value
 * written, it is server-resolved rather than request-supplied, and it is
 * already in the audit trail for the allowed path.
 *
 * ---------------------------------------------------------------------------
 * A failed audit write must never turn a 403 into a 500
 * ---------------------------------------------------------------------------
 * Ordering: this runs inside the `catch` block, before `abort(403)`, so a
 * refusal is always recorded before it is answered — the row cannot be lost to
 * a later failure on the response path.
 *
 * But the write is best-effort by design, and a `Throwable` from it is
 * reported and swallowed rather than propagated. Two reasons, and neither is
 * convenience:
 *
 * 1. There is no state change to protect. `Audit::wrap()` exists so a mutation
 *    and its audit row commit together; a refusal mutates nothing, so there is
 *    nothing for an audit failure to leave half-done. Rolling the refusal back
 *    is not a coherent option — the actor is still refused.
 * 2. Letting it propagate would make the response distinguishable. An audit
 *    outage would turn every refusal into a 500, which is both a worse answer
 *    (the caller learns the request failed for a server reason rather than an
 *    authority one) and a change in the flat-403 property the existence-oracle
 *    defence depends on. A monitoring improvement must not become a way to
 *    move the authorization surface.
 *
 * `report()` sends the failure to the application's error handler, so the
 * failure itself is not silent. The exception carries this class's own
 * parameters — an action name, a closed-list source, the fixed reason below,
 * and the server-resolved actor reference — so reporting it puts no restricted
 * data anywhere.
 */
final class RecordPaymentActionRefusal
{
    /**
     * Fixed, server-side, and never the caller's text. This runs before
     * validation, so the request body is unvalidated; and the caller is the
     * party being refused, so their words are not evidence about the refusal.
     * `ADMIN_ACTION_DENIED` is not on `SensitiveActions::ACTIONS`, so no
     * reason is required at all — one is supplied anyway because the row is
     * read by a human, and "empty" would be less honest than "the actor held
     * no authority".
     */
    private const string REASON = 'Refused: the actor holds no finance or restricted-admin authority for this payment action.';

    /**
     * `authenticated_actor` is the audit sentinel meaning "no role applies",
     * which is precisely true of a refused actor: the authorizer just
     * established that they hold neither authorized role. It is deliberately
     * NOT declared on `Roles\ActorRole` — that class's doc block records why
     * (a grantable role the policy layer reads as the absence of a role would
     * be a privilege-escalation-shaped inconsistency), so this is a literal
     * here exactly as it is in `Http\Middleware\RequireRecentAuthentication`
     * and `DocumentVault\Policies\DocumentAccessPolicy`, including that
     * middleware's `guest` fallback for an actor with no resolved identity.
     *
     * This is the ONE place in this module the sentinel is still written, and
     * it is the one place where it is correct: the allowed paths were changed
     * by this same hotfix to record the real role the authorizer approved.
     */
    public function record(ActorContext $actor, string $paymentAction): void
    {
        try {
            Audit::record(
                action: PaymentAuditActions::ADMIN_ACTION_DENIED,
                subject: new AuditSubject('payment_admin_action', $paymentAction),
                outcome: AuditOutcome::Denied,
                actorRef: $actor->identityReference,
                actorRole: $actor->isAuthenticated() ? 'authenticated_actor' : 'guest',
                source: AuditSource::Panel,
                reason: self::REASON,
            );
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
