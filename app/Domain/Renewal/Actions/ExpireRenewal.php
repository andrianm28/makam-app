<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * The privileged expiry path for an open renewal — an operator or admin
 * closes a `MENUNGGU_PEMBAYARAN` window without payment
 * (`RenewalStatus::KEDALUWARSA`) and records the decision in the audit
 * trail. The mirror of `MarkRenewalPaidExternally`: same `Audit::wrap()`
 * transaction, same settle guard — a renewal that already settled (or
 * already closed) must not be re-expired, and a refused attempt leaves no
 * audit trace because the whole transaction rolls back.
 *
 * `RENEWAL_EXPIRED` is deliberately NOT on `SensitiveActions::ACTIONS` —
 * it is an operator-level routine closure, not a money write, so the
 * reason is optional and only carried when the actor has one.
 */
final readonly class ExpireRenewal
{
    public function __invoke(
        Renewal $renewal,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
    ): void {
        Audit::wrap(
            mutation: function () use ($renewal): void {
                $current = Renewal::query()->lockForUpdate()->findOrFail($renewal->getKey());

                if ($current->status !== RenewalStatus::MENUNGGU_PEMBAYARAN) {
                    throw RenewalAlreadySettledException::forRenewal((string) $current->getKey());
                }

                $current->update([
                    'status' => RenewalStatus::KEDALUWARSA,
                ]);

                if ($renewal !== $current) {
                    $renewal->setRawAttributes($current->getAttributes(), true);
                }
            },
            action: 'RENEWAL_EXPIRED',
            subject: fn (): AuditSubject => new AuditSubject('renewal', (string) $renewal->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Panel,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
