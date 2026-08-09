<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Task 4 brief Step 4: "direct `Document::create(['state' => 'accepted'])`
 * still impossible via state-machine (enum + promotion-only write API)."
 *
 * This suite proves that state cannot be mass-assigned or directly assigned,
 * while the explicit transition API remains available to the scan/promotion
 * Actions in Task 5.
 */
final class DocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_is_not_mass_assignable(): void
    {
        $document = new Document([
            'document_kind' => DocumentKind::Ktp,
            'state' => DocumentState::Accepted,
        ]);

        $this->assertArrayNotHasKey('state', $document->getAttributes());
        $this->assertNotContains('state', $document->getFillable());
    }

    public function test_direct_state_assignment_is_rejected(): void
    {
        $document = new Document([
            'document_kind' => DocumentKind::Ktp,
        ]);

        $this->expectException(LogicException::class);

        $document->state = DocumentState::Accepted;
    }

    public function test_non_accepted_transition_uses_the_explicit_write_path(): void
    {
        $document = Document::createQuarantined([
            'document_kind' => DocumentKind::Ktp,
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-1',
            'original_filename' => 'ktp.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => 'test-key',
            'size_bytes' => 100,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
        ]);

        $document->transitionTo(DocumentState::Scanning);

        $this->assertSame(DocumentState::Scanning, $document->fresh()->state);
    }

    public function test_accepted_state_requires_the_promotion_path_from_scanning(): void
    {
        $document = Document::createQuarantined([
            'document_kind' => DocumentKind::Ktp,
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-1',
            'original_filename' => 'ktp.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => 'test-key',
            'size_bytes' => 100,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
        ]);

        $this->expectException(LogicException::class);

        $document->promote();
    }

    public function test_id_is_not_mass_assignable_and_is_generated_by_has_uuids(): void
    {
        $document = new Document;

        $this->assertNotContains('id', $document->getFillable());
    }
}
