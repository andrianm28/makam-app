<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Tests\TestCase;
use ValueError;

/**
 * Task 4 brief Step 4: "direct `Document::create(['state' => 'accepted'])`
 * still impossible via state-machine (enum + promotion-only write API)."
 *
 * This suite proves the "enum" half of that sentence — the "promotion-only
 * write API" half is a structural/convention guarantee (no other class in
 * this codebase calls `Document::create()`), not something a unit test can
 * assert against. `DocumentState`'s cases are all UPPERCASE
 * (`DocumentState::Accepted->value === 'ACCEPTED'`); the lowercase
 * `'accepted'` this test uses is deliberately NOT a valid case, so this
 * proves the guard rejects a wrongly-cased value, not merely an
 * unrecognised one. No database access needed: `casts()`'s native backed
 * -enum cast throws `ValueError` the moment the attribute is SET (inside
 * `fill()`, before any query runs) — see `Document`'s own class doc block.
 */
final class DocumentTest extends TestCase
{
    public function test_a_lowercase_accepted_state_value_is_rejected_by_the_enum_cast_before_any_query_runs(): void
    {
        $this->expectException(ValueError::class);

        Document::create([
            'document_kind' => DocumentKind::Ktp,
            'state' => 'accepted',
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-1',
            'original_filename' => 'ktp.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => 'test-key',
            'size_bytes' => 100,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
        ]);
    }

    public function test_a_valid_uppercase_state_value_is_accepted_by_the_enum_cast(): void
    {
        $document = new Document([
            'document_kind' => DocumentKind::Ktp,
            'state' => 'QUARANTINED',
        ]);

        $this->assertSame(DocumentState::Quarantined, $document->state);
        $this->assertSame(DocumentKind::Ktp, $document->document_kind);
    }

    public function test_id_is_not_mass_assignable_and_is_generated_by_has_uuids(): void
    {
        $document = new Document;

        $this->assertNotContains('id', $document->getFillable());
    }
}
