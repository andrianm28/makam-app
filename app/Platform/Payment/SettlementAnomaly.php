<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Audit\AuditSubject;

/**
 * Batch M1b (PAY-02, PAY-03) — the "record-and-return-an-outcome" shape
 * `ProcessWebhookEvent::auditSettlementConflict()` already established for a
 * sibling case (a settlement conflict), generalised so a settlement Action
 * can report an anomaly WITHOUT throwing.
 *
 * Throwing propagates out of `ProcessWebhookEvent`'s claim transaction and
 * rolls it back — the right behaviour for a TRANSIENT failure (the queue
 * retry re-claims the row), but the wrong one for a PERMANENT, well-
 * understood anomaly (a settlement amount that will never match, a target
 * that will never become open again): rolling back erases any audit row
 * written alongside it, which is exactly the PAY-03 finding
 * (`App\Domain\Renewal\Actions\MarkRenewalPaidOnline`'s own doc block traces
 * the mechanism in detail).
 *
 * Returning this value instead means the anomaly is handled INSIDE the
 * transaction that is actually going to commit: `ProcessWebhookEvent` moves
 * the `provider_events` row to `MANUAL_REVIEW` with `rejectionDetail` and
 * writes one `Audit::record()` call with `auditAction`/`note`/`subject` —
 * all before that same transaction commits.
 */
final readonly class SettlementAnomaly
{
    /**
     * @param  string  $auditAction  One of the closed-list audit action
     *                               constants (e.g.
     *                               `App\Domain\Renewal\RenewalAuditActions::
     *                               RENEWAL_PAID_ONLINE_REFUSED`).
     * @param  string  $note  A closed-list, human-readable explanation —
     *                        never a raw exception message and never a
     *                        provider payload value (AC14).
     * @param  string  $rejectionDetail  Written to
     *                                   `provider_events.rejection_detail`
     *                                   (bounded to 191 chars by
     *                                   `ProviderEvent::markStatus()`); same
     *                                   closed-list discipline as `$note`.
     * @param  AuditSubject|null  $subject  The audit row's subject. Defaults
     *                                      to the `provider_events` row
     *                                      itself when omitted (the same
     *                                      subject `auditSettlementConflict()`
     *                                      uses); a caller that knows the
     *                                      real domain target (e.g. the
     *                                      `Renewal`) supplies it so an
     *                                      operator can jump straight there.
     */
    public function __construct(
        public string $auditAction,
        public string $note,
        public string $rejectionDetail,
        public ?AuditSubject $subject = null,
    ) {}
}
