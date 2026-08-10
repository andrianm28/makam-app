<?php

declare(strict_types=1);

namespace App\Platform\Notification\Actions;

use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\WhatsAppMode;
use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\Contracts\NotificationMatrixSource;
use App\Platform\Notification\Contracts\NotificationSubjectSource;
use App\Platform\Notification\DeliveryResult;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Jobs\SendNotificationChannelJob;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\Recipient;
use App\Platform\Notification\RecipientResolver;
use App\Platform\Notification\RecipientRole;
use App\Platform\Notification\RecipientRoleColumns;
use App\Platform\Notification\TemplateRenderer;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxQueueName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Task 3 of the L2 `platform-notifications` lane
 * (`docs/superpowers/plans/2026-08-09-platform-notifications.md`,
 * `task-3-brief.md`). The outbox-fed dispatch path and its delivery-state
 * recording:
 *
 * - `consumeOutboxEvent()` — called by `Jobs\ConsumeOutboxNotificationJob`.
 *   Bridges the outbox envelope's MACHINE event name to the matrix's row
 *   LABEL via `notification_templates.outbox_event_name` (D2), resolves
 *   recipients, records `notification_events`/`notification_recipients`/
 *   `notification_deliveries`/`in_app_notifications` in ONE transaction,
 *   then dispatches one `Jobs\SendNotificationChannelJob` per queued
 *   delivery AFTER that transaction commits.
 * - `sendViaChannel()` — called by `Jobs\SendNotificationChannelJob`. The
 *   ONLY place that updates a `notification_deliveries` row's state after
 *   its initial insert. Never lets a `Contracts\Channel::send()` exception
 *   propagate (AC5: channel failure never changes business state, and
 *   never fails the queue-visible consumer path either).
 *
 * This class is the ONE write API for `notification_deliveries` — AC9 by
 * construction. No other class calls `NotificationDelivery::create()`/
 * `::insert()`/`DB::table('notification_deliveries')->insert*()`; see
 * `tests/Unit/Platform/Notification/NotificationDeliveryWriteApiTest.php`.
 */
final class DispatchNotification
{
    /**
     * AC7's unconditional in-app roles — task-3-brief.md D4: "every
     * resolved recipient whose role is PLATFORM_ADMIN, CEMETERY_OPERATOR or
     * VENDOR gets an in_app_notifications row, even when its cell carries
     * no IN_APP token." Deliberately does NOT include CASE_MANAGER (no
     * column reads anything but TBD today, so it never resolves in
     * practice, and AC7's own list of three roles does not name it either).
     *
     * @var list<string>
     */
    private const array UNCONDITIONAL_IN_APP_ROLES = [
        RecipientRole::PLATFORM_ADMIN,
        RecipientRole::CEMETERY_OPERATOR,
        RecipientRole::VENDOR,
    ];

    /**
     * task-3-brief.md D4: the legend defines four tokens, but only EMAIL
     * and WA back a real `Contracts\Channel` implementation / a real
     * `notification_deliveries.channel` value — IN_APP is handled entirely
     * by the unconditional in-app rule above, and MANUAL never appears in
     * any current matrix cell (see this task's report). Scanning only these
     * two here is a deliberate scope limit, not an oversight of the other
     * two legend tokens.
     *
     * @var list<string>
     */
    private const array DISPATCHABLE_CHANNEL_TOKENS = ['EMAIL', 'WA'];

    public function __construct(
        private readonly NotificationMatrixSource $matrixSource,
        private readonly RecipientResolver $recipientResolver,
        private readonly NotificationSubjectSource $subjectSource,
        private readonly TemplateRenderer $renderer,
        private readonly ModeResolver $modeResolver,
        private readonly RecordInAppNotification $recordInAppNotification,
    ) {}

    /**
     * Entry point for `Jobs\ConsumeOutboxNotificationJob`. Takes the outbox
     * event id (a string), re-reads `OutboxEvent` itself — the same
     * re-fetch-fresh-state pattern `PublishOutboxEventJob::handle()` uses
     * and documents — rather than trusting a possibly-stale envelope passed
     * in by the caller.
     */
    public function consumeOutboxEvent(string $outboxEventId): void
    {
        $outboxRow = OutboxEvent::query()->find($outboxEventId);

        if ($outboxRow === null) {
            // Claimed/published, then the row vanished before this job ran
            // — nothing in this codebase deletes outbox_events rows today,
            // but this stays correct even if that ever changes.
            return;
        }

        $template = DB::table('notification_templates')
            ->where('outbox_event_name', $outboxRow->event_name)
            ->first();

        if ($template === null) {
            // task-3-brief.md D1: "an outbox event is notification-
            // classified iff a notification_templates row exists whose
            // outbox_event_name equals the envelope's event_name." The
            // listener that dispatches this job already filtered on this,
            // but this job stays correct even if invoked directly (tests,
            // or a stale queued job after the mapping changed underneath
            // it) — no matching row means nothing is sent, silently and
            // correctly.
            return;
        }

        $queuedDeliveryIds = [];

        DB::transaction(function () use ($outboxRow, $template, &$queuedDeliveryIds): void {
            // AC8: the idempotency anchor for the WHOLE per-event pipeline.
            // insertOrIgnore (not a SELECT-then-INSERT pre-check, D8) races
            // safely against a concurrent redelivery of the same outbox
            // event: only one transaction's INSERT wins the
            // notification_events.event_id primary key, the other affects
            // zero rows and returns below having written nothing.
            $inserted = DB::table('notification_events')->insertOrIgnore([[
                'event_id' => $outboxRow->getKey(),
                'event_name' => $outboxRow->event_name,
                'matrix_event_name' => $template->event_name,
                'aggregate_type' => $outboxRow->aggregate_type,
                'aggregate_id' => $outboxRow->aggregate_id,
                'trace_id' => $outboxRow->trace_id,
                'consumed_at' => CarbonImmutable::now(),
            ]]);

            if ($inserted === 0) {
                // Already fully processed by a previous delivery of this
                // same outbox event — every write this method makes is
                // inside this one transaction, so an existing row can only
                // mean a prior run already committed the whole pipeline.
                // Full no-op.
                return;
            }

            $subject = $this->subjectSource->subjectFor($outboxRow->aggregate_type, $outboxRow->aggregate_id);

            if ($subject === null) {
                // task-3-brief.md D3: an unmapped aggregate type or a
                // missing row. Never an error — the notification_events row
                // above stands, recorded with zero recipients.
                Log::warning('Notification dispatch: no subject source for this aggregate, resolving no recipients.', [
                    'aggregate_type' => $outboxRow->aggregate_type,
                    'aggregate_id' => $outboxRow->aggregate_id,
                ]);

                return;
            }

            $recipients = $this->recipientResolver->resolve($template->event_name, $subject);

            if ($recipients->isEmpty()) {
                return;
            }

            $matrixRow = $this->matrixSource->forEvent($template->event_name);
            $matrixRecipients = $matrixRow['recipients'] ?? [];

            $version = $template->active_version_id !== null
                ? NotificationTemplateVersion::query()->find($template->active_version_id)
                : null;

            // D6: every seeded version has an empty variable_allowlist and
            // no {{ placeholder }} in its body — render($version, []) is
            // the only call that can ever succeed against this data.
            // Rendering here, inside the transaction, fails fast (rolls
            // back this event's recording) if a template can never be
            // rendered, rather than queuing deliveries for content that
            // cannot be produced.
            $rendered = $version !== null ? $this->renderer->render($version, []) : null;

            $whatsAppMode = $this->modeResolver->whatsAppMode();

            $deliveryRows = [];

            foreach ($recipients->all() as $recipient) {
                $recipientId = DB::table('notification_recipients')->insertGetId([
                    'event_id' => $outboxRow->getKey(),
                    'recipient_ref' => (string) $recipient->actorRef,
                    'actor_role' => $recipient->actorRole,
                    'scope_entity_type' => $recipient->scopeEntityType,
                    'scope_entity_id' => $recipient->scopeEntityId !== null ? (string) $recipient->scopeEntityId : null,
                ]);

                if (in_array($recipient->actorRole, self::UNCONDITIONAL_IN_APP_ROLES, true)) {
                    ($this->recordInAppNotification)(
                        $outboxRow->getKey(),
                        $recipient,
                        $rendered['subject'] ?? null,
                        $rendered['body'] ?? '',
                    );
                }

                if ($version === null) {
                    // No renderable content — nothing external can be
                    // queued for this recipient, but their in-app row (if
                    // any, above) is already recorded.
                    continue;
                }

                foreach ($this->dispatchableChannelsFor($matrixRecipients, $recipient) as $channel) {
                    $unavailable = $channel === 'WA' && $whatsAppMode === WhatsAppMode::EmailInAppFallback;

                    $deliveryRows[] = [
                        'event_id' => $outboxRow->getKey(),
                        'notification_recipient_id' => $recipientId,
                        'recipient_ref' => (string) $recipient->actorRef,
                        'channel' => $channel,
                        // D8: degenerate today — every one of the 6
                        // outbox-mapped events is transactional, so the
                        // outbox event id is itself the window.
                        'window_key' => $outboxRow->getKey(),
                        'state' => ($unavailable ? DeliveryState::Unavailable : DeliveryState::Queued)->value,
                        'template_version_id' => $version->id,
                        'attempt_count' => 0,
                        'created_at' => CarbonImmutable::now(),
                        'updated_at' => CarbonImmutable::now(),
                    ];
                }
            }

            if ($deliveryRows !== []) {
                // D8: insert-ignoring-conflicts, not a pre-check — belt and
                // braces alongside the notification_events anchor above.
                DB::table('notification_deliveries')->insertOrIgnore($deliveryRows);
            }

            $queuedDeliveryIds = DB::table('notification_deliveries')
                ->where('event_id', $outboxRow->getKey())
                ->where('state', DeliveryState::Queued->value)
                ->pluck('id')
                ->all();
        });

        // Dispatched AFTER the transaction commits — a delivery row queued
        // for a per-channel job must already be durable before that job can
        // run (QUEUE_CONNECTION=sync in tests would otherwise run the
        // channel job before this transaction's COMMIT is even visible).
        foreach ($queuedDeliveryIds as $deliveryId) {
            SendNotificationChannelJob::dispatch((int) $deliveryId)->onQueue(OutboxQueueName::Notifications->value);
        }
    }

    /**
     * Entry point for `Jobs\SendNotificationChannelJob`. AC5: a throwing
     * (or otherwise failing) `$channel` NEVER propagates out of this
     * method — it is recorded as a `Failed` delivery outcome instead. The
     * mutation that produced the originating outbox event is, by
     * construction, in an entirely separate, already-committed transaction
     * (the outbox pattern's whole point) and is never touched from here.
     */
    public function sendViaChannel(int $deliveryId, Channel $channel): void
    {
        $delivery = NotificationDelivery::query()->find($deliveryId);

        if ($delivery === null || $delivery->state !== DeliveryState::Queued) {
            // Vanished, or not in a state this method is responsible for
            // (e.g. UNAVAILABLE rows are never dispatched to a channel job
            // in the first place; a QUEUED row already resolved by a prior
            // run of this same job is left alone rather than re-sent).
            return;
        }

        $version = NotificationTemplateVersion::query()->find($delivery->template_version_id);

        if ($version === null) {
            $this->recordChannelOutcome($delivery, new DeliveryResult(
                DeliveryState::Failed,
                message: 'Pinned notification_template_versions row is missing.',
            ));

            return;
        }

        try {
            $result = $channel->send($delivery, $version);
        } catch (Throwable $exception) {
            $this->recordChannelOutcome($delivery, new DeliveryResult(
                DeliveryState::Failed,
                message: mb_substr($exception->getMessage(), 0, 2000),
            ));

            return;
        }

        $this->recordChannelOutcome($delivery, $result);
    }

    private function recordChannelOutcome(NotificationDelivery $delivery, DeliveryResult $result): void
    {
        $delivery->forceFill([
            'state' => $result->state,
            'provider_ref' => $result->providerRef,
            'failure_message' => $result->message,
            'attempt_count' => $delivery->attempt_count + 1,
        ])->save();
    }

    /**
     * task-3-brief.md D4: scans `$recipient`'s own matrix cell (found via
     * `RecipientRoleColumns::columnFor()`) for the two dispatchable channel
     * tokens, whole-token, case-sensitive as written in the document. A
     * `none`/`TBD` recipient never reaches this method at all (they never
     * become a `Recipient` — `RecipientResolver` already resolves both to
     * no recipient); a role with no column, or a cell with neither token
     * (`optional status`, `confirmation`, `optional`, `Vendor when
     * allocated`, `Assigned vendor`, …), yields an empty list here.
     *
     * @param  array<string, string>  $matrixRecipients
     * @return list<string>
     */
    private function dispatchableChannelsFor(array $matrixRecipients, Recipient $recipient): array
    {
        $column = RecipientRoleColumns::columnFor($recipient->actorRole);

        if ($column === null) {
            return [];
        }

        $cell = $matrixRecipients[$column] ?? '';
        $channels = [];

        foreach (self::DISPATCHABLE_CHANNEL_TOKENS as $token) {
            if (preg_match('/\b'.$token.'\b/', $cell) === 1) {
                $channels[] = $token;
            }
        }

        return $channels;
    }
}
