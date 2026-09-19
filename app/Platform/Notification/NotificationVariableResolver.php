<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Notification\Contracts\NotificationVariableSource;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplate;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Outbox\Models\OutboxEvent;

/**
 * Builds the variable bag for one `TemplateRenderer::render()` call.
 *
 * Two entry points, one per call-site shape: `DispatchNotification` holds
 * the fresh `OutboxEvent` row; a channel holds only the
 * `NotificationDelivery`, whose `event_id` IS the outbox row id
 * (`notification_events.event_id` references it, and
 * `notification_deliveries.event_id` references that). Both funnel into the
 * same merge-then-restrict path, so a channel re-render produces the same
 * bag the fresh-event render produced.
 *
 * Restricting to the version's `variable_allowlist` is what keeps every
 * version-1 template (empty allowlist, no placeholders) rendering: the
 * renderer rejects a SUPPLIED name that is not allowlisted, so an
 * unrestricted bag would have broken every seeded template at once.
 *
 * Sources are consulted in registration order and later keys win, so a
 * feature-module source (registered after the platform payload source)
 * can override a payload key with an authoritative value.
 */
final class NotificationVariableResolver
{
    /** @var list<NotificationVariableSource> */
    private readonly array $sources;

    public function __construct(NotificationVariableSource ...$sources)
    {
        $this->sources = array_values($sources);
    }

    /**
     * Returns a NEW resolver with `$source` appended AFTER every source
     * this resolver already holds, in their existing order — never
     * prepended. This is the exact shape a feature module's
     * `$this->app->extend(NotificationVariableResolver::class, ...)` call
     * (`Providers\NotificationServiceProvider`'s own doc block) is meant to
     * use: the closure receives the resolver the provider actually built
     * and calls `->appending($moduleSource)` on it, rather than
     * reconstructing a resolver from scratch and guessing at what the
     * provider registered. Because sources merge in registration order
     * with LATER keys winning, appending (never prepending) is what lets a
     * feature-module source override a platform-supplied key instead of
     * being silently overwritten by it, regardless of how many sources the
     * provider itself already registered or in what order.
     */
    public function appending(NotificationVariableSource $source): self
    {
        return new self(...[...$this->sources, $source]);
    }

    /**
     * @return array<string, scalar|\Stringable|null>
     */
    public function forOutboxRow(OutboxEvent $row, string $matrixEventName, NotificationTemplateVersion $version): array
    {
        $payload = $row->payload;

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $bag = [];

        foreach ($this->sources as $source) {
            if (! $source->handles((string) $row->aggregate_type)) {
                continue;
            }

            $bag = array_merge($bag, $source->variablesFor(
                $matrixEventName,
                (string) $row->aggregate_type,
                (string) $row->aggregate_id,
                is_array($payload) ? $payload : [],
            ));
        }

        return $this->restrictToAllowlist($bag, $version);
    }

    /**
     * @return array<string, scalar|\Stringable|null>
     */
    public function forDelivery(NotificationDelivery $delivery, NotificationTemplateVersion $version): array
    {
        $eventId = $delivery->event_id;

        if ($eventId === null || $eventId === '') {
            // A delivery with no outbox anchor has no payload to resolve
            // from. Returning early (rather than issuing a lookup on an
            // empty key) also keeps a channel usable against an in-memory
            // `NotificationDelivery` that was never persisted — which is
            // exactly how `Tests\Unit\Platform\Notification\
            // MailChannelTest` and `NotificationChannelsTest` drive the two
            // channels, with no database tables behind them at all.
            return [];
        }

        $row = OutboxEvent::query()->find((string) $eventId);
        $template = NotificationTemplate::query()->find($version->template_id);

        if ($row === null || $template === null) {
            return [];
        }

        return $this->forOutboxRow($row, (string) $template->event_name, $version);
    }

    /**
     * @param  array<string, mixed>  $bag
     * @return array<string, scalar|\Stringable|null>
     */
    public function restrictToAllowlist(array $bag, NotificationTemplateVersion $version): array
    {
        $allowlist = $version->variable_allowlist ?? [];

        if ($allowlist === []) {
            return [];
        }

        return array_intersect_key($bag, array_flip(array_map('strval', $allowlist)));
    }
}
