<?php

declare(strict_types=1);

namespace App\Platform\Notification\Providers;

use App\Platform\Notification\Channels\LogChannel;
use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\Contracts\NotificationSubjectSource;
use App\Platform\Notification\Contracts\RecipientRoleSource;
use App\Platform\Notification\Listeners\DispatchNotificationConsumerOnOutboxEventPublished;
use App\Platform\Notification\NotificationDeliveryWriteGuard;
use App\Platform\Notification\ProvisionalAggregateNotificationSubjectSource;
use App\Platform\Notification\ProvisionalScopeEntityRecipientRoleSource;
use App\Platform\Outbox\Events\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this lane's (L2 `platform-notifications`) `Notification` bindings
 * and the outbox-consumption listener. Registered in
 * `bootstrap/providers.php` — see the comment added there (task-3-brief.md
 * D7) for why this file follows `Providers/` house style (matching
 * `FeatureGate`/`IdentityAccess`/`Correlation`) rather than the plan's flat
 * path.
 *
 * ---------------------------------------------------------------------------
 * Bindings this Task adds
 * ---------------------------------------------------------------------------
 * - `RecipientRoleSource` -> `ProvisionalScopeEntityRecipientRoleSource`
 *   (Task 2's seam; Task 2 shipped no provider of its own, relying on
 *   `RecipientResolver`'s own constructor default — this makes the binding
 *   explicit now that real dispatch work depends on it).
 * - `NotificationSubjectSource` -> `ProvisionalAggregateNotificationSubjectSource`
 *   (Task 3's seam, task-3-brief.md D3).
 *
 * `NotificationMatrixSource`, `TemplateRenderer`, `RecipientResolver`,
 * `Actions\DispatchNotification`, `Actions\RecordInAppNotification` need no
 * explicit binding — they are concrete classes with constructor-injectable
 * dependencies the container can already autowire (same minimalism as
 * `IdentityAccessServiceProvider` not binding
 * `LocalUsersTableIdentityAccessAdapter` itself). `App\Platform\FeatureGate\
 * ModeResolver` is already bound `scoped()` by `FeatureGateServiceProvider`.
 *
 * *** `Contracts\Channel` binding: *** Task 4 binds the development
 * `Channels\LogChannel`; tests may replace it with a deterministic test
 * double. `Channels\NullChannel` is a real `Channel` implementation but is
 * NOT bound here and is never invoked by the current dispatch flow: a
 * closed WA gate is recorded `UNAVAILABLE` directly by
 * `Actions\DispatchNotification::consumeOutboxEvent()` (AC12), which never
 * reaches the `Channel` boundary for that recipient/channel pair. It exists
 * as a ready-made binding target for a future channel that genuinely needs
 * to report `UNAVAILABLE` from inside `Channel::send()` (found during
 * Task 7a slice 3 review — see progress.md).
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecipientRoleSource::class, ProvisionalScopeEntityRecipientRoleSource::class);
        $this->app->bind(NotificationSubjectSource::class, ProvisionalAggregateNotificationSubjectSource::class);
        $this->app->bind(Channel::class, LogChannel::class);
    }

    public function boot(): void
    {
        Event::listen(OutboxEventPublished::class, DispatchNotificationConsumerOnOutboxEventPublished::class);

        // Fix round 1, IMPORTANT 1: runtime enforcement of AC9 — see
        // NotificationDeliveryWriteGuard's own doc block for why a
        // connection-hook-based guard replaced the original (vacuous) regex
        // test.
        NotificationDeliveryWriteGuard::register();
    }
}
