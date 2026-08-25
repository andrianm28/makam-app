<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Platform\FinancialLedger\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything `Actions\ApplyPaidEffects` needs to know about ONE payment
 * arrival, as an immutable value — Task 7 of
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`.
 *
 * The Action takes an `Order` and one of these and nothing else. That is
 * deliberate: it keeps the paid path callable and testable without a trigger
 * site being able to smuggle in a half-resolved provider payload. Today one
 * trigger site exists — `ApplyPaymentSettlement` resolves a real `Order`
 * through the webhook's invoice reference and builds the trigger for it (see
 * the Action's own doc block) — and the manual-verification trigger site is
 * still open, which is exactly why the value stays a plain constructor with
 * no provider types in it.
 *
 * ---------------------------------------------------------------------------
 * `businessKey`, and why it survived the ledger being dropped
 * ---------------------------------------------------------------------------
 * The plan named this field for `Journal::post()`'s unique business key. No
 * journal batch is posted (`task-7-brief.md` Ruling 1 — `Journal::post()`
 * requires an `entity_ref` badan usaha and no column chain reaches one from
 * an `Order`; `FIN-DEC-01` is TBD and a missing decision closes the gate
 * rather than authorizing a guess). The field stays because it is also the
 * natural idempotency key for the `payment.received.v1` outbox row, which is
 * what stops one trigger producing two customer notifications.
 *
 * Its source-prefix rule is enforced here with the same shape
 * `Journal::assertSourcePrefixed()` uses — a `:` with at least one character
 * before it — so `payment:{id}` and `manual_verify:{id}` stay
 * self-describing, and so a future ledger wiring inherits keys that are
 * already valid rather than having to rewrite history.
 *
 * ---------------------------------------------------------------------------
 * `actorRef` / `actorRole` — carried, never invented
 * ---------------------------------------------------------------------------
 * `Actions\RecordOrderStatusChange::__invoke()` takes `string $actorRef,
 * string $actorRole`, both NON-nullable, and writes them to
 * `order_status_events` and `audit_events`. An Action on a money path must
 * not manufacture an actor, so the trigger carries the one its caller
 * already established:
 *
 *   - the WEBHOOK path has no credential of ours behind it — `ProcessWebhookEvent`
 *     records `actorRole: 'provider'` with a null `actorRef` for exactly that
 *     reason. Null is not available here, so a webhook caller passes the
 *     provider event id as `actorRef`: it is the most specific true statement
 *     about who caused the transition, and it is already a reference rather
 *     than an identity claim. It is deliberately NOT defaulted to null or to
 *     a placeholder string — an absent actor on a paid transition should fail
 *     loudly at construction, not resolve to a fiction.
 *   - the MANUAL path passes the role `PaymentActionAuthorizer::authorize()`
 *     returned for the operator, and that operator's own actor reference.
 *
 * ---------------------------------------------------------------------------
 * `currency` — a documented addition to the plan's field list
 * ---------------------------------------------------------------------------
 * `Money` in this codebase is a bare integer minor-unit value with no
 * currency of its own, and the paid precondition has to compare currency as
 * well as minor units (`task-7-brief.md` §1c step 1). Without this field
 * there is nothing to compare the quote's `currency` against, so it is
 * carried explicitly rather than assumed to be the configured one.
 */
final readonly class PaidTrigger
{
    public function __construct(
        public PaidTriggerSource $source,
        public string $sourceId,
        public string $businessKey,
        public Money $amount,
        public string $currency,
        public CarbonImmutable $occurredAt,
        public string $actorRef,
        public string $actorRole,
    ) {
        if (trim($this->sourceId) === '') {
            throw new InvalidArgumentException('A paid trigger requires a non-blank source id.');
        }

        // Same rule as `App\Platform\FinancialLedger\Journal::
        // assertSourcePrefixed()`: a separator, and at least one character of
        // source before it.
        $separator = strpos($this->businessKey, ':');

        if ($separator === false || $separator < 1) {
            throw new InvalidArgumentException(
                "A paid trigger business key must be source-prefixed; [{$this->businessKey}] is not."
            );
        }

        if (trim($this->currency) === '') {
            throw new InvalidArgumentException('A paid trigger requires a non-blank currency.');
        }

        if (trim($this->actorRef) === '' || trim($this->actorRole) === '') {
            throw new InvalidArgumentException(
                'A paid trigger requires a non-blank actor reference and role; the paid path must not invent an actor.'
            );
        }
    }
}
