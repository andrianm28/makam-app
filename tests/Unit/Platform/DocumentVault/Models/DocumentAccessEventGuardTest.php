<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\Exceptions\DocumentAccessEventIsImmutableException;
use App\Platform\DocumentVault\Models\DocumentAccessEvent;
use Tests\TestCase;

final class DocumentAccessEventGuardTest extends TestCase
{
    public function test_update_always_throws(): void
    {
        $event = new DocumentAccessEvent;

        $this->expectException(DocumentAccessEventIsImmutableException::class);

        $event->update(['outcome' => 'denied']);
    }

    public function test_delete_always_throws(): void
    {
        $event = new DocumentAccessEvent;

        $this->expectException(DocumentAccessEventIsImmutableException::class);

        $event->delete();
    }

    public function test_the_model_is_completely_guarded_and_has_no_updated_at_behaviour(): void
    {
        $event = new DocumentAccessEvent;

        $this->assertSame(['*'], $event->getGuarded());
        $this->assertFalse($event->usesTimestamps());
        $this->assertSame('immutable_datetime', $event->getCasts()['occurred_at']);
    }
}
