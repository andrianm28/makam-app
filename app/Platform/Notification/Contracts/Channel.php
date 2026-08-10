<?php

declare(strict_types=1);

namespace App\Platform\Notification\Contracts;

use App\Platform\Notification\DeliveryResult;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;

/**
 * task-3-brief.md D5: Task 3 creates this interface (the per-channel
 * dispatch job, `Jobs\SendNotificationChannelJob`, needs it to exist),
 * Task 4 creates and binds the real implementations
 * (`Channels\LogChannel`/`Channels\NullChannel`). No default binding is
 * registered by this lane's `Providers\NotificationServiceProvider` — see
 * that class's own doc block. Tests in this task bind a test double
 * directly (`tests/Fixtures/Notification/FakeChannel.php`).
 *
 * `$delivery->channel` (`EMAIL`/`WA`) tells an implementation which
 * provider to address; `$version` is the pinned, immutable template
 * snapshot to render (AC13) — an implementation is expected to call
 * `TemplateRenderer::render($version, [])` itself (every seeded version has
 * an empty `variable_allowlist`; task-3-brief.md D6) rather than receive
 * pre-rendered content, so it fully owns the moment of rendering closest to
 * the actual send.
 */
interface Channel
{
    public function send(NotificationDelivery $delivery, NotificationTemplateVersion $version): DeliveryResult;
}
