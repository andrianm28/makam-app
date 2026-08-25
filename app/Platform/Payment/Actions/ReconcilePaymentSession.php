<?php

declare(strict_types=1);

namespace App\Platform\Payment\Actions;

use App\Platform\Payment\Checkout\Contracts\PaymentCheckoutClient;
use App\Platform\Payment\Checkout\PaymentStatusResult;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\ProcessWebhookEvent;
use App\Platform\Payment\ProcessWebhookEventOutcome;
use App\Platform\Payment\ProviderEventStatus;
use App\Platform\Payment\ProviderEventType;
use App\Platform\Payment\ReconciliationOutcome;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The reconciliation half of AC5's settlement path — closes the
 * single-point-of-failure a real incident exposed on 25 Aug 2026: before
 * this class, the ONLY way a payment ever settled on our side was an inbound
 * webhook (`ReceiveWebhook` -> `ProcessWebhookEvent` ->
 * `ApplyPaymentSettlement`). A real sandbox payment completed on the
 * provider's own side while the provider's webhook URL was misconfigured to
 * an unreachable domain, and sat at `AWAITING_PAYMENT` indefinitely —
 * discoverable only by a human checking the provider's own dashboard. This
 * class independently asks the provider "what is this payment's real
 * status?" via `PaymentCheckoutClient::fetchStatus()` (a server-to-server,
 * authenticated API call — never the customer's browser return URL) and, if
 * the provider reports a terminal outcome, settles through the EXACT SAME
 * path a real webhook uses.
 *
 * ---------------------------------------------------------------------------
 * One settlement path, not two — how this stays true
 * ---------------------------------------------------------------------------
 * This class does NOT duplicate `ApplyPaymentSettlement`'s logic. It
 * synthesizes a `provider_events` row shaped exactly like the one a real
 * webhook delivery would produce (same `provider_transaction_id`,
 * `invoice_reference`, `event_type` — sourced from the provider's OWN status
 * response, the same way `ReceiveWebhook::persist()` sources them from
 * `data.payment_id`/`data.order_id`/`event_type`), then hands the row's id to
 * `ProcessWebhookEvent`, the exact same claim-and-settle entry point
 * `Jobs\ProcessProviderEventJob` calls for a real delivery. Every idempotency
 * and financial-integrity guarantee `ProcessWebhookEvent`/
 * `ApplyPaymentSettlement` already provide — the `(provider,
 * provider_transaction_id)` apply-time claim, `ApplyPaidEffects`'s
 * already-paid no-op, the settlement-conflict audit trail — applies here
 * unchanged, because this class never bypasses them.
 *
 * ---------------------------------------------------------------------------
 * Distinguishing a reconciliation-sourced row from a real webhook delivery
 * ---------------------------------------------------------------------------
 * `event_id_source = 'reconciliation'` (a new value alongside
 * `ReceiveWebhook`'s `'svix-id'`/`'body-digest'`) marks the row's real origin
 * — an operator reading `provider_events` can always tell a polled
 * confirmation from a delivered one. `provider_event_id` is deterministic
 * (`'reconciliation:'.$providerPaymentId`), not random: a second
 * reconciliation attempt for the same provider payment (the on-return check
 * and the scheduled sweep racing, or a retried sweep) collides on
 * `(provider, provider_event_id)` and reuses the SAME row rather than
 * creating a second one — the same insert-then-catch-and-reuse shape
 * `ReceiveWebhook::resolveDuplicate()` established for real deliveries,
 * simplified here because there is no hostile input to classify, only a
 * benign race between two callers of this same class.
 *
 * If the real webhook arrives LATER, after a reconciliation attempt already
 * settled the same transaction: it carries a NEW `provider_event_id` (a real
 * `svix-id`), so its `ReceiveWebhook::persist()` insert does not collide on
 * event id — but it DOES carry the same `(provider, provider_transaction_id,
 * invoice_reference)` triple, which collides on `provider_events`' partial
 * unique index. `ReceiveWebhook::resolveDuplicate()`'s secondary-guard lookup
 * then finds THIS class's row, compares payload digests (they will not
 * match — the bytes differ), and rejects the real webhook as a duplicate
 * with a mismatched payload: an audited `REJECTED_PAYLOAD` row, acknowledged
 * with a 2xx (never an alarming error to the provider), and — critically —
 * the order stays correctly settled by the reconciliation that got there
 * first. No double-settlement, no thrown error, no false
 * `SettlementConflict` alarm.
 *
 * ---------------------------------------------------------------------------
 * Persist, then process — never nested in one transaction
 * ---------------------------------------------------------------------------
 * `findOrCreateEvent()` commits the `provider_events` row in its own
 * transaction; `ProcessWebhookEvent::__invoke()` is called AFTER that commits
 * and manages its own transaction boundary. This mirrors
 * `ReceiveWebhook::finishValidation()` dispatching `ProcessProviderEventJob`
 * only `->afterCommit()` — and deliberately avoids nesting this class's own
 * transaction inside `ProcessWebhookEvent`'s, which would make it a SAVEPOINT
 * whose rollback semantics differ from an outermost transaction (the exact
 * class of bug found and corrected elsewhere in this codebase's renewal
 * online-payment settlement path).
 */
final readonly class ReconcilePaymentSession
{
    /**
     * Provider-reported status VALUES this class recognises as terminal,
     * mapped to the `ProviderEventType` a real webhook would carry for the
     * same outcome. Provider-defined strings observed live in SumoPod's
     * sandbox dashboard (`completed`) plus the two ADR-0033 documents for
     * the failure/expiry legs; anything else (including `pending`, and any
     * value this class does not recognise) is treated as "not yet decided"
     * rather than guessed at.
     *
     * @var array<string, ProviderEventType>
     */
    private const array TERMINAL_STATUS_MAP = [
        'completed' => ProviderEventType::Completed,
        'paid' => ProviderEventType::Completed,
        'success' => ProviderEventType::Completed,
        'failed' => ProviderEventType::Failed,
        'expired' => ProviderEventType::Expired,
    ];

    private const string EVENT_ID_SOURCE_RECONCILIATION = 'reconciliation';

    public function __construct(
        private PaymentCheckoutClient $checkout,
        private ProcessWebhookEvent $process,
    ) {}

    public function __invoke(PaymentSession $session): ReconciliationOutcome
    {
        $current = SessionState::tryFrom((string) $session->state);

        // Cheap short-circuit: `ApplyPaymentSettlement::transitionToTerminal()`
        // would treat these as no-ops anyway, but skipping them here also
        // skips the outbound API call — no reason to ask the provider about a
        // session this system has already finished with.
        if ($current === SessionState::Paid
            || $current === SessionState::Failed
            || $current === SessionState::Expired) {
            return ReconciliationOutcome::AlreadyTerminal;
        }

        $status = $this->checkout->fetchStatus((string) $session->provider_payment_id);

        $eventType = self::TERMINAL_STATUS_MAP[strtolower(trim($status->status))] ?? null;

        if ($eventType === null) {
            return ReconciliationOutcome::StillPending;
        }

        $event = $this->findOrCreateEvent($session, $status, $eventType);

        $outcome = ($this->process)($event->getKey());

        return match ($outcome) {
            ProcessWebhookEventOutcome::Claimed => ReconciliationOutcome::Settled,
            ProcessWebhookEventOutcome::SettlementConflict => ReconciliationOutcome::SettlementConflict,
            ProcessWebhookEventOutcome::NotClaimable,
            ProcessWebhookEventOutcome::NotFound => ReconciliationOutcome::AlreadyReconciled,
        };
    }

    /**
     * Finds the reconciliation row for this provider payment if an earlier
     * attempt already created one, or creates it — see the class doc block
     * for the deterministic id that makes this idempotent under a race.
     */
    private function findOrCreateEvent(
        PaymentSession $session,
        PaymentStatusResult $status,
        ProviderEventType $eventType,
    ): ProviderEvent {
        $providerEventId = self::EVENT_ID_SOURCE_RECONCILIATION.':'.$status->paymentId;

        $existing = $this->findByEventId((string) $session->provider, $providerEventId);

        if ($existing instanceof ProviderEvent) {
            return $existing;
        }

        $rawPayload = json_encode([
            'reconciliation' => true,
            'source' => 'sumopod_status_api',
            'payment_id' => $status->paymentId,
            'order_id' => $status->orderId,
            'status' => $status->status,
            'amount_minor' => $status->amountMinor,
            'fee_minor' => $status->feeMinor,
            'net_amount_minor' => $status->netAmountMinor,
            'payment_method' => $status->paymentMethod,
            'completed_at' => $status->completedAt?->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        try {
            return DB::transaction(function () use ($session, $status, $eventType, $providerEventId, $rawPayload): ProviderEvent {
                return ProviderEvent::create([
                    'provider' => (string) $session->provider,
                    'provider_event_id' => $providerEventId,
                    'event_id_source' => self::EVENT_ID_SOURCE_RECONCILIATION,
                    'provider_transaction_id' => $status->paymentId,
                    'invoice_reference' => $status->orderId,
                    'event_type' => $eventType->value,
                    // The session's own merchant scope — the same value a
                    // real delivery's `{merchant}` route segment would carry
                    // for this session, since there is no inbound route
                    // segment here to read it from.
                    'merchant_ref' => (string) $session->merchant_ref,
                    'amount_minor' => $status->amountMinor,
                    'declared_currency' => (string) $session->currency,
                    'event_occurred_at' => $status->completedAt,
                    'raw_payload' => $rawPayload,
                    'payload_digest' => hash('sha256', $rawPayload),
                    'status' => ProviderEventStatus::Validated->value,
                    'received_at' => CarbonImmutable::now(),
                    'validated_at' => CarbonImmutable::now(),
                ]);
            });
        } catch (QueryException $exception) {
            // The benign race this class's own doc block names: two
            // reconciliation callers (on-return + the scheduled sweep)
            // resolving the same still-open session at nearly the same
            // moment. The loser's insert collides on the deterministic
            // `provider_event_id`; re-read the winner's row rather than
            // erroring — the same "collision is the idempotency mechanism"
            // stance `ReceiveWebhook::resolveDuplicate()` takes for real
            // deliveries.
            $existing = $this->findByEventId((string) $session->provider, $providerEventId);

            if ($existing instanceof ProviderEvent) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function findByEventId(string $provider, string $providerEventId): ?ProviderEvent
    {
        return ProviderEvent::query()
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->first();
    }
}
