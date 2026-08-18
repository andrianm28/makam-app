<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\FuneralCase\Actions\OpenFuneralCase;
use App\Domain\OrderWorkflow\Exceptions\UnroutableProductTypeException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderParty;
use App\Domain\OrderWorkflow\OrderPartyRole;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PreNeed\Actions\RegisterPreNeedInterest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * AC4 and AC5 — Task 3 of
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`.
 * Turns a booking draft into a commercial order, routes it by product type,
 * and is idempotent on a caller-supplied key.
 *
 * ---------------------------------------------------------------------------
 * Idempotency: the database decides, the fast path is only a courtesy
 * ---------------------------------------------------------------------------
 * Design-system §6.6 requires a duplicate submission to render the SAME
 * confirmation and never create a second order. On an order-creation path a
 * read-then-write check is a check-then-act race, and what it races to
 * create is a duplicate funeral order — so the guarantee is
 * `orders_idempotency_key_unq`, a real partial UNIQUE index
 * (`2026_08_12_100015_add_order_submission_identity.php`), and the losing
 * INSERT is translated back into the incumbent order.
 *
 * PRECEDENT: `App\Platform\DocumentVault\Actions\UploadDocument` and its
 * `client_upload_id` handling — a nullable client-supplied retry token on
 * the aggregate's own table, a partial unique index over the non-null rows,
 * an attempted insert, a narrow `QueryException` classifier, and a re-read
 * that returns the existing row instead of erroring. That shape is followed
 * here rather than reinvented. `ProcessWebhookEvent`'s claim was considered
 * and rejected as the model: it is a compare-and-set over rows that ALREADY
 * EXIST (`provider_events` is written before anything is known), which is a
 * different problem from stopping a second row being created at all — and
 * that class's own doc block explains at length why an index was
 * unavailable to it. The constraint is available here, so it is used.
 *
 * The pre-check `first()` before the insert exists only so the common
 * duplicate — a customer double-tapping the submit button seconds apart —
 * costs one SELECT instead of an INSERT, a rolled-back transaction, and a
 * SELECT. Deleting it would change performance, not correctness.
 *
 * NOT VERIFIED ON THIS HOST, stated rather than assumed: true CONCURRENT
 * submission cannot be exercised on this repository's hermetic
 * single-connection in-memory SQLite suite. What is proven here is the
 * sequential path and the presence of the index. Task 10 owns real
 * PostgreSQL 18 verification.
 *
 * ---------------------------------------------------------------------------
 * Ordering inside the transaction, and why it is not negotiable
 * ---------------------------------------------------------------------------
 * The routed record (funeral case or Pre-Need interest) is created BEFORE
 * the order, because `orders.funeral_case_id` / `pre_need_case_id` are set
 * at `create()` time and cannot be set later: `Order`'s write guard makes
 * `update()`, `fill()+save()` and every other model-level write throw
 * (`OrderIsGuardedException`), and `applyStatus()` — the one door left open
 * — writes the status column and nothing else. "Create the order, then link
 * the case" is not available, and the guard is not to be loosened to make it
 * available.
 *
 * The whole submission is one `DB::transaction()`, so a routed record can
 * never survive a failed order insert. That matters most on the duplicate
 * path: the losing submission of a race has already created a funeral case
 * by the time the unique index refuses its order, and the rollback is what
 * makes that case disappear rather than linger, ownerless, as a second case
 * for one order.
 *
 * ---------------------------------------------------------------------------
 * `MASUK` and its status event
 * ---------------------------------------------------------------------------
 * The order is created at `MASUK` through `create()`, the one path Task 2's
 * write guard deliberately leaves open, and its initial status event is
 * written by `RecordOrderStatusChange::initial()` — the sole-writer Action's
 * second entry point, added by this task. The transition entry point cannot
 * serve an event with no predecessor; read `initial()`'s own doc block for
 * why, and for the three preconditions that stand in for the transition
 * assertion. No status event row is written here, and `Order::applyStatus()`
 * is not called here.
 */
final readonly class SubmitBookingDraft
{
    /**
     * The actor recorded on the initial status event and its audit row.
     * Public submission has no authenticated operator behind it — the
     * customer is the actor, and they may be anonymous (`booking_drafts.
     * user_id` is nullable by design). `actor_ref` therefore names the
     * DRAFT, which is the only stable, non-identifying reference that
     * exists at this point; it is deliberately not an email, phone number,
     * or name (`AGENTS.md` §Observability — audit rows must not carry
     * restricted data).
     */
    private const string ACTOR_ROLE = 'customer';

    public function __construct(
        private RecordOrderStatusChange $recordStatusChange,
        private OpenFuneralCase $openFuneralCase,
        private RegisterPreNeedInterest $registerPreNeedInterest,
    ) {}

    public function __invoke(BookingDraft $draft, string $idempotencyKey): Order
    {
        $key = trim($idempotencyKey);

        if ($key === '') {
            throw new InvalidArgumentException('A booking submission requires a non-blank idempotency key.');
        }

        if ($draft->service_type === null) {
            throw new InvalidArgumentException(
                "Booking draft [{$draft->getKey()}] has no service type; there is nothing to route."
            );
        }

        $existing = $this->findByIdempotencyKey($key);

        if ($existing instanceof Order) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): Order => $this->submit($draft, $key));
        } catch (QueryException $exception) {
            if (! $this->isDuplicateIdempotencyKey($exception)) {
                throw $exception;
            }

            $incumbent = $this->findByIdempotencyKey($key);

            // The index fired but the winning row is not readable. That is
            // not a duplicate submission, it is a contradiction, so the
            // original database error is what propagates — the same
            // fail-loud choice `UploadDocument` makes when its own re-read
            // comes back empty.
            if (! $incumbent instanceof Order) {
                throw $exception;
            }

            return $incumbent;
        }
    }

    private function submit(BookingDraft $draft, string $idempotencyKey): Order
    {
        $productType = ProductType::fromBookingServiceType((string) $draft->service_type);

        // Created first — see the class doc block on ordering. Exactly one
        // of the two is ever non-null on a given order.
        $funeralCaseId = null;
        $preNeedCaseId = null;

        match ($productType) {
            ProductType::AT_NEED_SERVICE_ORDER => $funeralCaseId = ($this->openFuneralCase)($draft)->getKey(),
            ProductType::PRE_NEED_PLOT_PURCHASE => $preNeedCaseId = ($this->registerPreNeedInterest)($draft)->getKey(),
            // No default arm — see `UnroutableProductTypeException`.
            ProductType::FUNERAL_PROTECTION_MEMBERSHIP,
            ProductType::CARE_SUBSCRIPTION,
            ProductType::MARKETPLACE_PRODUCT_ORDER,
            ProductType::RENEWAL_ORDER => throw UnroutableProductTypeException::for($productType),
        };

        $order = Order::query()->create([
            'reference' => $this->nextReference(),
            'product_type' => $productType->value,
            // `create()` is the one path Task 2's write guard leaves open,
            // and it is the only way an order can begin at `MASUK`.
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draft->getKey(),
            'funeral_case_id' => $funeralCaseId,
            // Holds a `pre_need_interests` id. The column name is Task 2's
            // and the mismatch is explained in
            // `2026_08_12_100018_create_pre_need_interests_table.php`.
            'pre_need_case_id' => $preNeedCaseId,
            'idempotency_key' => $idempotencyKey,
        ]);

        // The status column now says `MASUK`; this is what makes the event
        // history agree with it. An order with a status and no event row is
        // the divergence Task 2 exists to prevent.
        ($this->recordStatusChange)->initial(
            order: $order,
            actorRef: "booking_draft:{$draft->getKey()}",
            actorRole: self::ACTOR_ROLE,
        );

        OrderParty::query()->create([
            'order_id' => $order->getKey(),
            // Null for an anonymous draft, which is a supported state.
            'user_id' => $draft->user_id,
            'role' => OrderPartyRole::PEMESAN->value,
            // Carried from the draft's own Step 6 fields
            // (`2026_08_12_100000_add_booking_draft_steps_6_to_8_fields.php`,
            // validated in `SaveBookingDraftStep`). Copied verbatim, never
            // defaulted or fabricated — a draft that never reached Step 6
            // (a service-catalogue-only submission, or a test fixture) has
            // these as null on the draft already, and null carries through
            // unchanged. This is the only reference a notification's
            // recipient resolution has for a guest customer's contact
            // details — see `Platform\Notification\Support\
            // OrderPartySubjectSupport`, which reads this row back.
            'full_name' => $draft->customer_full_name,
            'contact_phone' => $draft->customer_mobile,
            'contact_email' => $draft->customer_email,
            'address' => $draft->customer_address,
            'relationship_to_deceased' => $draft->customer_relationship,
            'preferred_contact_channel' => $draft->customer_contact_channel,
        ]);

        return $order;
    }

    private function findByIdempotencyKey(string $key): ?Order
    {
        return Order::query()->where('idempotency_key', $key)->first();
    }

    /**
     * The human-facing reference. `2026_08_12_100000_create_orders_table.php`
     * declares the column and its uniqueness and leaves generation to "a
     * later task"; this is it.
     *
     * Random rather than sequential, despite the migration's illustrative
     * `MK-2026-000123`. A zero-padded counter needs a sequence or a
     * `MAX()+1` read, and `MAX()+1` under concurrency is the same
     * check-then-act race the idempotency key is guarded against — with a
     * worse failure, because a reference collision rejects a submission that
     * is not a duplicate at all. `documents.storage_key` and the audit
     * layer's ids make the same "opaque, generated, unique" choice.
     *
     * A collision is possible in principle (36^8 ≈ 2.8e12 per year) and is
     * NOT swallowed: `orders.reference` is UNIQUE, so it surfaces as a
     * `QueryException` that `isDuplicateIdempotencyKey()` deliberately does
     * not match, and the whole submission rolls back rather than silently
     * returning someone else's order.
     */
    private function nextReference(): string
    {
        return 'MK-'.Carbon::now()->format('Y').'-'.Str::upper(Str::random(8));
    }

    /**
     * Same narrow-classifier discipline as
     * `RecordOrderStatusChange::isDuplicatePaidEvent()` and
     * `UploadDocument::isDuplicateClientUploadId()`, and narrow for the
     * reason those document at length: a `QueryException`'s message echoes
     * the INSERT's own column list, so matching a BARE column name would
     * classify a NOT NULL or length violation on `orders` as a duplicate
     * submission and hand the caller an unrelated order.
     *
     * PostgreSQL names the failing index directly
     * (`orders_idempotency_key_unq`) and is matched first. SQLite reports
     * the QUALIFIED `orders.idempotency_key` form, which appears only in its
     * constraint description and never in the unqualified INSERT column
     * list, and pairs it with the word "unique". A reference collision —
     * `orders.reference`, a different index — matches neither branch and so
     * propagates as the real error it is.
     */
    private function isDuplicateIdempotencyKey(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'orders_idempotency_key_unq')) {
            return true;
        }

        return str_contains($message, 'unique')
            && str_contains($message, 'orders.idempotency_key');
    }
}
