<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Exceptions\DocumentAccessDeniedException;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentAccessEvent;
use App\Platform\DocumentVault\Models\SignedUrlGrant;
use App\Platform\DocumentVault\Policies\DocumentAccessPolicy;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mints one purpose-scoped, time-limited, actor-bound grant for an accepted
 * document — the module's single write API for `signed_url_grants`, and the
 * only place AC6/AC7/AC8/AC9 are decided for the read side.
 *
 * The URL itself is never stored or returned here. The caller renders it with
 * `Contracts\ObjectStorage::temporaryUrl($documentId, $token)`, so swapping a
 * real S3 presigner in later (Task 8) needs no change to this Action.
 *
 * ---------------------------------------------------------------------------
 * Two guards, one refusal (AC7 + AC9)
 * ---------------------------------------------------------------------------
 * 1. `DocumentAccessPolicy::canView()` — role AND record relationship.
 * 2. `state === ACCEPTED` — fail-closed: a document that has not passed a
 *    clean scan has no signed URL, and neither does a rejected, expired or
 *    logically deleted one.
 *
 * All three refusal reasons (not permitted / not accepted / no such document)
 * raise the SAME `DocumentAccessDeniedException` with the same message, so a
 * caller cannot distinguish them — AC9's "no existence leak". The distinction
 * is recorded where it belongs: a `document_access_events` row and an audit
 * row, both access-controlled. A non-existent document can only produce the
 * audit row, because `document_access_events.document_id` is a real foreign
 * key with `restrictOnDelete` and there is no row to point at; that asymmetry
 * is invisible to the refused caller.
 *
 * `DOCUMENT_ACCESS_GRANT`/`DOCUMENT_ACCESS_DENIED` are deliberately NOT on
 * `SensitiveActions::ACTIONS` (the plan's Global Constraints grow that list by
 * `DOCUMENT_DELETE` only in this lane), so neither requires a `$reason`.
 *
 * ---------------------------------------------------------------------------
 * Why the issuance access event uses `DocumentAccessPurpose::Grant`
 * ---------------------------------------------------------------------------
 * The issuance and the later redemption of the same URL are two distinct
 * events an auditor must be able to tell apart ("a URL was handed out" vs
 * "the bytes were served"). Recording both with the requested purpose would
 * make them indistinguishable rows, so issuance records `GRANT` and carries
 * the REQUESTED purpose in the audit row's `metadata['purpose']`;
 * `RecordDocumentAccess` records the requested purpose for the real access.
 * This is also what gives the `GRANT` value in the migration's purpose CHECK
 * list a meaning.
 *
 * ---------------------------------------------------------------------------
 * What Task 7's redemption path MUST still do — this Action cannot
 * ---------------------------------------------------------------------------
 * A grant is a snapshot of an authorization decision taken up to 300 s
 * earlier. Issuance deliberately takes no row lock and makes no promise about
 * the document's state at redemption time, because everything below has to be
 * re-evaluated against the redeeming request anyway:
 *
 *  - re-run `DocumentAccessPolicy::canView()` for the REQUESTING actor, so a
 *    scope assignment revoked after issuance invalidates the outstanding
 *    grant;
 *  - require `signed_url_grants.actor_ref` to equal the requesting actor, and
 *    treat a NULL `actor_ref` as non-redeemable (see
 *    `2026_08_10_100020_add_signed_url_grant_actor_binding.php`);
 *  - re-check `state === ACCEPTED` — a document may have been rejected,
 *    expired or logically deleted since issuance;
 *  - require the grant's `purpose` to equal the purpose being exercised
 *    (cross-purpose redemption is refused);
 *  - honour `expires_at` and single use via `consumed_at`;
 *  - call `RecordDocumentAccess` on BOTH the served and the refused path;
 *  - map `DocumentAccessDeniedException` to 404, never 403.
 */
final readonly class IssueSignedUrl
{
    /**
     * AC6's hard ceiling. Deceased-document grants get no exemption and no
     * configuration knob — this is a constant, not a config value, so no
     * environment can widen it. PostgreSQL enforces the same ceiling
     * independently (`signed_url_grants_expires_at_check`).
     */
    public const int MAX_TTL_SECONDS = 300;

    public function __construct(
        private DocumentAccessPolicy $policy,
    ) {}

    /**
     * Resolve a document by id and issue against it. This is the entry point a
     * route uses, and the reason it exists: doing the lookup INSIDE the Action
     * is what makes "document does not exist" and "actor may not see it"
     * structurally indistinguishable, instead of leaving each caller to
     * remember to translate a missing row into the same refusal.
     */
    public function issueForDocumentId(
        ActorContext $actor,
        string $documentId,
        DocumentAccessPurpose $purpose,
        ?int $ttlSeconds = null,
        ?string $ipAddress = null,
    ): SignedUrlGrant {
        $document = Document::query()->find($documentId);

        if (! $document instanceof Document) {
            Audit::record(
                action: 'DOCUMENT_ACCESS_DENIED',
                subject: new AuditSubject('document', $documentId),
                outcome: AuditOutcome::Denied,
                actorRef: $actor->identityReference,
                actorRole: DocumentAccessPolicy::auditRoleFor($actor),
                source: AuditSource::Api,
                metadata: ['purpose' => $purpose->value],
            );

            throw DocumentAccessDeniedException::denied();
        }

        return $this->issue($actor, $document, $purpose, $ttlSeconds, $ipAddress);
    }

    /**
     * @param  int|null  $ttlSeconds  Requested lifetime. The effective expiry
     *                                is `min($ttlSeconds, self::MAX_TTL_SECONDS)`, so a caller may ask
     *                                for LESS than 300 s but never more. Null means the maximum.
     *
     * @throws DocumentAccessDeniedException when the policy refuses or the
     *                                       document is not `ACCEPTED`.
     * @throws InvalidArgumentException when a non-positive lifetime is asked
     *                                  for — a zero/negative expiry is a caller bug, not a denial, and
     *                                  must not be silently rounded up to the maximum.
     */
    public function issue(
        ActorContext $actor,
        Document $document,
        DocumentAccessPurpose $purpose,
        ?int $ttlSeconds = null,
        ?string $ipAddress = null,
    ): SignedUrlGrant {
        if ($ttlSeconds !== null && $ttlSeconds < 1) {
            throw new InvalidArgumentException('A signed URL lifetime must be at least one second.');
        }

        if (! $this->policy->canView($actor, $document)) {
            $this->refuse($actor, $document, $purpose, $ipAddress);
        }

        if ($document->state !== DocumentState::Accepted) {
            $this->refuse($actor, $document, $purpose, $ipAddress);
        }

        $lifetimeSeconds = min($ttlSeconds ?? self::MAX_TTL_SECONDS, self::MAX_TTL_SECONDS);
        $issuedAt = CarbonImmutable::now();
        $actorRef = $actor->identityReference;

        // Unreachable in practice — the policy already refused every
        // unauthenticated actor — but the grant's actor binding is only
        // meaningful if it can never be null, so this is asserted rather than
        // assumed.
        if ($actorRef === null) {
            throw DocumentAccessDeniedException::denied();
        }

        return DB::transaction(function () use (
            $actor,
            $actorRef,
            $document,
            $purpose,
            $ipAddress,
            $issuedAt,
            $lifetimeSeconds,
        ): SignedUrlGrant {
            $grant = SignedUrlGrant::issueGrant(
                document: $document,
                actorRef: $actorRef,
                purpose: $purpose,
                // 256 bits of CSPRNG entropy, rendered hex. Never derived
                // from the document id, the actor, or the clock.
                token: bin2hex(random_bytes(32)),
                issuedAt: $issuedAt,
                expiresAt: $issuedAt->addSeconds($lifetimeSeconds),
            );

            DocumentAccessEvent::recordAccess(
                document: $document,
                actorRef: $actorRef,
                actorRole: DocumentAccessPolicy::auditRoleFor($actor),
                purpose: DocumentAccessPurpose::Grant,
                outcome: AuditOutcome::Allowed,
                ipAddress: $ipAddress,
            );

            // AC8: actor, purpose, record, timestamp, outcome. The token is
            // deliberately absent — it is a bearer credential, and
            // `audit_events` is read by more people than may redeem it.
            Audit::record(
                action: 'DOCUMENT_ACCESS_GRANT',
                subject: new AuditSubject('document', $document->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: DocumentAccessPolicy::auditRoleFor($actor),
                source: AuditSource::Api,
                metadata: ['purpose' => $purpose->value],
            );

            return $grant;
        });
    }

    /**
     * Record the denial, then raise the one canonical refusal.
     *
     * @throws DocumentAccessDeniedException always.
     */
    private function refuse(
        ActorContext $actor,
        Document $document,
        DocumentAccessPurpose $purpose,
        ?string $ipAddress,
    ): never {
        DB::transaction(function () use ($actor, $document, $purpose, $ipAddress): void {
            DocumentAccessEvent::recordAccess(
                document: $document,
                actorRef: $actor->identityReference,
                actorRole: DocumentAccessPolicy::auditRoleFor($actor),
                purpose: DocumentAccessPurpose::Grant,
                outcome: AuditOutcome::Denied,
                ipAddress: $ipAddress,
            );

            Audit::record(
                action: 'DOCUMENT_ACCESS_DENIED',
                subject: new AuditSubject('document', $document->getKey()),
                outcome: AuditOutcome::Denied,
                actorRef: $actor->identityReference,
                actorRole: DocumentAccessPolicy::auditRoleFor($actor),
                source: AuditSource::Api,
                metadata: ['purpose' => $purpose->value],
            );
        });

        throw DocumentAccessDeniedException::denied();
    }
}
