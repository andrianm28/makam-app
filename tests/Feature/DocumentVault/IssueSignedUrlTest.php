<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\MetadataAllowlist;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use App\Platform\DocumentVault\Actions\IssueSignedUrl;
use App\Platform\DocumentVault\Actions\RecordDocumentAccess;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Exceptions\DocumentAccessDeniedException;
use App\Platform\DocumentVault\Exceptions\SignedUrlGrantImmutableException;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Models\DocumentAccessEvent;
use App\Platform\DocumentVault\Models\SignedUrlGrant;
use App\Platform\DocumentVault\Models\SignedUrlGrantQueryBuilder;
use App\Platform\DocumentVault\Policies\DocumentAccessPolicy;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AC6 (≤ 300 s, single purpose), AC7 (no URL before ACCEPTED), AC8 (every
 * issuance audited with actor/purpose/record/timestamp/outcome) and AC9
 * (role AND relationship, no existence leak).
 */
final class IssueSignedUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_url_is_issued_before_a_document_is_accepted(): void
    {
        foreach ([
            DocumentState::Uploading,
            DocumentState::Quarantined,
            DocumentState::Scanning,
            DocumentState::Rejected,
            DocumentState::Expired,
            DocumentState::Deleted,
        ] as $state) {
            $document = $this->relatedDocument(state: $state);

            try {
                $this->action()->issue($this->relatedActor(), $document, DocumentAccessPurpose::Download);
                $this->fail("State {$state->value} must not yield a signed URL.");
            } catch (DocumentAccessDeniedException) {
                // expected
            }
        }

        $this->assertSame(0, SignedUrlGrant::query()->count());
        $this->assertSame(6, DocumentAccessEvent::query()->where('outcome', AuditOutcome::Denied->value)->count());
        $this->assertSame(6, AuditEvent::query()->where('action', 'DOCUMENT_ACCESS_DENIED')->count());
    }

    public function test_an_accepted_document_yields_a_single_purpose_grant_capped_at_three_hundred_seconds(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00'));

        $document = $this->relatedDocument();

        $grant = $this->action()->issue($this->relatedActor(), $document, DocumentAccessPurpose::Download);

        $this->assertSame(DocumentAccessPurpose::Download, $grant->purpose);
        $this->assertSame($document->getKey(), $grant->document_id);
        $this->assertSame(300, (int) $grant->created_at->diffInSeconds($grant->expires_at));
        $this->assertNull($grant->consumed_at);
        $this->assertNotSame('', (string) $grant->token);

        CarbonImmutable::setTestNow();
    }

    public function test_a_requested_lifetime_longer_than_three_hundred_seconds_is_clamped(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00'));

        $grant = $this->action()->issue(
            $this->relatedActor(),
            $this->relatedDocument(),
            DocumentAccessPurpose::View,
            ttlSeconds: 86400,
        );

        $this->assertSame(IssueSignedUrl::MAX_TTL_SECONDS, 300);
        $this->assertSame(300, (int) $grant->created_at->diffInSeconds($grant->expires_at));

        CarbonImmutable::setTestNow();
    }

    public function test_a_shorter_requested_lifetime_is_honoured_and_a_non_positive_one_is_rejected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00'));

        $grant = $this->action()->issue(
            $this->relatedActor(),
            $this->relatedDocument(),
            DocumentAccessPurpose::View,
            ttlSeconds: 60,
        );

        $this->assertSame(60, (int) $grant->created_at->diffInSeconds($grant->expires_at));

        $this->expectException(InvalidArgumentException::class);

        $this->action()->issue(
            $this->relatedActor(),
            $this->relatedDocument(),
            DocumentAccessPurpose::View,
            ttlSeconds: 0,
        );
    }

    public function test_each_issuance_is_scoped_to_exactly_one_purpose_with_its_own_token(): void
    {
        $document = $this->relatedDocument();
        $actor = $this->relatedActor();

        $download = $this->action()->issue($actor, $document, DocumentAccessPurpose::Download);
        $view = $this->action()->issue($actor, $document, DocumentAccessPurpose::View);

        $this->assertSame(DocumentAccessPurpose::Download, $download->purpose);
        $this->assertSame(DocumentAccessPurpose::View, $view->purpose);
        $this->assertNotSame($download->token, $view->token);
        $this->assertSame(2, SignedUrlGrant::query()->count());
        $this->assertSame(
            1,
            SignedUrlGrant::query()->where('purpose', DocumentAccessPurpose::Download->value)->count(),
        );
    }

    public function test_the_grant_is_bound_to_the_issuing_actor(): void
    {
        $grant = $this->action()->issue(
            $this->relatedActor(),
            $this->relatedDocument(),
            DocumentAccessPurpose::Download,
        );

        $this->assertSame('42', (string) $grant->actor_ref);
    }

    public function test_every_allowed_issuance_writes_an_audit_row_and_a_grant_access_event(): void
    {
        $document = $this->relatedDocument();

        $grant = $this->action()->issue($this->relatedActor(), $document, DocumentAccessPurpose::Download);

        $audit = AuditEvent::query()->where('action', 'DOCUMENT_ACCESS_GRANT')->sole();
        $this->assertSame(AuditOutcome::Allowed->value, $audit->outcome);
        $this->assertSame('42', (string) $audit->actor_ref);
        $this->assertSame('operator', $audit->actor_role);
        $this->assertSame('document', $audit->subject_type);
        $this->assertSame($document->getKey(), $audit->subject_id);
        $this->assertSame(['purpose' => DocumentAccessPurpose::Download->value], $audit->metadata);
        $this->assertNotNull($audit->occurred_at);
        $this->assertContains('purpose', MetadataAllowlist::ALLOWED_KEYS);

        $accessEvent = DocumentAccessEvent::query()->sole();
        $this->assertSame($document->getKey(), $accessEvent->document_id);
        $this->assertSame(DocumentAccessPurpose::Grant, $accessEvent->purpose);
        $this->assertSame(AuditOutcome::Allowed->value, $accessEvent->outcome);
        $this->assertSame('42', $accessEvent->actor_ref);
        $this->assertSame('operator', $accessEvent->actor_role);
        $this->assertNotNull($accessEvent->occurred_at);

        $this->assertNotSame('', $grant->token);
    }

    public function test_the_action_and_its_policy_resolve_from_the_container(): void
    {
        // Task 7's route resolves these rather than constructing them, and
        // `DocumentAccessPolicy` -> `ScopeAssignmentResolver` -> `ActorContext`
        // is a three-deep chain that only works because
        // `IdentityAccessServiceProvider` scopes an `ActorContext` binding.
        $this->assertInstanceOf(IssueSignedUrl::class, app(IssueSignedUrl::class));
        $this->assertInstanceOf(DocumentAccessPolicy::class, app(DocumentAccessPolicy::class));
        $this->assertInstanceOf(RecordDocumentAccess::class, app(RecordDocumentAccess::class));
    }

    public function test_neither_grant_action_is_sensitive_so_no_reason_is_required(): void
    {
        $this->assertNotContains('DOCUMENT_ACCESS_GRANT', SensitiveActions::ACTIONS);
        $this->assertNotContains('DOCUMENT_ACCESS_DENIED', SensitiveActions::ACTIONS);
    }

    public function test_an_unrelated_actor_with_a_role_is_denied_and_the_denial_is_recorded(): void
    {
        $document = $this->relatedDocument();
        $stranger = new ActorContext(identityReference: 99, roles: ['admin']);

        try {
            $this->action()->issue($stranger, $document, DocumentAccessPurpose::Download);
            $this->fail('An unrelated actor must not receive a grant.');
        } catch (DocumentAccessDeniedException) {
            // expected
        }

        $this->assertSame(0, SignedUrlGrant::query()->count());

        $audit = AuditEvent::query()->where('action', 'DOCUMENT_ACCESS_DENIED')->sole();
        $this->assertSame(AuditOutcome::Denied->value, $audit->outcome);
        $this->assertSame('99', (string) $audit->actor_ref);
        $this->assertSame('admin', $audit->actor_role);

        $accessEvent = DocumentAccessEvent::query()->sole();
        $this->assertSame(DocumentAccessPurpose::Grant, $accessEvent->purpose);
        $this->assertSame(AuditOutcome::Denied->value, $accessEvent->outcome);
    }

    public function test_a_guest_denial_records_a_non_null_actor_role(): void
    {
        try {
            $this->action()->issue(ActorContext::guest(), $this->relatedDocument(), DocumentAccessPurpose::View);
            $this->fail('A guest must not receive a grant.');
        } catch (DocumentAccessDeniedException) {
            // expected
        }

        $accessEvent = DocumentAccessEvent::query()->sole();
        $this->assertSame('guest', $accessEvent->actor_role);
        $this->assertNull($accessEvent->actor_ref);
    }

    public function test_a_denial_never_leaks_whether_the_document_exists(): void
    {
        $existingButForbidden = $this->relatedDocument();
        $stranger = new ActorContext(identityReference: 99, roles: ['admin']);

        $forbidden = $this->captureDenial(
            fn () => $this->action()->issueForDocumentId(
                $stranger,
                (string) $existingButForbidden->getKey(),
                DocumentAccessPurpose::Download,
            ),
        );

        $missing = $this->captureDenial(
            fn () => $this->action()->issueForDocumentId(
                $stranger,
                (string) Str::uuid(),
                DocumentAccessPurpose::Download,
            ),
        );

        $this->assertSame($forbidden::class, $missing::class);
        $this->assertSame($forbidden->getMessage(), $missing->getMessage());
        $this->assertSame($forbidden->getCode(), $missing->getCode());
        $this->assertStringNotContainsStringIgnoringCase('exist', $missing->getMessage());
        $this->assertStringNotContainsString((string) $existingButForbidden->getKey(), $forbidden->getMessage());
    }

    /**
     * `documents.id` is a real PostgreSQL `uuid` column, so a non-UUID id
     * would make the driver raise a `QueryException` (a 500) instead of
     * returning `null` — distinguishable from a clean refusal, and therefore
     * an AC9 existence leak. The guard runs in PHP before any driver is
     * involved, so this test is meaningful on SQLite even though SQLite could
     * never reproduce the original PostgreSQL failure.
     */
    public function test_a_malformed_document_id_is_refused_identically_to_a_well_formed_unknown_one(): void
    {
        $stranger = new ActorContext(identityReference: 99, roles: ['admin']);
        $wellFormedUnknown = $this->captureDenial(
            fn () => $this->action()->issueForDocumentId(
                $stranger,
                (string) Str::uuid(),
                DocumentAccessPurpose::Download,
            ),
        );

        foreach ([
            'not-a-uuid',
            '',
            '42',
            "'; DROP TABLE documents; --",
            str_repeat('a', 4096),
            '00000000-0000-0000-0000-00000000000',
        ] as $malformedId) {
            $denial = $this->captureDenial(
                fn () => $this->action()->issueForDocumentId(
                    $stranger,
                    $malformedId,
                    DocumentAccessPurpose::Download,
                ),
            );

            $this->assertSame($wellFormedUnknown::class, $denial::class);
            $this->assertSame($wellFormedUnknown->getMessage(), $denial->getMessage());
            $this->assertSame($wellFormedUnknown->getCode(), $denial->getCode());

            if ($malformedId !== '') {
                $this->assertStringNotContainsString($malformedId, $denial->getMessage());
            }
        }
    }

    public function test_a_malformed_document_id_appends_nothing_to_either_append_only_table(): void
    {
        $stranger = new ActorContext(identityReference: 99, roles: ['admin']);

        foreach (['not-a-uuid', '', str_repeat('a', 4096)] as $malformedId) {
            $this->captureDenial(
                fn () => $this->action()->issueForDocumentId(
                    $stranger,
                    $malformedId,
                    DocumentAccessPurpose::Download,
                ),
            );
        }

        // An unauditable subject must not be allowed to drive writes into
        // tables that have no delete path.
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame(0, DocumentAccessEvent::query()->count());
        $this->assertSame(0, SignedUrlGrant::query()->count());

        // A WELL-FORMED unknown id still audits — the asymmetry is deliberate
        // and invisible to the caller.
        $this->captureDenial(
            fn () => $this->action()->issueForDocumentId(
                $stranger,
                (string) Str::uuid(),
                DocumentAccessPurpose::Download,
            ),
        );

        $this->assertSame(1, AuditEvent::query()->where('action', 'DOCUMENT_ACCESS_DENIED')->count());
    }

    public function test_an_accepted_document_reached_by_id_issues_the_same_grant_as_the_model_entry_point(): void
    {
        $document = $this->relatedDocument();

        $grant = $this->action()->issueForDocumentId(
            $this->relatedActor(),
            (string) $document->getKey(),
            DocumentAccessPurpose::Download,
        );

        $this->assertSame($document->getKey(), $grant->document_id);
        $this->assertSame(DocumentAccessPurpose::Download, $grant->purpose);
    }

    public function test_the_temporary_url_is_rendered_from_the_grant_without_being_stored(): void
    {
        $document = $this->relatedDocument();

        $grant = $this->action()->issue($this->relatedActor(), $document, DocumentAccessPurpose::Download);

        $url = $this->action()->temporaryUrl($grant);

        $this->assertStringEndsWith(
            "/internal/documents/{$document->getKey()}/download/{$grant->token}",
            $url,
        );

        // The token is never copied into audit metadata or the access event.
        $audit = AuditEvent::query()->where('action', 'DOCUMENT_ACCESS_GRANT')->sole();
        $this->assertStringNotContainsString((string) $grant->token, json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_issued_urls_use_the_private_application_route_instead_of_storage_adapter(): void
    {
        $document = $this->relatedDocument();
        $grant = $this->action()->issue($this->relatedActor(), $document, DocumentAccessPurpose::Download);

        $url = $this->action()->temporaryUrl($grant);

        $this->assertStringEndsWith(
            "/internal/documents/{$document->getKey()}/download/{$grant->token}",
            $url,
        );
        $this->assertFalse(method_exists(ObjectStorage::class, 'temporaryUrl'));
        $this->assertSame(1, SignedUrlGrant::query()->count());
        $this->assertSame(1, DocumentAccessEvent::query()->count());
    }

    #[DataProvider('immutableGrantAttributeProvider')]
    public function test_issued_grants_reject_model_mutation_of_immutable_attributes(string $attribute, mixed $value): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        $grant->setAttribute($attribute, $value);

        $this->expectException(SignedUrlGrantImmutableException::class);

        $grant->save();
    }

    #[DataProvider('immutableGrantAttributeProvider')]
    public function test_issued_grants_reject_query_builder_mutation_of_immutable_attributes(string $attribute, mixed $value): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        $this->expectException(SignedUrlGrantImmutableException::class);

        SignedUrlGrant::query()->whereKey($grant->getKey())->update([$attribute => $value]);
    }

    public function test_an_issued_grant_can_only_transition_consumed_at_once(): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        $this->assertTrue($grant->consume());
        $this->assertNotNull($grant->fresh()->consumed_at);
        $this->assertFalse($grant->consume());
    }

    public function test_an_issued_grant_cannot_be_deleted(): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        $this->expectException(SignedUrlGrantImmutableException::class);

        $grant->delete();
    }

    public function test_issued_grants_reject_query_builder_upsert(): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        $this->expectException(SignedUrlGrantImmutableException::class);

        SignedUrlGrant::query()->upsert([
            [
                'id' => $grant->getKey(),
                'document_id' => $grant->document_id,
                'actor_ref' => $grant->actor_ref,
                'purpose' => $grant->purpose->value,
                'token' => 'replacement-token',
                'expires_at' => $grant->expires_at,
                'consumed_at' => null,
                'created_at' => $grant->created_at,
            ],
        ], ['id'], ['token']);
    }

    public function test_issued_grants_reject_query_builder_force_delete(): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        $this->expectException(SignedUrlGrantImmutableException::class);

        SignedUrlGrant::query()->whereKey($grant->getKey())->forceDelete();
    }

    public function test_issued_grants_reject_query_builder_increment(): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        $this->expectException(SignedUrlGrantImmutableException::class);

        SignedUrlGrant::query()->whereKey($grant->getKey())->increment('id');
    }

    public function test_issued_grants_reject_query_builder_insert_or_ignore_using(): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        try {
            SignedUrlGrant::query()->insertOrIgnoreUsing(
                ['id'],
                DB::table('signed_url_grants')->select('id')->where('id', $grant->getKey()),
            );
        } catch (SignedUrlGrantImmutableException $exception) {
            $this->assertTrue(collect($exception->getTrace())->contains(
                static fn (array $frame): bool => ($frame['class'] ?? null) === SignedUrlGrantQueryBuilder::class
                    && ($frame['function'] ?? null) === 'rejectMutation',
            ));

            return;
        }

        $this->fail('Expected the Eloquent query builder to reject insertOrIgnoreUsing.');
    }

    public function test_issued_grants_reject_query_builder_update_from(): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        $this->expectException(SignedUrlGrantImmutableException::class);

        SignedUrlGrant::query()->whereKey($grant->getKey())->updateFrom([
            'token' => 'replacement-token',
        ]);
    }

    public function test_issued_grants_reject_base_query_builder_mutation(): void
    {
        $grant = $this->action()->issue($this->relatedActor(), $this->relatedDocument(), DocumentAccessPurpose::Download);

        $this->expectException(SignedUrlGrantImmutableException::class);

        SignedUrlGrant::query()->whereKey($grant->getKey())->toBase()->update([
            'token' => 'replacement-token',
        ]);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function immutableGrantAttributeProvider(): iterable
    {
        yield 'token' => ['token', 'replacement-token'];
        yield 'purpose' => ['purpose', DocumentAccessPurpose::View];
        yield 'expiry' => ['expires_at', CarbonImmutable::parse('2026-08-10 10:00:00')];
        yield 'document' => ['document_id', (string) Str::uuid()];
        yield 'actor binding' => ['actor_ref', 'replacement-actor'];
    }

    private function captureDenial(callable $callback): DocumentAccessDeniedException
    {
        try {
            $callback();
        } catch (DocumentAccessDeniedException $exception) {
            return $exception;
        }

        $this->fail('Expected a DocumentAccessDeniedException.');
    }

    private function action(): IssueSignedUrl
    {
        return new IssueSignedUrl(new DocumentAccessPolicy(new ScopeAssignmentResolver(ActorContext::guest())));
    }

    private function relatedActor(): ActorContext
    {
        return new ActorContext(identityReference: 42, roles: ['operator']);
    }

    private function relatedDocument(DocumentState $state = DocumentState::Accepted): Document
    {
        $documentId = (string) Str::uuid();
        $ownerId = 'order-'.Str::random(8);

        DB::table('documents')->insert([
            'id' => $documentId,
            'document_kind' => 'DEATH_CERTIFICATE',
            'state' => $state->value,
            'owner_type' => ScopeEntityType::ORDER,
            'owner_id' => $ownerId,
            'original_filename' => 'akta-kematian.pdf',
            'storage_prefix' => $state === DocumentState::Accepted ? 'accepted' : 'quarantine',
            'storage_key' => 'opaque-key-'.Str::random(8),
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ScopeAssignment::query()->create([
            'actor_identifier' => '42',
            'entity_type' => ScopeEntityType::ORDER,
            'entity_id' => $ownerId,
        ]);

        return Document::query()->findOrFail($documentId);
    }
}
