<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Audit\Models;

use App\Platform\Audit\Exceptions\AuditRecordIsImmutableException;
use App\Platform\Audit\Models\AuditEvent;
use Tests\TestCase;

/**
 * AC1's application-level guard on `AuditEvent`. Pure unit coverage —
 * no database needed, because `update()`/`performUpdate()`/`delete()`
 * throw unconditionally regardless of whether the instance is
 * persisted. See `tests/Feature/Audit/AuditEventAppendOnlyTest.php`
 * for the equivalent proof against a real persisted row, plus the
 * documented gap (query-builder mass update bypasses this guard).
 */
final class AuditEventGuardTest extends TestCase
{
    public function test_update_always_throws(): void
    {
        $event = new AuditEvent;

        $this->expectException(AuditRecordIsImmutableException::class);

        $event->update(['outcome' => 'denied']);
    }

    public function test_delete_always_throws(): void
    {
        $event = new AuditEvent;

        $this->expectException(AuditRecordIsImmutableException::class);

        $event->delete();
    }

    public function test_has_no_updated_at_timestamp_column_behaviour(): void
    {
        // $timestamps = false is itself part of AC1's story: an
        // updated_at column would suggest the row can be revised.
        $event = new AuditEvent;

        $this->assertFalse($event->usesTimestamps());
    }
}
