<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Actions;

use App\Domain\AgreementCertificate\AgreementCertificateAuditActions;
use App\Domain\AgreementCertificate\Models\Agreement;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Task 1 — AC2's acceptance binding: draft -> accepted, stamping the
 * acceptor, the accepted quote, and the EXACT agreement version row on
 * the agreement, audited `AGREEMENT_ACCEPTED` and emitting the
 * catalogued `agreement.accepted.v1` in the same transaction as the
 * mutation.
 *
 * The `$agreementVersionId` parameter exists precisely so the caller
 * names the version being accepted; if it does not match the row itself,
 * acceptance is refused BEFORE anything is written — "bound to the
 * actor and the exact document version" is enforced at both layers (the
 * action's check and `Agreement::accept()`'s stamp).
 *
 * The audit row records `ActorRole::CUSTOMER` as the actor role: the
 * pinned signature carries a single actor without a role, and the party
 * accepting a contract is the customer. Lane 2's pre-need acceptance
 * (admin-invoked on the customer's behalf) reuses this same signature
 * and documents the same choice.
 */
final readonly class AcceptAgreement
{
    public function __invoke(
        Agreement $agreement,
        string $actorRef,
        ?string $quoteId,
        ?string $agreementVersionId,
        AuditSource $auditSource = AuditSource::Panel,
    ): Agreement {
        if ($agreementVersionId !== (string) $agreement->getKey()) {
            throw new InvalidArgumentException(
                "The agreement version to accept must be the exact version row [{$agreement->getKey()}] being accepted (AC2)."
            );
        }

        return Audit::wrap(
            mutation: function () use ($agreement, $actorRef, $quoteId): Agreement {
                $current = Agreement::query()->lockForUpdate()->findOrFail($agreement->getKey());

                $current->accept(CarbonImmutable::now(), $actorRef, $quoteId);

                // Same precedent as `AcceptQuote` / `RecordOrderStatusChange`:
                // sync the caller's own instance to the persisted row.
                if ($agreement !== $current) {
                    $agreement->setRawAttributes($current->getAttributes(), true);
                }

                $this->emitAccepted($current);

                return $agreement;
            },
            action: AgreementCertificateAuditActions::AGREEMENT_ACCEPTED,
            subject: fn (Agreement $accepted): AuditSubject => new AuditSubject(
                'agreement',
                $accepted->getKey(),
                $accepted->version_number,
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: ActorRole::CUSTOMER,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    /**
     * `event-catalog.md:21` — `agreement.accepted.v1`, "Exact version and
     * evidence". The payload carries the exact versions the acceptance
     * bound: the agreement row, its version number, and the accepted
     * quote reference — never restricted content.
     */
    private function emitAccepted(Agreement $agreement): void
    {
        Outbox::record(
            eventName: 'agreement.accepted.v1',
            eventVersion: 1,
            aggregateType: 'agreement',
            aggregateId: $agreement->getKey(),
            data: [
                'agreement_id' => $agreement->getKey(),
                'version_number' => $agreement->version_number,
                'quote_id' => $agreement->accepted_quote_id,
                'accepted_by_ref' => $agreement->accepted_by_ref,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "agreement_accepted:{$agreement->getKey()}",
        );
    }
}
