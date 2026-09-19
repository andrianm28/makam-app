<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Contracts\NotificationVariableSource;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\NotificationVariableResolver;
use App\Platform\Notification\PayloadNotificationVariableSource;
use App\Platform\Outbox\Models\OutboxEvent;
use Tests\TestCase;

/**
 * `Providers\NotificationServiceProvider` binds `NotificationVariableResolver`
 * as a CLOSURE singleton — not `$this->app->singleton(NotificationVariableResolver::class)`
 * auto-resolution — precisely so a later feature module can
 * `$this->app->extend(NotificationVariableResolver::class, ...)` and append
 * its own `NotificationVariableSource` (see that provider's own doc block,
 * and `.kiro/specs/platform-notifications/design.md` §"Template variables":
 * "a feature module registers its own [source] ... so the platform never
 * imports a Domain model"). Task 3's review flagged that both the
 * closure-binding contract AND the later-source-wins merge order were
 * asserted only in prose. This test resolves the real, app-container-bound
 * singleton, extends it exactly the way a future
 * `OrderWorkflowServiceProvider` will, and proves both facts against one
 * before/after comparison rather than an `assertInstanceOf` that would
 * pass even if `extend()` silently failed to take effect:
 *
 *   (a) the bound singleton accepts `$this->app->extend(...)` and the
 *       extended instance — not the original — is what `make()` returns
 *       afterwards;
 *   (b) a source appended AFTER the platform payload source
 *       (`PayloadNotificationVariableSource`, registered first in the
 *       provider) wins a key collision, matching
 *       `NotificationVariableResolverTest::
 *       test_a_later_source_overrides_an_earlier_one_for_the_same_key`,
 *       now proved across a real container `extend()` call instead of a
 *       resolver built directly by its constructor.
 *
 * If the provider ever regressed to binding this class in a way the
 * container can autowire (impossible today — the constructor is
 * `NotificationVariableSource ...$sources`, a variadic interface parameter
 * — but a future edit could replace the closure with something that tries),
 * the very first `$this->app->make()` call below would throw a
 * `BindingResolutionException` before either assertion runs, failing this
 * test loudly rather than silently.
 */
final class NotificationServiceProviderVariableResolverBindingTest extends TestCase
{
    public function test_the_bound_resolver_can_be_extended_and_the_appended_source_wins_on_collision(): void
    {
        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode(['order_id'], JSON_THROW_ON_ERROR)]);

        $row = new OutboxEvent;
        $row->setRawAttributes([
            'aggregate_type' => 'marketplace_order',
            'aggregate_id' => 'abc',
            // Flat, matching production shape: `Outbox::record()` stores
            // `data` directly as `payload`, never wrapped in a `data` key.
            'payload' => json_encode(['order_id' => 'from-payload'], JSON_THROW_ON_ERROR),
        ]);

        // Before extension: the provider registers only the platform
        // payload source, so the payload's own value renders unchanged.
        $beforeExtension = $this->app->make(NotificationVariableResolver::class)
            ->forOutboxRow($row, 'Marketplace order submitted', $version);

        self::assertSame(['order_id' => 'from-payload'], $beforeExtension);

        $appendedSource = new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'from-appended-source'];
            }
        };

        $this->app->extend(
            NotificationVariableResolver::class,
            static fn (NotificationVariableResolver $resolver, $app): NotificationVariableResolver => new NotificationVariableResolver(
                new PayloadNotificationVariableSource,
                $appendedSource,
            ),
        );

        $afterExtension = $this->app->make(NotificationVariableResolver::class)
            ->forOutboxRow($row, 'Marketplace order submitted', $version);

        self::assertSame(
            ['order_id' => 'from-appended-source'],
            $afterExtension,
            'a source registered after the platform payload source must win a key collision, proving the extended instance (not the original) is what the container now returns',
        );
    }
}
