<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Actions;

use App\Domain\OrderWorkflow\Actions\CancelOrder;
use App\Domain\OrderWorkflow\Actions\CompleteOrder;
use App\Domain\OrderWorkflow\Actions\ExpireOrder;
use App\Domain\OrderWorkflow\Actions\GrantOrderPaymentOpening;
use App\Domain\OrderWorkflow\Actions\IssueOrderQuote;
use App\Domain\OrderWorkflow\Actions\ManualPaymentVerification;
use App\Domain\OrderWorkflow\Actions\MarkOrderPaid;
use App\Domain\OrderWorkflow\Actions\ProcessOrder;
use App\Domain\OrderWorkflow\Actions\RecordBuyerApproval;
use App\Domain\OrderWorkflow\Actions\RejectOrder;
use App\Domain\OrderWorkflow\Actions\RequestAvailability;
use App\Domain\OrderWorkflow\Actions\VerifyOrder;
use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderStatusBadge;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The ONE dynamic transition action: given a target `OrderStatus`, builds a
 * header `Filament\Actions\Action` for the record's view page. The view page
 * maps every allowed outgoing edge (`OrderTransition::allowedFrom()`) to one
 * of these — this factory never appears on the list page and is never
 * invoked for a transition the authorizer denies.
 *
 * ---------------------------------------------------------------------------
 * Transition names (canonical, shared with the authorizer)
 * ---------------------------------------------------------------------------
 * `TRANSITION_NAME` is the same vocabulary `OrderTransitionAuthorizer`'s
 * matrix keys on (`verify_order`, `request_availability`, ...). The plan
 * names these constants as shared between the authorizer and this action
 * mapping; the authorizer's own matrix (Task 1) is the canonical copy.
 *
 * ---------------------------------------------------------------------------
 * Two-layer enforcement, same shape as `FaqArticlesTable`'s custom actions
 * ---------------------------------------------------------------------------
 * `->authorize(fn (): bool => self::authorized($order, $to))` is the
 * RENDER/mount gate — it decides whether the button is drawn and whether
 * `mountAction()` accepts it. `self::run()` re-checks the SAME authorizer
 * (plus `ReauthenticationGuard::assertFresh()` on money transitions) as its
 * first act — the enforcement that survives a direct wire call, because "the
 * button was not rendered" is not a security property.
 *
 * The authorization check deliberately does NOT include the
 * re-authentication freshness check: `isAuthorized()` must answer "may this
 * actor attempt this transition" (finance may attempt a money transition —
 * the test suite pins this), while the freshness requirement is a
 * run-time gate answered inside `run()` (redirect to the MFA challenge when
 * stale).
 *
 * ---------------------------------------------------------------------------
 * Signature note — the six Task-2 domain Actions land at merge time
 * ---------------------------------------------------------------------------
 * This lane implements Tasks 3+4 in parallel with Lane A's Tasks 1+2
 * (merge order A → B). The six forward-path Actions this factory dispatches
 * to — `VerifyOrder`, `RequestAvailability`, `IssueOrderQuote`,
 * `RecordBuyerApproval`, `ProcessOrder`, `CompleteOrder` — therefore do not
 * exist on this branch yet; they are written against the plan's Task 2
 * signatures and resolve once Lane A lands. See the task report.
 */
final class TransitionOrderAction
{
    /**
     * The canonical transition name per target status — the shared
     * vocabulary with `OrderTransitionAuthorizer` and the domain Actions.
     *
     * @var array<string, string>
     */
    private const array TRANSITION_NAME = [
        'DIVERIFIKASI' => 'verify_order',
        'MENUNGGU_KETERSEDIAAN' => 'request_availability',
        'PENAWARAN_TERKIRIM' => 'issue_quote',
        'DISETUJUI_PEMESAN' => 'record_buyer_approval',
        'MENUNGGU_PEMBAYARAN' => 'authorize_payment_opening',
        'MENUNGGU_VERIFIKASI_PEMBAYARAN' => 'manual_payment_verification',
        'DIBAYAR' => 'mark_order_paid',
        'DIPROSES' => 'process_order',
        'SELESAI' => 'complete_order',
        'DITOLAK' => 'reject_order',
        'DIBATALKAN' => 'cancel_order',
        'KEDALUWARSA' => 'expire_order',
    ];

    /**
     * The money-touching transitions: role gate (finance/admin only, in
     * `OrderTransitionAuthorizer`) PLUS mandatory fresh re-authentication at
     * run time. Task 1's authorizer matrix keeps the full five-name money
     * list (including the marketplace/renewal transitions it must also
     * answer for); this resource can only ever trigger the three order
     * transitions, so those three are listed here — the other two are dead
     * names for this factory.
     *
     * @var list<string>
     */
    private const array MONEY_TRANSITIONS = [
        'authorize_payment_opening',
        'manual_payment_verification',
        'mark_order_paid',
    ];

    public static function make(OrderStatus $to, Order $order): Action
    {
        $transition = self::TRANSITION_NAME[$to->value] ?? null;

        $action = Action::make('transition_'.$to->value)
            ->label(BookingOrderStatusBadge::label($to))
            ->color(BookingOrderStatusBadge::color($to))
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi transisi')
            ->modalDescription('Transisi ini dicatat di audit.')
            ->authorize(fn (): bool => self::authorized($order, $to))
            ->action(fn (array $data) => self::run($order, $to, $data['reason'] ?? null));

        if ($to->requiresReason()) {
            $action->schema([Textarea::make('reason')->label('Alasan')->required()]);
        }

        if (in_array($transition, self::MONEY_TRANSITIONS, true)) {
            $action->color('warning')->icon(Heroicon::OutlinedShieldCheck);
        }

        return $action;
    }

    /**
     * The render/mount gate. Role check ONLY — deliberately not the
     * re-authentication freshness check, which is a run-time gate answered
     * in `run()` (a finance actor is authorized to ATTEMPT a money
     * transition; the re-authentication requirement then redirects them to
     * the challenge).
     */
    private static function authorized(Order $order, OrderStatus $to): bool
    {
        $transition = self::TRANSITION_NAME[$to->value] ?? null;

        if ($transition === null) {
            return false;
        }

        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(app(ActorContext::class), $transition);
        } catch (OrderActionNotAuthorisedException) {
            return false;
        }

        return true;
    }

    /**
     * The enforcement path: re-checks the authorizer, applies the
     * re-authentication gate to money transitions, then dispatches to the
     * owning domain Action. Every failure surfaces as a Filament
     * notification — state is only ever changed by the domain Action, never
     * by this class.
     */
    private static function run(Order $order, OrderStatus $to, ?string $reason): void
    {
        $actor = app(ActorContext::class);
        $transition = self::TRANSITION_NAME[$to->value];

        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition($actor, $transition);

            if (in_array($transition, self::MONEY_TRANSITIONS, true)) {
                app(ReauthenticationGuard::class)->assertFresh($actor);
            }
        } catch (ReauthenticationRequiredException) {
            session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'money_action');
            session()->put('url.intended', route('filament.admin.resources.booking-orders.view', ['record' => $order->getKey()]));

            Notification::make()
                ->warning()
                ->title('Perlu verifikasi ulang')
                ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                ->send();

            redirect()->route('filament.admin.pages.mfa-challenge');

            return;
        } catch (OrderActionNotAuthorisedException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        // `authorizeTransition()` throws for a null identity reference
        // before this line, so the cast cannot yield an empty reference.
        $actorRef = (string) $actor->identityReference;
        $actorRole = BookingOrderResource::auditRoleFor($actor);

        try {
            match ($to) {
                OrderStatus::DIVERIFIKASI => app(VerifyOrder::class)($order, $actorRef, $actorRole, $reason),
                OrderStatus::MENUNGGU_KETERSEDIAAN => app(RequestAvailability::class)($order, $actorRef, $actorRole, $reason),
                OrderStatus::PENAWARAN_TERKIRIM => app(IssueOrderQuote::class)($order, CarbonImmutable::now()->addDays(30), $actorRef, $actorRole, $reason),
                OrderStatus::DISETUJUI_PEMESAN => app(RecordBuyerApproval::class)($order, $actorRef, $actorRole, $reason),
                OrderStatus::MENUNGGU_PEMBAYARAN => app(GrantOrderPaymentOpening::class)($order, (int) $actorRef, $actorRef, $actorRole, $reason),
                OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN => app(ManualPaymentVerification::class)($order, $actorRef, $actorRole, $reason ?? 'Pembayaran manual dicatat.'),
                OrderStatus::DIBAYAR => app(MarkOrderPaid::class)($order, $actorRef, $actorRole, $reason),
                OrderStatus::DIPROSES => app(ProcessOrder::class)($order, $actorRef, $actorRole, $reason),
                OrderStatus::SELESAI => app(CompleteOrder::class)($order, $actorRef, $actorRole, $reason),
                OrderStatus::DITOLAK => app(RejectOrder::class)($order, $actorRef, $actorRole, $reason ?? ''),
                OrderStatus::DIBATALKAN => app(CancelOrder::class)($order, $actorRef, $actorRole, $reason),
                OrderStatus::KEDALUWARSA => app(ExpireOrder::class)($order, $actorRef, $actorRole, $reason),
            };

            Notification::make()->success()->title('Transisi berhasil dicatat.')->send();
            redirect()->route('filament.admin.resources.booking-orders.view', ['record' => $order->getKey()]);
        } catch (\Throwable $exception) {
            Notification::make()->danger()->title('Transisi gagal')->body($exception->getMessage())->send();
        }
    }
}
