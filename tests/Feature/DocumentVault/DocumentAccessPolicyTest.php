<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Policies\DocumentAccessPolicy;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AC9: role AND record relationship are BOTH required. A role alone is never
 * enough, a relationship alone is never enough, and a guest is never enough.
 */
final class DocumentAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_denied_even_for_a_document_whose_owner_id_matches_nothing(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'order-1');

        $this->assertFalse($this->policy()->canView(ActorContext::guest(), $document));
    }

    public function test_a_role_without_any_record_relationship_is_denied(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'order-1');

        // Every role on the permitted list, and none of them holds a grant.
        foreach (DocumentAccessPolicy::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS as $role) {
            $this->assertFalse(
                $this->policy()->canView($this->actor(42, [$role]), $document),
                "Role [{$role}] alone must not grant access.",
            );
        }
    }

    public function test_a_record_relationship_without_a_permitted_role_is_denied(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'order-1');
        $this->grant(42, ScopeEntityType::ORDER, 'order-1');

        $this->assertFalse($this->policy()->canView($this->actor(42, []), $document));
        $this->assertFalse($this->policy()->canView($this->actor(42, ['finance']), $document));
    }

    /**
     * User ruling, 10 Aug 2026. `rbac-matrix.md:12` gives the Vendor column
     * "No default" on restricted documents — the identical value it gives
     * Finance/Issuer/Auditor — so vendor is EXCLUDED from the allow-list on
     * the same basis finance is. This fails if `vendor` is ever re-added.
     */
    public function test_a_vendor_is_denied_even_with_a_record_relationship(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'order-1');
        $this->grant(42, ScopeEntityType::ORDER, 'order-1');

        $this->assertNotContains('vendor', DocumentAccessPolicy::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS);
        $this->assertFalse($this->policy()->canView($this->actor(42, ['vendor']), $document));

        // The same actor and the same grant, with an included role, IS
        // allowed — so this proves the role is what denied them, not a
        // missing relationship.
        $this->assertTrue($this->policy()->canView($this->actor(42, ['case_manager']), $document));
    }

    /**
     * Same ruling, other direction: Case Manager's "Assigned/purpose" is an
     * affirmative grant, so it is INCLUDED. The "assigned" half is the
     * scope assignment.
     */
    public function test_a_case_manager_with_a_record_relationship_is_allowed(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'order-1');
        $this->grant(42, ScopeEntityType::ORDER, 'order-1');

        $this->assertContains('case_manager', DocumentAccessPolicy::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS);
        $this->assertTrue($this->policy()->canView($this->actor(42, ['case_manager']), $document));
    }

    public function test_a_case_manager_without_a_record_relationship_is_still_denied(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'order-1');
        $this->grant(42, ScopeEntityType::ORDER, 'order-2');

        $this->assertFalse($this->policy()->canView($this->actor(42, ['case_manager']), $document));
    }

    public function test_the_permitted_role_list_matches_the_ruled_membership_rule(): void
    {
        $this->assertSame(
            ['admin', 'operator', 'case_manager', 'customer'],
            DocumentAccessPolicy::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS,
        );
    }

    public function test_a_permitted_role_with_an_active_scope_assignment_is_allowed(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'order-1');
        $this->grant(42, ScopeEntityType::ORDER, 'order-1');

        $this->assertTrue($this->policy()->canView($this->actor(42, ['operator']), $document));
    }

    public function test_a_scope_assignment_for_a_different_record_is_denied(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'order-1');
        $this->grant(42, ScopeEntityType::ORDER, 'order-2');

        $this->assertFalse($this->policy()->canView($this->actor(42, ['operator']), $document));
    }

    public function test_a_scope_assignment_of_a_different_entity_type_is_denied(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'shared-id');
        $this->grant(42, ScopeEntityType::CEMETERY, 'shared-id');

        $this->assertFalse($this->policy()->canView($this->actor(42, ['operator']), $document));
    }

    public function test_a_revoked_scope_assignment_is_denied(): void
    {
        $document = $this->documentOwnedBy(ScopeEntityType::ORDER, 'order-1');
        $this->grant(42, ScopeEntityType::ORDER, 'order-1', revoked: true);

        $this->assertFalse($this->policy()->canView($this->actor(42, ['operator']), $document));
    }

    public function test_a_document_owned_by_the_actor_themselves_is_allowed_without_a_scope_assignment(): void
    {
        $document = $this->documentOwnedBy(DocumentAccessPolicy::OWNER_TYPE_ACTOR, '42');

        $this->assertTrue($this->policy()->canView($this->actor(42, ['customer']), $document));
    }

    public function test_a_document_owned_by_another_actor_is_denied(): void
    {
        $document = $this->documentOwnedBy(DocumentAccessPolicy::OWNER_TYPE_ACTOR, '43');

        $this->assertFalse($this->policy()->canView($this->actor(42, ['customer']), $document));
    }

    public function test_an_owner_type_with_no_known_relationship_source_fails_closed(): void
    {
        $document = $this->documentOwnedBy('booking_draft', 'draft-1');
        $this->grant(42, ScopeEntityType::ORDER, 'draft-1');

        $this->assertFalse($this->policy()->canView($this->actor(42, ['admin']), $document));
    }

    public function test_the_audit_role_is_deterministic_regardless_of_role_array_order(): void
    {
        $this->assertSame('guest', DocumentAccessPolicy::auditRoleFor(ActorContext::guest()));
        $this->assertSame(
            'admin',
            DocumentAccessPolicy::auditRoleFor($this->actor(42, ['customer', 'admin', 'operator'])),
        );
        $this->assertSame(
            'admin',
            DocumentAccessPolicy::auditRoleFor($this->actor(42, ['operator', 'admin', 'customer'])),
        );
        $this->assertSame('operator', DocumentAccessPolicy::auditRoleFor($this->actor(42, ['operator'])));
        $this->assertSame(
            'authenticated_actor',
            DocumentAccessPolicy::auditRoleFor($this->actor(42, [])),
        );
        $this->assertSame(
            'authenticated_actor',
            DocumentAccessPolicy::auditRoleFor($this->actor(42, ['finance'])),
        );
    }

    private function policy(): DocumentAccessPolicy
    {
        return new DocumentAccessPolicy(new ScopeAssignmentResolver(ActorContext::guest()));
    }

    /**
     * @param  list<string>  $roles
     */
    private function actor(int|string $identityReference, array $roles): ActorContext
    {
        return new ActorContext(identityReference: $identityReference, roles: $roles);
    }

    private function grant(int|string $actorIdentifier, string $entityType, string $entityId, bool $revoked = false): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $actorIdentifier,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'revoked_at' => $revoked ? CarbonImmutable::now() : null,
        ]);
    }

    private function documentOwnedBy(string $ownerType, string $ownerId): Document
    {
        $documentId = (string) Str::uuid();

        DB::table('documents')->insert([
            'id' => $documentId,
            'document_kind' => 'KTP',
            'state' => 'ACCEPTED',
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'original_filename' => 'identity.pdf',
            'storage_prefix' => 'accepted',
            'storage_key' => 'opaque-key-'.Str::random(8),
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Document::query()->findOrFail($documentId);
    }
}
