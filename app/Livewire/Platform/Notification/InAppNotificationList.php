<?php

declare(strict_types=1);

namespace App\Livewire\Platform\Notification;

use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\Notification\Actions\MarkInAppNotificationRead;
use App\Platform\Notification\InAppNotificationInboxQuery;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Panel-agnostic in-app notification inbox list (task-5-brief.md) — mounted
 * today by the admin panel's `App\Filament\Admin\Pages\InAppNotifications`
 * page, written so a future vendor panel can mount the same component (the
 * brief's scope note: this branch has exactly ONE panel provider; the
 * vendor panel is a later lane).
 *
 * All scope filtering lives in `InAppNotificationInboxQuery` and all writes
 * in `Actions\MarkInAppNotificationRead` — house rule: no domain logic, no
 * queries, no authorization decisions in the Livewire component. This class
 * only reads the scoped inbox for the current actor and forwards the read
 * transition, both through the container-resolved services.
 *
 * Presentation copy comes from the views/partials, not from business state:
 * an unread marker uses `--mk-intent-info-*` (design-system §3.6 — a badge
 * with text, never a red dot), and delivery states render through
 * `DeliveryState::presentation()` — pending/unavailable are shown honestly
 * and a false "Terkirim" is impossible by construction, because the chip
 * labels come from the enum and only SENT/DELIVERED map to "Terkirim" (AC4).
 */
final class InAppNotificationList extends Component
{
    /**
     * The read transition for one row. The action re-verifies the actor's
     * scope on the record itself (throws `ModelNotFoundException` for an
     * out-of-scope id), so this component forwards no authorization claim.
     */
    public function markRead(int $notificationId): void
    {
        $actorRef = app(ScopeAssignmentResolver::class)->currentActorIdentifier();

        if ($actorRef === null) {
            return;
        }

        app(MarkInAppNotificationRead::class)(
            notificationId: $notificationId,
            actorRef: $actorRef,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );
    }

    public function render(): View
    {
        return view('filament.admin.notifications.in-app-notification-list', [
            'notifications' => app(InAppNotificationInboxQuery::class)
                ->forCurrentActor()
                ->with('deliveries')
                ->get(),
        ]);
    }
}
