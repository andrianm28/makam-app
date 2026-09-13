<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Audit;

use App\Platform\Audit\AuditSubject;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The write is attempted for real rather than asked of reflection.
     * `isReadOnly()` proves today's declaration; an actual assignment proves
     * the value a caller already holds cannot be changed under them — which
     * is the property AC5 depends on, and the only one that stays meaningful
     * if the class is renamed or its properties are redeclared.
     *
     * The message is asserted, not just the `Error`: dropping the property
     * altogether, or making it private, also throws here, and those are
     * different bugs that must not pass as "immutable".
     */
    #[DataProvider('immutablePropertyProvider')]
    public function test_is_immutable_by_construction(string $property, mixed $replacement): void
    {
        $subject = new AuditSubject(type: 'booking', id: 42, version: 3);
        $original = $subject->{$property};

        try {
            $subject->{$property} = $replacement;
            $this->fail("AuditSubject::\${$property} accepted a write — it must be readonly.");
        } catch (Error $e) {
            $this->assertStringContainsString(
                'Cannot modify readonly property',
                $e->getMessage(),
                "AuditSubject::\${$property} refused the write for the wrong reason: {$e->getMessage()}"
            );
        }

        $this->assertSame($original, $subject->{$property});
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function immutablePropertyProvider(): array
    {
        return [
            'type' => ['type', 'renewal'],
            'id' => ['id', 99],
            'version' => ['version', 7],
        ];
    }
}
