<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Audit;

use App\Platform\Audit\AuditSubject;
use Tests\TestCase;

/**
 * Pure unit coverage for the `AuditSubject` value object — no
 * database, no container resolution.
 */
final class AuditSubjectTest extends TestCase
{
    public function test_carries_type_and_id(): void
    {
        $subject = new AuditSubject(type: 'booking', id: 42);

        $this->assertSame('booking', $subject->type);
        $this->assertSame(42, $subject->id);
        $this->assertNull($subject->version);
    }

    public function test_accepts_a_string_id(): void
    {
        $subject = new AuditSubject(type: 'booking', id: 'uuid-1234');

        $this->assertSame('uuid-1234', $subject->id);
    }

    public function test_carries_an_optional_version(): void
    {
        $subject = new AuditSubject(type: 'booking', id: 42, version: 3);

        $this->assertSame(3, $subject->version);
    }

    public function test_is_immutable_by_construction(): void
    {
        $reflection = new \ReflectionClass(AuditSubject::class);

        foreach (['type', 'id', 'version'] as $property) {
            $this->assertTrue(
                $reflection->getProperty($property)->isReadOnly(),
                "AuditSubject::\${$property} must be readonly."
            );
        }
    }
}
