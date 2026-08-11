<?php

declare(strict_types=1);

namespace App\Platform\Notification\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Notification\InAppNotificationInboxQuery;
use App\Platform\Notification\Models\InAppNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The read transition (`read_at` null -> timestamp) plus its audit row —
 * task-5-brief.md Step 2. The ONLY writer of `in_app_notifications.read_at`
 * (the row itself is still created by `RecordInAppNotification`).
 *
 * ---------------------------------------------------------------------------
 * Scope is re-verified here, never trusted from the caller
 * ---------------------------------------------------------------------------
 * The notification is looked up by id and then checked against the actor's
 * scoped inbox (`InAppNotificationInboxQuery::forActor()`), so an
 * out-of-scope id throws `ModelNotFoundException` exactly like a non-existent
 * one — no existence leak, the same rule Step 1 applies to the list. A caller
 * (today, the Livewire inbox component) can therefore never cause a read on a
 * record the actor is not entitled to see.
 *
 * ---------------------------------------------------------------------------
 * One audit row per real transition, never per click
 * ---------------------------------------------------------------------------
 * The audit row is written only when the transition actually happens
 * (`read_at` was null). platform-audit AC4 pairs every COMMITTED STATE CHANGE
 * with its audit record; a repeat mark-read commits no state change, so it
 * writes no second audit row — the first transition's row is the durable
 * record of the read, and a spammed "mark read" button cannot flood
 * `audit_events`.
 *
 * `NOTIFICATION_READ` is deliberately NOT on `SensitiveActions::ACTIONS`
 * (task-5-brief.md: it is non-sensitive; adding it there would break that
 * list's exact-match test and exceed this lane's authorized growth), so no
 * `$reason` is required and none is accepted.
 */
final class MarkInAppNotificationRead
{
    public function __construct(
        private readonly InAppNotificationInboxQuery $inboxQuery,
    ) {}

    public function __invoke(
        int $notificationId,
        int|string $actorRef,
        string $actorRole,
        AuditSource $source,
    ): InAppNotification {
        $notification = InAppNotification::query()->find($notificationId);

        if ($notification === null) {
            throw (new ModelNotFoundException)->setModel(InAppNotification::class, [$notificationId]);
        }

        if (! $this->inboxQuery->forActor($actorRef)->whereKey($notificationId)->exists()) {
            throw (new ModelNotFoundException)->setModel(InAppNotification::class, [$notificationId]);
        }

        return DB::transaction(function () use ($notification, $actorRef, $actorRole, $source): InAppNotification {
            if ($notification->read_at === null) {
                $notification->forceFill(['read_at' => now()])->save();

                Audit::record(
                    action: 'NOTIFICATION_READ',
                    subject: new AuditSubject('in_app_notification', $notification->getKey()),
                    outcome: AuditOutcome::Allowed,
                    actorRef: $actorRef,
                    actorRole: $actorRole,
                    source: $source,
                );
            }

            return $notification;
        });
    }
}
