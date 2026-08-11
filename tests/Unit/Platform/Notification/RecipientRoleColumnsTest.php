<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\RecipientResolver;
use App\Platform\Notification\RecipientRoleColumns;
use ReflectionClass;
use Tests\TestCase;

final class RecipientRoleColumnsTest extends TestCase
{
    public function test_dispatch_channel_mapping_stays_in_parity_with_recipient_resolution_mapping(): void
    {
        $resolverConstants = (new ReflectionClass(RecipientResolver::class))
            ->getReflectionConstant('ROLE_COLUMNS')
            ->getValue();

        $this->assertSame(
            $resolverConstants,
            RecipientRoleColumns::SCOPE_ROLE_COLUMNS,
            'Channel dispatch must scan the same role-to-matrix-column mapping as recipient resolution.',
        );
    }
}
