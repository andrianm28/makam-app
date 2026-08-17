<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Actions;

use App\Domain\PreNeed\Models\PreNeedConsultationRequest;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * Task 5 — the ONLY writer of `pre_need_consultation_requests`, and the
 * complement of `RegisterPreNeedInterest` on the public /preneed surface:
 * interest REGISTRATION records *that* a visitor is interested, this
 * records *what they want to consult about*. The row and its
 * `PRENEED_CONSULTATION_REQUESTED` audit pair commit in one
 * `Audit::wrap` transaction (AC4 — a committed row can never exist
 * without its audit event).
 *
 * ---------------------------------------------------------------------------
 * G-LEGAL-01 discipline: the consultation is NEVER gated
 * ---------------------------------------------------------------------------
 * This Action does not resolve `ModeResolver::preNeedMode()` at all — not
 * to refuse, and not to record. The consultation creates no financial
 * object and no agreement; `G-LEGAL-01` governs PAID Pre-Need plot
 * purchase, and the §6.9 fallback surface ("registers interest; no payment
 * created") is exactly as available while the gate is closed as while it
 * is open. design-system.md §6.9 Negative criteria require the
 * interest/consultation entry point to be NEVER removed regardless of the
 * gate, and `RequestPreNeedConsultationTest::
 * test_the_consultation_is_gate_independent_in_both_modes` pins it:
 * interest flows work identically with the gate open or shut, and the
 * consultation carries no `gate_mode` column to diverge on.
 *
 * `$preNeedInterestId` is the optional linkage to the interest row a
 * visitor may have registered on the same visit (`null` when they file a
 * consultation standalone).
 */
final readonly class RequestPreNeedConsultation
{
    public function __invoke(
        string $name,
        string $contact,
        string $message,
        ?string $preNeedInterestId = null,
        int|string|null $actorRef = null,
        string $actorRole = 'guest',
        AuditSource $auditSource = AuditSource::Api,
    ): PreNeedConsultationRequest {
        return Audit::wrap(
            mutation: fn (): PreNeedConsultationRequest => PreNeedConsultationRequest::query()->create([
                'name' => $name,
                'contact' => $contact,
                'message' => $message,
                'pre_need_interest_id' => $preNeedInterestId,
            ]),
            action: PreNeedAuditActions::PRENEED_CONSULTATION_REQUESTED,
            subject: fn (PreNeedConsultationRequest $request): AuditSubject => new AuditSubject(
                'pre_need_consultation_request',
                (string) $request->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
