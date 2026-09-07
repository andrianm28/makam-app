<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\DocumentVault\Exceptions\DocumentAccessEventIsImmutableException;
use App\Platform\DocumentVault\Models\DocumentAccessEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DocumentAccessEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(DocumentAccessEventIsImmutableException::class);

        $event->update(['outcome' => 'denied']);
    }

    public function test_mutating_an_attribute_and_calling_save_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();
        $event->outcome = 'denied';

        $this->expectException(DocumentAccessEventIsImmutableException::class);

        $event->save();
    }

    public function test_delete_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(DocumentAccessEventIsImmutableException::class);

        $event->delete();
    }

    public function test_document_cannot_be_deleted_while_an_access_event_references_it(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(QueryException::class);

        DB::table('documents')->where('id', $event->document_id)->delete();
    }

    /**
     * OBS-01 (7 Sep 2026): `DocumentAccessEvent::query()->update()` goes
     * through `Illuminate\Database\Eloquent\Builder`, not this model's
     * overrides, so the model-level guard never sees it. The real control is
     * now `2026_09_07_100000_enforce_audit_events_append_only.php`'s
     * `document_access_events_append_only` trigger. On PostgreSQL the
     * bypass is refused by the database; on SQLite (no PL/pgSQL) there is
     * no trigger and the gap is asserted open rather than skipped.
     */
    public function test_query_builder_mass_update_bypasses_the_model_level_guard(): void
    {
        $event = $this->persistedEvent();

        if (! $this->onPostgres()) {
            $affected = DocumentAccessEvent::query()->where('id', $event->id)->update(['outcome' => 'denied']);

            $this->assertSame(1, $affected, 'SQLite carries no append-only trigger; the model-layer gap is still open there.');
            $this->assertSame('denied', $event->fresh()->outcome);

            return;
        }

        $this->assertMutationRefused(
            fn () => DocumentAccessEvent::query()->where('id', $event->id)->update(['outcome' => 'denied']),
        );

        $this->assertSame('allowed', $event->fresh()->outcome);
    }

    /**
     * OBS-01: the database refuses every update/delete shape on
     * `document_access_events`, as raw query-builder statements — the
     * refusal does not depend on going through Eloquent at all.
     */
    public function test_the_database_refuses_every_update_and_delete_on_document_access_events(): void
    {
        $this->skipUnlessPostgres();

        $event = $this->persistedEvent();

        $mutations = [
            'update via query builder' => fn () => DB::table('document_access_events')
                ->where('id', $event->id)
                ->update(['outcome' => 'denied']),

            'delete via query builder' => fn () => DB::table('document_access_events')
                ->where('id', $event->id)
                ->delete(),
        ];

        foreach ($mutations as $label => $mutation) {
            $this->assertMutationRefused($mutation, $label);
        }

        $row = DB::table('document_access_events')->where('id', $event->id)->sole();
        $this->assertSame('allowed', $row->outcome);
    }

    /**
     * The sanctioned insert path (the fixture's own `insertGetId`) is
     * unaffected by the trigger.
     */
    public function test_the_sanctioned_insert_path_is_unaffected_by_the_append_only_trigger(): void
    {
        $this->skipUnlessPostgres();

        $countBefore = DB::table('document_access_events')->count();

        $this->persistedEvent();

        $this->assertSame($countBefore + 1, DB::table('document_access_events')->count());
    }

    /**
     * Pins the refusal to `reject_audit_history_mutation()` specifically —
     * SQLSTATE and message together, matching
     * `JournalAppendOnlyTest`/`AuditEventAppendOnlyTest`'s own helper. The
     * `DB::transaction()` wrapper is not decoration: `RefreshDatabase`
     * already holds an open transaction, and an uncaught PostgreSQL error
     * would leave it ABORTED for every later statement in the test.
     */
    private function assertMutationRefused(\Closure $mutation, string $label = 'mutation'): void
    {
        try {
            DB::transaction($mutation);
            $this->fail("Expected [{$label}] to be refused by the append-only trigger, but it succeeded.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Audit history is append-only',
                $exception->getMessage(),
                "[{$label}] failed, but not with the append-only policy message: {$exception->getMessage()}",
            );
            $this->assertSame(
                '42501',
                $exception->getCode(),
                "[{$label}] must be refused as insufficient_privilege (42501), not as some other error class.",
            );
        }
    }

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function skipUnlessPostgres(): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped(
                'The document_access_events append-only trigger is PostgreSQL-only; run with DB_CONNECTION=pgsql.'
            );
        }
    }

    private function persistedEvent(): DocumentAccessEvent
    {
        $documentId = (string) Str::uuid();

        DB::table('documents')->insert([
            'id' => $documentId,
            'document_kind' => 'KTP',
            'state' => 'ACCEPTED',
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-123',
            'original_filename' => 'identity.pdf',
            'storage_prefix' => 'accepted',
            'storage_key' => 'opaque-key-123',
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('document_access_events')->insertGetId([
            'document_id' => $documentId,
            'actor_ref' => 'actor-123',
            'actor_role' => 'admin',
            'purpose' => 'VIEW',
            'outcome' => 'allowed',
            'ip_address' => '192.0.2.1',
            'occurred_at' => now(),
        ]);

        return DocumentAccessEvent::query()->findOrFail($eventId);
    }
}
