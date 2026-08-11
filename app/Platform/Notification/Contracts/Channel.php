<?php

declare(strict_types=1);

namespace App\Platform\Notification\Contracts;

use App\Platform\Notification\DeliveryResult;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\RecipientSet;

/**
 * task-3-brief.md D5: Task 3 created this interface for the per-channel
 * dispatch job. Task 4 supplies the provider-neutral implementations
 * `Channels\LogChannel` (bound, the dev/CI default) and `Channels\
 * NullChannel` (a real implementation, currently unbound and unreachable —
 * the WA-closed case never calls into a `Channel` at all; see `Channels\
 * NullChannel`'s own doc block). Tests may replace the default binding with
 * a test double (`tests/Fixtures/Notification/FakeChannel.php`).
 *
 * `$delivery->channel` (`EMAIL`/`WA`) tells an implementation which
 * provider to address; `$version` is the pinned, immutable template
 * snapshot to render (AC13); `$recipients` is the resolved record-scope set.
 * An implementation is expected to call `TemplateRenderer::render($version,
 * [])` itself (every seeded version has an empty `variable_allowlist`;
 * task-3-brief.md D6) rather than receive pre-rendered content, so it fully
 * owns the moment of rendering closest to the actual send.
 * `$delivery->provider_idempotency_key` is deterministic for the durable
 * delivery identity and MUST be passed to the provider's idempotency facility
 * when the provider supports one.
 */
interface Channel
{
    public function send(NotificationDelivery $delivery, NotificationTemplateVersion $version, RecipientSet $recipients): DeliveryResult;
}
