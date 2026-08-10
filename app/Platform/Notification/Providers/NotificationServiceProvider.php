<?php

declare(strict_types=1);

namespace App\Platform\Notification\Providers;

use App\Platform\Notification\Contracts\NotificationSubjectSource;
use App\Platform\Notification\Contracts\RecipientRoleSource;
use App\Platform\Notification\Listeners\DispatchNotificationConsumerOnOutboxEventPublished;
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
 * *** `Contracts\Channel` has NO binding here. *** task-3-brief.md D5:
 * Task 4 creates `Channels\LogChannel`/`Channels\NullChannel` and adds the
 * binding line to this class — until then, `Jobs\SendNotificationChannelJob`
 * cannot resolve in production (this task's own tests bind a test double
 * directly into the container, so they do not depend on that binding
 * existing). Same "not yet registered, flagged explicitly" precedent
 * `FeatureGateServiceProvider`'s own doc block set for its
 * then-unregistered `bootstrap/providers.php` line.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecipientRoleSource::class, ProvisionalScopeEntityRecipientRoleSource::class);
        $this->app->bind(NotificationSubjectSource::class, ProvisionalAggregateNotificationSubjectSource::class);
    }

    public function boot(): void
    {
        Event::listen(OutboxEventPublished::class, DispatchNotificationConsumerOnOutboxEventPublished::class);
    }
}
