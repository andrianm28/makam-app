<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Contracts\NotificationVariableSource;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\NotificationVariableResolver;
use App\Platform\Outbox\Models\OutboxEvent;
use Tests\TestCase;

/**
 * `Providers\NotificationServiceProvider` binds `NotificationVariableResolver`
 * as a closure singleton precisely so a later feature module can
 * `$this->app->extend(NotificationVariableResolver::class, ...)` and append
 * its own `NotificationVariableSource` (see that provider's own doc block,
 * and `.kiro/specs/platform-notifications/design.md` §"Template variables":
 * "a feature module registers its own [source] ... so the platform never
 * imports a Domain model"). Task 3's review flagged that both the
 * closure-binding contract AND the later-source-wins merge order were
 * asserted only in prose.
 *
 * Fix round 1 review correctly rejected this test's first version: its
 * `extend()` closure discarded the `$resolver` argument the provider
 * actually produced and hardcoded `new NotificationVariableResolver(new
 * PayloadNotificationVariableSource, $appendedSource)`. That proved only
 * the test file's own literal argument order, not the provider's real
 * registration order — a provider regression (e.g. registering the
 * platform payload source LAST relative to some other provider-level
 * source) would have passed unnoticed. This version instead calls
 * `$resolver->appending($appendedSource)` on the REAL resolver instance the
 * `extend()` closure receives, so whatever the provider actually built —
 * in whatever order — is what gets extended. `appending()` itself
 * (`NotificationVariableResolver`'s own method) always places the new
 * source strictly last, which is what guarantees a feature module's source
 * can override a platform-supplied key rather than the reverse; that
 * guarantee has its own direct unit coverage in
 * `NotificationVariableResolverTest::
 * test_appending_keeps_the_existing_source_and_lets_the_new_one_win_on_collision`,
 * proved failing when `appending()` was temporarily mutated to prepend
 * instead (see task-4-report.md's fix-round-1 section for that run's
 * output).
 *
 * Honesty note on scope: the provider registers exactly ONE source today
 * (`PayloadNotificationVariableSource`), so "the provider's own internal
 * ordering among several sources" is not yet something a test can
 * meaningfully break — that scenario only becomes constructible once a
 * second source is registered directly inside the provider's closure
 * (not merely appended via `extend()`), which is out of this task's scope.
 * What this test DOES lock in, against the real production binding: (a)
 * the bound singleton accepts `$this->app->extend(...)` and the extended
 * instance — not the original — is what `make()` returns afterwards, and
 * (b) a source appended via the real `$resolver` argument after the
 * platform payload source wins a key collision.
 *
 * This test's `make()` calls do depend on SOME explicit binding existing
 * for `NotificationVariableResolver` — its constructor is
 * `NotificationVariableSource ...$sources`, a variadic interface
 * parameter the container cannot autowire by reflection. If the provider's
 * binding were removed entirely (no closure, no `->instance()`, nothing
 * explicit), the first `$this->app->make()` call below would throw a
 * `BindingResolutionException` before either assertion runs. This test does
 * NOT discriminate between styles of explicit binding (a closure, an
 * `->instance()` call, or anything else all satisfy it equally, since
 * `extend()` operates on whatever is currently bound) — only that one
 * exists and supplies the payload source the baseline assertion depends on.
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
        // This assertion depends on the real production binding, not a
        // stand-in.
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
            static fn (NotificationVariableResolver $resolver, $app): NotificationVariableResolver => $resolver->appending($appendedSource),
        );

        $afterExtension = $this->app->make(NotificationVariableResolver::class)
            ->forOutboxRow($row, 'Marketplace order submitted', $version);

        self::assertSame(
            ['order_id' => 'from-appended-source'],
            $afterExtension,
            'a source appended (via the real resolver the provider produced) after the platform payload source must win a key collision',
        );
    }
}
