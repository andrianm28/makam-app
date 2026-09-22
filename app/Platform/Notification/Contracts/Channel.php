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
 * An implementation renders for itself rather than receiving pre-rendered
 * content, so it fully owns the moment of rendering closest to the actual
 * send. `$delivery->provider_idempotency_key` is deterministic for the
 * durable delivery identity and MUST be passed to the provider's
 * idempotency facility when the provider supports one.
 *
 * ---------------------------------------------------------------------------
 * How an implementation renders: resolve the bag, never hardcode `[]`
 * ---------------------------------------------------------------------------
 * This doc block used to say an implementation "is expected to call
 * `TemplateRenderer::render($version, [])`", on the grounds (task-3-brief.md
 * D6) that every seeded version had an empty `variable_allowlist`. That
 * ceased to be true when `2026_09_06_130000_add_v2_notification_templates_
 * for_zero_recipient_events.php` shipped allowlisted variables and
 * `2026_09_20_100000_add_v3_notification_templates_with_double_brace_
 * placeholders.php` shipped bodies that actually REFERENCE them. The real
 * contract is:
 *
 *     $rendered = $renderer->render(
 *         $version,
 *         $variables->forDelivery($delivery, $version),
 *     );
 *
 * where `$variables` is `NotificationVariableResolver`. `forDelivery()` is
 * the channel-shaped entry point: a channel holds only the
 * `NotificationDelivery`, so the resolver walks `$delivery->event_id` back
 * to the outbox row that carries the payload and rebuilds the same bag the
 * fresh-event render built, then restricts it to this version's allowlist.
 *
 * Why this is the safe call and `[]` is not. `render()` throws
 * `InvalidArgumentException` for a name that the body REFERENCES but the
 * caller did not supply, so hardcoding `[]` against a version whose body
 * references an allowlisted variable fails every send for that template:
 * `Jobs\SendNotificationChannelJob` catches the throw, records
 * `DeliveryResult::CHANNEL_SEND_FAILED`, and re-queues through
 * `Jobs\RetryFailedDeliveryJob` until its `MAX_ATTEMPTS` backoff is spent
 * and the delivery escalates — for every event of that kind, not one
 * unlucky one. Resolving the bag costs nothing against the templates `[]`
 * used to be safe for: `restrictToAllowlist()` returns exactly `[]` for a
 * version with an empty allowlist, so every seeded version-1 template
 * still renders byte-identically to before.
 */
interface Channel
{
    public function send(NotificationDelivery $delivery, NotificationTemplateVersion $version, RecipientSet $recipients): DeliveryResult;
}
