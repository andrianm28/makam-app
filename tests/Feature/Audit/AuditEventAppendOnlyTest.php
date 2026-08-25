<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Exceptions\AuditRecordIsImmutableException;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC1 against a REAL persisted row (see
 * tests/Unit/Platform/Audit/Models/AuditEventGuardTest.php for the
 * equivalent unit-level proof that does not need the database).
 *
 * This class also deliberately proves the documented gap: the
 * model-level guard is NOT the database-level enforcement design.md
 * names, and a query-builder mass update bypasses it entirely. See
 * App\Platform\Audit\Models\AuditEvent's class-level doc block and
 * the migration's own doc block for the full accounting — this test
 * exists so that gap is visible and tracked, not silently assumed
 * closed.
 */
final class AuditEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function persistedEvent(): AuditEvent
    {
        return Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    public function test_update_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(AuditRecordIsImmutableException::class);

        $event->update(['outcome' => 'denied']);
    }

    public function test_mutating_an_attribute_and_calling_save_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();
        $event->outcome = 'denied';

        $this->expectException(AuditRecordIsImmutableException::class);

        $event->save();
    }

    public function test_delete_on_a_persisted_row_throws(): void
    {
        $event = $this->persistedEvent();

        $this->expectException(AuditRecordIsImmutableException::class);

        $event->delete();
    }

    public function test_the_row_is_unchanged_after_a_blocked_update_attempt(): void
    {
        $event = $this->persistedEvent();

        try {
            $event->update(['outcome' => 'denied']);
        } catch (AuditRecordIsImmutableException) {
            // expected
        }

        $this->assertSame('allowed', $event->fresh()->outcome);
    }

    /**
     * THE DOCUMENTED GAP, made concrete rather than left as prose
     * only. `AuditEvent::query()->update()` goes through
     * `Illuminate\Database\Eloquent\Builder`, not the `AuditEvent`
     * model instance, so overriding `update()`/`performUpdate()` on
     * the model never intercepts it. Real enforcement requires the
     * database-level `REVOKE` in
     * `app/Platform/Audit/sql/revoke-audit-mutations.sql`, which is
     * blocked on finding N-1 (no separate application/migration
     * Postgres role exists yet).
     *
     * This test currently asserts the bypass SUCCEEDS. That is not
     * this test endorsing the gap — it is this test refusing to let
     * the gap go unnoticed. Once N-1 is resolved and the REVOKE is
     * applied for real in every environment, this exact assertion
     * should start failing at the database (a permission error) for
     * any role that isn't the migration owner, and this test should
     * be revisited then, not deleted quietly.
     */
    public function test_query_builder_mass_update_bypasses_the_model_level_guard_this_is_the_documented_ac1_gap(): void
    {
        $event = $this->persistedEvent();

        $affected = AuditEvent::query()->where('id', $event->id)->update(['outcome' => 'denied']);

        $this->assertSame(1, $affected);
        $this->assertSame('denied', $event->fresh()->outcome);
    }
}
