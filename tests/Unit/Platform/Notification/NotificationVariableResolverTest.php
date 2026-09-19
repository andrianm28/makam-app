<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Contracts\NotificationVariableSource;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\NotificationVariableResolver;
use App\Platform\Outbox\Models\OutboxEvent;
use PHPUnit\Framework\TestCase;

final class NotificationVariableResolverTest extends TestCase
{
    public function test_the_bag_is_restricted_to_the_version_allowlist(): void
    {
        $resolver = new NotificationVariableResolver(new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'MO-1', 'secret' => 'never'];
            }
        });

        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode(['order_id'])]);

        $row = new OutboxEvent;
        $row->setRawAttributes([
            'aggregate_type' => 'marketplace_order',
            'aggregate_id' => 'abc',
            'payload' => json_encode(['data' => ['order_id' => 'MO-1']]),
        ]);

        self::assertSame(['order_id' => 'MO-1'], $resolver->forOutboxRow($row, 'Marketplace order submitted', $version));
    }

    public function test_a_version_with_an_empty_allowlist_gets_an_empty_bag(): void
    {
        $resolver = new NotificationVariableResolver(new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'MO-1'];
            }
        });

        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode([])]);

        self::assertSame([], $resolver->restrictToAllowlist(['order_id' => 'MO-1'], $version));
    }

    public function test_a_source_that_does_not_handle_the_aggregate_is_skipped(): void
    {
        $resolver = new NotificationVariableResolver(new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return $aggregateType === 'order';
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_reference' => 'MK-1'];
            }
        });

        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode(['order_reference'])]);

        $row = new OutboxEvent;
        $row->setRawAttributes(['aggregate_type' => 'vendor_order', 'aggregate_id' => 'v1', 'payload' => json_encode([])]);

        self::assertSame([], $resolver->forOutboxRow($row, 'Vendor accepted/rejected', $version));
    }

    public function test_a_later_source_overrides_an_earlier_one_for_the_same_key(): void
    {
        $first = new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'from-payload'];
            }
        };
        $second = new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'from-module'];
            }
        };

        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode(['order_id'])]);
        $row = new OutboxEvent;
        $row->setRawAttributes(['aggregate_type' => 'order', 'aggregate_id' => 'o1', 'payload' => json_encode([])]);

        self::assertSame(['order_id' => 'from-module'], (new NotificationVariableResolver($first, $second))->forOutboxRow($row, 'x', $version));
    }

    /**
     * `appending()` is what `Providers\NotificationServiceProvider`'s
     * `$this->app->extend(...)` contract is built on (see that method's
     * own doc block) — a feature module extends the REAL resolver the
     * provider produced rather than reconstructing one from scratch. Two
     * things must hold for that to be safe: the source already present
     * keeps supplying keys the appended source does not touch, and the
     * appended source — being later in registration order — wins any key
     * both supply.
     */
    public function test_appending_keeps_the_existing_source_and_lets_the_new_one_win_on_collision(): void
    {
        $existing = new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'from-existing', 'shared' => 'from-existing'];
            }
        };

        $appended = new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['shared' => 'from-appended'];
            }
        };

        $resolver = (new NotificationVariableResolver($existing))->appending($appended);

        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode(['order_id', 'shared'])]);
        $row = new OutboxEvent;
        $row->setRawAttributes(['aggregate_type' => 'order', 'aggregate_id' => 'o1', 'payload' => json_encode([])]);

        self::assertSame(
            ['order_id' => 'from-existing', 'shared' => 'from-appended'],
            $resolver->forOutboxRow($row, 'x', $version),
        );
    }
}
