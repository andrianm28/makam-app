# L8 Seam Survey — Platform Infrastructure Available to Renewal Steps 4-6

**Purpose:** read-only survey of the merged platform seams an upcoming lane would consume to build the
public renewal journey steps 4 (fee/quote), 5 (payment), 6 (confirmation/invoice) plus the AC11
duplicate-period guard.

**Surveyed at:** worktree `/home/ubuntu/makam-app/.worktrees/platform-renewal-completion`,
branch `lane/l8-renewal-completion`, HEAD `0b9ce5e` ("Merge pull request #23 from
andrianm28/makam-app fix/audit-reason-and-notification-guard").

**Method:** direct file reads only. No test suite was run, no build was run. Every claim below carries a
`path:line` citation against the committed files at that HEAD. Where a fact could not be established
from the code, this document says **NOT FOUND** rather than inferring.

**All paths are relative to the worktree root** `/home/ubuntu/makam-app/.worktrees/platform-renewal-completion/`.

---

## Executive summary (read this before the detail)

The payment adapter is **deliberately, structurally incapable of taking a payment today**. It is a
fail-closed guard plus a webhook receiver plus two self-contained admin write paths. There is:

- no `CreatePaymentSession` action,
- no `PaymentProvider` interface or outbound provider integration,
- no reachable code path that inserts a `payment_sessions` row,
- no `payment.received.v1` outbox event, no paid-state event, no observer, no callback.

This is not an oversight to route around; it is an approved ruling (Wave 1b ruling 1b-L3-01) enforced in
three independent layers, with tests that assert the absence. See §A.1 and §A.5.

Consequently the biggest planning risk for the L8 lane is **not** "how do we extend the payment adapter
for a renewal subject" — it is "there is nothing to extend yet, and the thing that is missing is
upstream of payments entirely" (a `Quote`, a confirmation/reservation, an authorized opening, and a
merchant/`badan_usaha` binding — none of which exist in this repository).

---

# A. Payment adapter (`app/Platform/Payment/`)

## A.0 Governing documents

| Document | Path | Notes |
| --- | --- | --- |
| Plan doc | `docs/superpowers/plans/2026-08-09-platform-payment-adapter.md` | 340 lines. Lane L3, Wave 1. |
| Spec — requirements | `.kiro/specs/platform-payment-adapter/requirements.md` | 32 lines, AC1-AC14 + negative criteria. |
| Spec — design | `.kiro/specs/platform-payment-adapter/design.md` | 51 lines. |
| Spec — tasks | `.kiro/specs/platform-payment-adapter/tasks.md` | 65 lines, incl. four dated append-corrections. |

`requirements.md:5` states the dependency direction explicitly:

> **Status:** Foundation P0. Consumed by 7 specs. Blocks booking Step 8 and renewal Step 5.

So renewal Step 5 is a *named, known* downstream consumer of this spec, and this spec knows it is
blocking it.

## A.1 Public entry points to start a payment for an amount

### A.1.1 The only entry point that exists

**`App\Platform\Payment\GuardPaymentSession`** — `app/Platform/Payment/GuardPaymentSession.php:84`.

```
final readonly class GuardPaymentSession                       # :84
public function __construct(                                   # :86
    private ModeResolver $modes,                               # :87
    private ActorContextResolver $actors,                      # :88
    private CorrelationContext $correlation,                   # :89
) {}
public function __invoke(Money $requestedAmount): GuardResult  # :108
```

Constructor dependencies, all container-resolvable:
- `App\Platform\FeatureGate\ModeResolver`
- `App\Platform\IdentityAccess\ActorContextResolver`
- `App\Platform\Correlation\CorrelationContext`

**The single parameter is the amount, and nothing else.** `__invoke()` takes `Money $requestedAmount`
(`GuardPaymentSession.php:108`) and no subject, no payable, no reference, no order id, no
`PaymentMode`. The doc block at `GuardPaymentSession.php:75-83` states the mode-parameter absence is
deliberate and reflection-asserted:

> `ModeResolver::paymentMode()` is called HERE, inside the evaluation. No parameter of this class —
> constructor or `__invoke` — accepts a `PaymentMode`, a mode name, or a gate state, so no request
> input, no caller, and no config value can select it. `GuardPaymentSessionTest` asserts that by
> reflection, not by convention.

**`GuardPaymentSession::__invoke()` always returns a denial.** `GuardPaymentSession.php:106`:
`@return GuardResult always a denial — see the class doc block.`

### A.1.2 What identifies the thing being paid for

**NOT FOUND — there is no subject/payable/reference on the guard or on `payment_intents`.**

`payment_intents` `$fillable` (`app/Platform/Payment/Models/PaymentIntent.php:59-73`) is:
`requested_amount_minor`, `currency`, `payment_mode`, `decision`, `denied_condition`,
`denial_reason`, `missing_upstream`, `denied_conditions`, `public_message`, `actor_ref`,
`actor_role`, `correlation_id`, `evaluated_at`.

There is **no polymorphic subject, no payable morph, no order id, no reference string** on the intent.
A `payment_intents` row is a *decision record about an evaluation*, not a record of what was being
paid for.

`payment_sessions` (the row that would carry the binding) has `$fillable`
(`app/Platform/Payment/Models/PaymentSession.php:57-68`):
`payment_intent_id`, `provider`, `provider_payment_id`, `payment_link_url`, `amount_minor`,
`currency`, `merchant_ref`, `badan_usaha_ref`, `state`, `expires_at`.

Note: even `payment_sessions` has **no subject/order/payable column** — only `merchant_ref` and
`badan_usaha_ref` (AC13 merchant binding), which are NOT NULL
(`database/migrations/2026_08_09_100100_create_payment_sessions_table.php:73-74`). The only structural
link is `payment_intent_id` → `payment_intents` with `restrictOnDelete()`
(`...100100_create_payment_sessions_table.php:58-60`).

The **only** place in the whole module where "what is being paid for" is represented today is the
manual-fallback table, and there it is deliberately free text, not a key:

`database/migrations/2026_08_11_100000_create_payment_verifications_table.php:29-34`:
> `reference` is caller-supplied free text (an order/booking/invoice token) — NOT a foreign key. This
> is a deliberate, FLAGGED design call, not a settled production shape … A future task, once
> `app/Domain/OrderWorkflow/` exists, will very likely add a real foreign key and a migration to
> backfill/constrain this column.

Column declaration: `$table->string('reference', 191);`
(`...100000_create_payment_verifications_table.php:59`), indexed but **not unique** (`:91`).

The same free-text-pointer convention appears on reversals:
`database/migrations/2026_08_11_100010_create_payment_reversals_table.php:83` (`reference` free text),
with a real UNIQUE on `(reversal_type, reference)` at `:106`.

### A.1.3 Amount / currency representation

**Integer minor units everywhere. There is a `Money` value object; it is the type at the guard
boundary.**

- Value object: `App\Platform\FinancialLedger\Money` — `app/Platform/FinancialLedger/Money.php:16`,
  `final readonly class Money` with `public readonly int $minorUnits` (`:18`).
- The constructor **rejects non-int at runtime**: `Money.php:26-28` throws
  `\TypeError('Money minor units must be an integer.')`.
- `Money::fromDecimal(string $amount): int` (`Money.php:39`) converts a decimal *string*
  (note: it returns an `int`, not a `Money` — a slightly surprising signature worth knowing).
- `toMinorInt(): int` (`Money.php:73`), `add`/`subtract`/`negate`/`compare`/`isPositive`/`format`
  (`Money.php:78-120`).
- Currency is **not** carried on `Money`. It is read from config at the write site:
  `GuardPaymentSession.php:227` — `'currency' => (string) config('money.currency')`.
- Config: `config/money.php` — `'currency' => 'IDR'`, `'minor_units' => 2`.

Column types across the codebase (see also §D.3):
- `payment_intents.requested_amount_minor` — `bigInteger`
  (`database/migrations/2026_08_09_100000_create_payment_intents_table.php:69`)
- `payment_sessions.amount_minor` — `bigInteger`
  (`...2026_08_09_100100_create_payment_sessions_table.php:69`)
- `journal_entries.amount_minor` — `unsignedBigInteger` + `CHECK (amount_minor >= 0)`
  (`database/migrations/2026_08_09_110100_create_journal_entries_table.php:19,40-41`)

Eloquent casts are `'integer'`, deliberately never `decimal`:
`PaymentIntent.php:81-85` — "`integer`, never `float`/`decimal` — Wave 0 ruling 0c. A decimal cast
would return a string that arithmetic silently coerces to float".
Same at `PaymentSession.php:76-79`.

### A.1.4 Statuses that exist

**Four separate, deliberately non-overlapping closed lists.** They must not be merged.

1. **`App\Platform\Payment\SessionState`** — `app/Platform/Payment/SessionState.php:42`
   (backed enum, string): `CREATED` (`:48`), `AWAITING_PAYMENT` (`:55`), `PAID` (`:61`),
   `FAILED` (`:66`), `EXPIRED` (`:72`), `REFUNDED` (`:79`). Helpers `values()` (`:84`),
   `isKnown()` (`:89`), `assertKnown()` (`:98`).

   **Critical caveat, quoted from `SessionState.php:14-25`:**
   > "Payment-session scope, NOT order scope" … The order/invoice vocabulary (`DIBAYAR`,
   > `MENUNGGU_VERIFIKASI_PEMBAYARAN`, and every fulfillment state) belongs to the order aggregate,
   > which this module never writes. … Adding an order state to this enum would quietly merge the two
   > state machines the invariant separates.

   And `SessionState.php:30-33`: "Nothing writes a row carrying any of these values today."

2. **`App\Platform\Payment\PaymentIntentDecision`** — has **no `Allowed` case**. Per
   `.kiro/specs/platform-payment-adapter/tasks.md:39`:
   > `PaymentIntentDecision` deliberately has no `Allowed` case and the Postgres CHECK admits only
   > `'denied'`.

3. **`App\Platform\Payment\PaymentVerificationStatus`** — `SUBMITTED|VERIFIED|REJECTED`, a
   deliberately separate list (`database/migrations/2026_08_11_100000_create_payment_verifications_table.php:43-45`).

4. **`App\Platform\Payment\ProviderEventStatus`** — webhook-receiver statuses, incl. `Processing`,
   `Processed`, `ManualReview`, and `REJECTED_*` variants
   (referenced at `app/Platform/Payment/ProcessWebhookEvent.php:134-138`,
   `app/Platform/Payment/PaymentAuditActions.php:42-43`).

### A.1.5 What does NOT exist (verified absent)

Per `.kiro/specs/platform-payment-adapter/tasks.md:40`:
> **Not** implemented, deliberately: `CreatePaymentSession`, the `PaymentProvider` contract, and all
> provider/HTTP code.

And `tasks.md:65` (Task 8 disposition):
> there is still no `PaymentProvider` outbound integration anywhere in this repository — only the
> webhook *receiver* half (`WebhookController`, `SumoPodWebhookSignature`, `WebhookValidator`) is real
> — so "create a payment session against the sandbox" has no code path to exercise regardless of
> credentials.

Directory listing of `app/Platform/Payment/` confirms: no `CreatePaymentSession.php`, no
`Contracts/PaymentProvider.php`, no `Contracts/` directory at all.

## A.2 Return / cancel / verify flow, end to end

### A.2.1 Routes

**`routes/web.php`:**

| Line | Route | Controller | Middleware |
| --- | --- | --- | --- |
| `318` | `GET /pembayaran/kembali` → name `payments.return` | `PaymentReturnController` | (web default) |
| `319` | `GET /pembayaran/batal` → name `payments.cancel` | `PaymentCancelController` | (web default) |
| `342-344` | `POST /admin/payments/manual-verifications/{paymentVerification}/verify` → name `admin.payments.manual-verifications.verify` | `VerifyManualPaymentController` | `['web', 'auth', RequireRecentAuthentication::class.':payment_manual_verification,filament.admin.pages.mfa-challenge']` |
| `371-374` | `POST /admin/payments/reversals/{reversalType}` (constrained `whereIn('reversalType', ['refund','chargeback'])`) → name `admin.payments.reversals.record` | `RecordPaymentReversalController` | `['web', 'auth', RequireRecentAuthentication::class.':payment_reversal,filament.admin.pages.mfa-challenge']` |

**`routes/api.php:49-55`:** `POST payments/webhook/{merchant}` → `WebhookController`, name
`payments.webhook`, with a `throttle:payment-webhook` limiter
(`routes/api.php:34`) and merchant validated against `config('payment.webhook.merchants')`
(`routes/api.php:44`).

**Renewal routes today** (`routes/web.php:173-174`):
```
Route::get('/perpanjangan', RenewalStart::class)->name('perpanjangan.index');
Route::get('/perpanjangan/cari', GraveSearch::class)->name('perpanjangan.cari');
```
`routes/web.php:160-163` records the intended future paths: "`/perpanjangan` is Step 1-2 (city,
cemetery); `/perpanjangan/cari` is Step 3 (grave search) — steps 4-6 (fee, payment, confirmation) are
Sprint 13". `docs/superpowers/plans/2026-08-09-retrofit-renewal.md` (Review scope, quoting
`information-architecture.md` §1) names the planned route tree as
`/perpanjangan/{cari, permohonan/{renewalReference}, konfirmasi/{renewalReference}}`
(also quoted at `app/Livewire/Public/Renewal/GraveSearch.php:28-30`).

### A.2.2 `PaymentReturnController` and `PaymentCancelController` — they do nothing, and that is the feature

`app/Platform/Payment/Http/Controllers/PaymentReturnController.php:50-55`:
```
final class PaymentReturnController
{
    public function __invoke(): View
    {
        return view('payment.return');
    }
}
```

No constructor, no dependency, no model, no query, no action, no event. The doc block
(`PaymentReturnController.php:12-38`) is explicit that this is structurally enforced:

> the safety property here is an ABSENCE, and it is enforced structurally rather than by discipline:
> this controller has no constructor dependency, no model, no query, no action, no event. There is no
> object in scope through which a state transition, a journal post, an outbox emission, or a "paid"
> claim could be reached, and `Tests\Feature\Payment\PaymentReturnRouteTest` fails if any of those
> names so much as appears in this file's code.

`PaymentCancelController.php:30-36` is the exact mirror; its doc block (`:18-28`) argues the cancel
return is *equally* untrusted in the opposite direction.

**Implication for the L8 lane:** a renewal Step 6 "confirmation" screen may NOT read payment success
from the return URL, and may not add a lookup that infers success from arrival. Anything added here
must be a READ (`PaymentReturnController.php:46-48`).

### A.2.3 How a feature module learns a session became `PAID`

**NOT FOUND. There is no mechanism, because nothing can become paid.**

Evidence, in order of strength:

1. **No `payment_sessions` row can exist.** Three stacked layers, enumerated in
   `app/Platform/Payment/Models/PaymentSession.php:28-42`:
   - Layer 1: `App\Platform\Payment\GuardResult` "has no factory that produces an allowed result, so
     no code can even represent a pass" (`PaymentSession.php:31-33`).
   - Layer 2: "`CreatePaymentSession` and the `PaymentProvider` contract are not built — there is no
     creation path to call" (`PaymentSession.php:34-35`).
   - Layer 3: the model's own `creating` hook — `PaymentSession.php:84-89`:
     ```
     protected static function booted(): void
     {
         self::creating(function (self $session): void {
             throw PaymentSessionCreationUnavailableException::becauseGuardIsDenyOnly();
         });
     }
     ```
     The same doc block states plainly what layer 3 does *not* stop:
     `PaymentSession::query()->insert()`, `DB::table(...)->insert()`, raw SQL
     (`PaymentSession.php:37-42`).

2. **No paid-state event exists.** `grep -rn "payment.received" app/` returns only doc-block prose
   describing the event as unbuilt:
   - `app/Platform/Payment/Jobs/ProcessProviderEventJob.php:32` — "and the `payment.received.v1`
     outbox event. None of those is built".
   - `app/Platform/Notification/ProvisionalAggregateNotificationSubjectSource.php:41` —
     "`availability.*`/`quote.*`/`payment.received.v1` have no domain module at" (that time).

   There are **zero** `Outbox::record()` call sites anywhere under `app/Platform/Payment/`. The
   existing `Outbox::record()` call sites are `app/Domain/Booking/Actions/SaveBookingDraftStep.php:133`,
   `app/Domain/Booking/Actions/StartBookingDraft.php:54`,
   `app/Platform/DocumentVault/Actions/RecordDocumentAccess.php:75`,
   `app/Platform/DocumentVault/Actions/UploadDocument.php:270`,
   `app/Platform/FeatureGate/GateActivationRecorder.php:173`.

3. **The webhook processor deliberately stops before applying.**
   `app/Platform/Payment/ProcessWebhookEvent.php:20-41` — the class "claims, it does not apply":
   > The plan's original Task 4 was almost entirely downstream of a created `payment_sessions` row:
   > the `PAID` transition, the domain `DIBAYAR` state, the same-transaction `Journal::post()`, and
   > the `payment.received.v1` outbox emission. … None of those effects has anything to act on …
   > So this class implements the two claims that ARE reachable, and stops. It deliberately does NOT
   > mark a row `PROCESSED`.

4. **`.kiro/specs/platform-payment-adapter/tasks.md:49`** lists as still NOT TESTED:
   "the session-dependent paid/apply path, Journal write, order `DIBAYAR` transition, outbox effects,
   and live provider delivery."

**The mechanism that DOES exist, for the manual path only:** a `payment_verifications` row's own
`status` column, moved `SUBMITTED → VERIFIED|REJECTED` by
`App\Platform\Payment\VerifyManualPayment::verify()`
(`app/Platform/Payment/VerifyManualPayment.php:79-114`). There is **no event, no observer, no
listener, and no callback** on that transition — only the row and an `audit_events` row. A consumer
would have to **poll / query** `payment_verifications` by its free-text `reference`
(`payment_verifications_reference_idx`,
`database/migrations/2026_08_11_100000_create_payment_verifications_table.php:91`).

**When the outbox path is eventually built, this is the consumption shape** (existing, real, merged):
`Outbox::record()` → `PublishOutboxEventJob` fires `App\Platform\Outbox\Events\OutboxEventPublished`
→ listener registered at `app/Platform/Notification/Providers/NotificationServiceProvider.php:67`:
```
Event::listen(OutboxEventPublished::class, DispatchNotificationConsumerOnOutboxEventPublished::class);
```
`Outbox::record()` signature — `app/Platform/Outbox/Outbox.php:87-96`:
```
public static function record(
    string $eventName,
    int $eventVersion,
    string $aggregateType,
    int|string $aggregateId,
    array $data,
    OutboxClassification $classification,
    ?string $idempotencyKey = null,
): OutboxEvent
```
with `PayloadClassification::assertSafe($data)` enforced at `Outbox.php:97`.

## A.3 Manual-payment fallback path

### A.3.1 Submission (customer side)

**`App\Platform\Payment\SubmitManualPayment`** — `app/Platform/Payment/SubmitManualPayment.php:42`.

```
public function __construct(private UploadDocument $uploadDocument) {}   # :44-46
public function submit(
    string $reference,               # :55
    string $paymentMethod,           # :56
    string $paymentReference,        # :57
    ?string $instructions,           # :58
    UploadedFile|StreamInterface|null $proofFile,  # :59
    int|string|null $actorRef,       # :60
    string $actorRole,               # :61
    AuditSource $source,             # :62
    ?string $clientUploadId = null,  # :63
    array $proofMeta = [],           # :64
): PaymentVerification               # :65
```

- Creates the row at `SUBMITTED` via `PaymentVerification::createSubmitted()`
  (`SubmitManualPayment.php:80`).
- Proof file goes through the document vault's quarantine-first seam, `DocumentKind::PaymentProof`,
  with `ownerType = 'payment_verification'` and `ownerId = $verification->id`
  (`SubmitManualPayment.php:88-95`). Only `documents.id` is stored — never file content
  (`SubmitManualPayment.php:26-28`, and `attachProof()` at
  `app/Platform/Payment/Models/PaymentVerification.php:114-124`).
- Audit action `PAYMENT_MANUAL_SUBMITTED` with `AuditOutcome::Allowed`
  (`SubmitManualPayment.php:102-104`). **Not** on `SensitiveActions::ACTIONS`, so **no reason is
  required** (`app/Platform/Payment/PaymentAuditActions.php:96-101`).
- **No authorization check of any kind.** This is a customer self-service write path.

### A.3.2 Verification (admin side) — by whom, and what authorization

**`App\Platform\Payment\VerifyManualPayment::verify()`** —
`app/Platform/Payment/VerifyManualPayment.php:79-86`:
```
public function verify(
    PaymentVerification $verification,
    PaymentVerificationDecision $decision,
    string $reason,
    int|string|null $actorRef,
    string $actorRole,
    AuditSource $source,
): PaymentVerification
```

**HTTP wiring:** `app/Platform/Payment/Http/Controllers/VerifyManualPaymentController.php:55-86`.

The authorization actually enforced, in order:

1. **`auth` middleware** — must be an authenticated session (`routes/web.php:343`).
2. **`RequireRecentAuthentication:payment_manual_verification,filament.admin.pages.mfa-challenge`** —
   AC9's recent-re-authentication freshness gate (`routes/web.php:343`). This is the middleware's
   *second* real attachment in the repo; the first is `/admin/mfa/disable`
   (`VerifyManualPaymentController.php:20-27`).
3. **`ReauthenticationService::satisfy()`** is called first inside the controller to close out the
   challenge's audit trail (`VerifyManualPaymentController.php:59-64`), with a hardcoded
   `actorRole: 'authenticated_actor'` (`:61`).
4. **Mandatory reason**, validated at the HTTP boundary with
   `['required', 'string', new NonBlankReason]` (`VerifyManualPaymentController.php:71`) and enforced
   authoritatively inside the transaction by `Audit::record()`'s
   `SensitiveActions::requiresReason('PAYMENT_MANUAL_VERIFICATION')` check
   (`app/Platform/Audit/Audit.php:104-106`; the action is on the list at
   `app/Platform/Audit/SensitiveActions.php:35`).
5. **Decide-exactly-once**, under a row lock — see §A.4.

> ### ⚠ FINDING: there is NO role check on manual verification or on reversals.
>
> Neither `VerifyManualPaymentController` nor `RecordPaymentReversalController` reads
> `ActorContext::$roles`, calls any `*Authorizer`, or checks `ActorRole` in any way. Both hardcode
> `actorRole: 'authenticated_actor'` — a sentinel that `ActorRole`'s own doc block says means
> "no role applies" (`app/Platform/IdentityAccess/Roles/ActorRole.php:44-55`).
>
> Verified by reading both controllers in full
> (`VerifyManualPaymentController.php:55-86`, `RecordPaymentReversalController.php:60-107`) and by
> `grep -rn "ActorRole\|hasRole\|authorize(" app/Platform/Payment/` returning no authorization call
> sites in either file.
>
> Effect as shipped: **any authenticated user who can satisfy an MFA re-authentication challenge can
> approve a manual payment verification or record a refund/chargeback.** The re-auth middleware
> proves *freshness of session*, not *entitlement*. This directly bears on §C.3 — the L8 lane's
> "mark an external renewal with evidence" action would, if it copied this precedent verbatim,
> inherit the same gap. Flagged for escalation, not resolved here.

### A.3.3 Reversals

`ReversalService::record()` — `app/Platform/Payment/ReversalService.php:33-41` — a thin dispatcher
over `Actions\RecordRefund` / `Actions\RecordChargeback` by `PaymentReversalType`
(`ReversalService.php:42-59`). Audit actions `PAYMENT_REFUND` / `PAYMENT_CHARGEBACK`, both on
`SensitiveActions::ACTIONS` (`app/Platform/Audit/SensitiveActions.php:83-84`), so both require a
non-blank reason. Controller: `RecordPaymentReversalController.php:60-107`; same three-step shape as
manual verification.

Per `.kiro/specs/platform-payment-adapter/tasks.md:61`, reversals deliberately do **not** post a
journal reversal batch, call any `PaymentProvider::refund()`, or touch customer balance:
"`Journal::postReversal()` has zero call sites in this repo".

## A.4 Guards, invariants, preconditions constraining a caller

| # | Invariant | Enforcing code | Notes |
| --- | --- | --- | --- |
| 1 | **Six-condition guard is the only path to a session; five conditions deny unconditionally** | `app/Platform/Payment/GuardPaymentSession.php:140-191` | Conditions 2-6 return `UnavailableUpstream` via `$this->unavailable(...)` (`:157-189`). Condition 1 is genuinely evaluated against `G-PAY-01` (`:149-155`). |
| 2 | **No allowed `GuardResult` can be constructed** | `GuardPaymentSession.php:31-33` (describing `GuardResult`) | "no factory that produces an allowed result, so no code can even represent a pass". |
| 3 | **`PaymentSession` refuses every insert** | `app/Platform/Payment/Models/PaymentSession.php:84-89` | `creating` hook throws `PaymentSessionCreationUnavailableException::becauseGuardIsDenyOnly()`. |
| 4 | **`payment_intents` is append-only** | `app/Platform/Payment/Models/PaymentIntent.php:111-132` | `update()`, `performUpdate()`, `delete()` all throw `PaymentIntentIsImmutableException`. |
| 5 | **`payment_intents.decision` closed list, model-level** | `PaymentIntent.php:91-103` | `saving` hook rejects anything not in `PaymentIntentDecision::values()` — makes the Postgres CHECK real on SQLite too. |
| 6 | **Every guard evaluation writes exactly one intent row + one audit event, in one transaction** | `GuardPaymentSession.php:224-265` (`Audit::wrap`) | Ruling 1b-L3-01 Step 3. |
| 7 | **One provider payment id ↔ one session** (AC7) | `database/migrations/2026_08_09_100100_create_payment_sessions_table.php:86` | `$table->unique(['provider','provider_payment_id'], 'payment_sessions_provider_payment_unq')`. |
| 8 | **`merchant_ref` + `badan_usaha_ref` NOT NULL on every session** (AC13) | `...100100_create_payment_sessions_table.php:73-74`, rationale at `:34-40` | "a NOT NULL column means the first creation path anyone writes cannot omit them even by accident". |
| 9 | **A verification decides exactly once, under a row lock** | `app/Platform/Payment/VerifyManualPayment.php:88-101` + `app/Platform/Payment/Models/PaymentVerification.php:132-145` | `lockForUpdate()->firstOrFail()` inside the transaction, then `decide()` on the *locked* instance. Second caller gets `PaymentVerificationAlreadyDecidedException`. |
| 10 | **`payment_verifications` has three doors only** | `PaymentVerification.php:20-34` + `$fillable` at `:51-56` + `saving` hook at `:69-84` | `status` / `decided_*` are not fillable; `::create()`/`fill()` land on an "unknown status" `LogicException`. |
| 11 | **One reversal per `(reversal_type, reference)`** | `database/migrations/2026_08_11_100010_create_payment_reversals_table.php:106` | `$table->unique(['reversal_type','reference'], 'payment_reversals_type_reference_unique')` — an ordinary index, so it holds on SQLite too. |
| 12 | **Provider transaction claimed by at most one settling event** | `app/Platform/Payment/ProcessWebhookEvent.php:96-105`, claiming statuses at `:134-138` | Apply-time claim, deliberately not an index — reasoning at `:80-95`. |
| 13 | **`provider_events` append-only, unique by provider event id** | `.kiro/specs/platform-payment-adapter/design.md:30` | "`provider_events` is append-only and is the replay source of truth." |
| 14 | **Paid state never from a browser return** (AC4) | `PaymentReturnController.php:12-38`, `PaymentCancelController.php:18-28` | Structurally enforced + grep-asserted by `Tests\Feature\Payment\PaymentReturnRouteTest`. |

**No idempotency-key concept exists on the payment path.** The only `idempotencyKey` in the platform is
`Outbox::record()`'s optional parameter (`app/Platform/Outbox/Outbox.php:95`). Verified by grep — no
`idempotency` identifier appears anywhere under `app/Platform/Payment/`.

**No "one open session per X" rule exists** — because no session can exist. NOT FOUND.

## A.5 GAP ANALYSIS — would a *renewal* payment require modifying shared code?

### Short answer

**Yes — but not for the reason the question anticipates.** There is no hardcoded enum of payable types,
no foreign key to a specific subject table, and no `switch` over subject types anywhere in
`app/Platform/Payment/`. The blocker is far more fundamental: **there is no session-creation code to
extend at all, and the guard's five unconditional denials are upstream of payments entirely.**

### The specific findings

**A.5.1 — There is no closed list of payable/subject types. NOT FOUND.**

Grep across `app/Platform/Payment/**` finds no `PayableType`, no `SubjectType`, no
`morphTo`/`morphMany`, and no `match` over a subject. The full enum inventory in the module is:
`SessionState`, `PaymentIntentDecision`, `PaymentVerificationStatus`, `PaymentVerificationDecision`,
`PaymentReversalType`, `ProviderEventStatus`, `ProviderEventType`, `GuardCondition`,
`GuardDenialReason`, `OutboxClassification` (external). None of them enumerates *what is being paid
for*.

So on the narrow question asked: **there is no closed list to add `RENEWAL` to.** In that narrow sense
the design is subject-agnostic and would extend cleanly.

**A.5.2 — `GuardPaymentSession` accepts only a `Money`, so a renewal caller has nowhere to name
itself.** `GuardPaymentSession.php:108` — `public function __invoke(Money $requestedAmount): GuardResult`.
Adding a renewal subject means **changing this signature** — a shared-file change to
`app/Platform/Payment/GuardPaymentSession.php`, and correspondingly to `payment_intents`
(`$fillable` at `PaymentIntent.php:59-73`) and its migration
(`database/migrations/2026_08_09_100000_create_payment_intents_table.php`), which has no subject column.

**A.5.3 — The five hardcoded denials are the real wall, and they are not about renewals.**

`app/Platform/Payment/GuardPaymentSession.php:157-189`. Each is an unconditional
`$this->unavailable(...)` call with a hardcoded missing-upstream name:

| Line | Condition | Hardcoded missing upstream |
| --- | --- | --- |
| `:157-161` | `ConfirmationOrReservation` | `'Confirmation|PlotReservation'` |
| `:163-167` | `QuoteAcceptedAndUnexpired` | `'Quote'` |
| `:169-173` | `AuthorizedOpening` | `'AuthorizePaymentOpening'` |
| `:179-183` | `AmountMatchesQuoteTotal` | `'Quote'` |
| `:185-189` | `MerchantAndBadanUsahaBound` | `'Merchant|BadanUsaha'` |

These deny for **every** caller, of every subject type. A renewal caller is denied for exactly the same
reason a booking caller is. **Making a renewal payment possible requires implementing those upstream
records and then rewriting these five `match` arms** — i.e. modifying the shared file
`app/Platform/Payment/GuardPaymentSession.php` at lines 157-189.

**A.5.4 — Those upstream domain directories are empty.** Verified by directory listing:

```
app/Domain/Quotation/Actions/.gitkeep        app/Domain/Quotation/Models/.gitkeep
app/Domain/OrderWorkflow/Actions/.gitkeep    app/Domain/OrderWorkflow/Models/.gitkeep
app/Domain/PlotReservation/Actions/.gitkeep  app/Domain/PlotReservation/Models/.gitkeep
```

All six are `.gitkeep`-only. There is no `Quote` model, no `Confirmation`, no `PlotReservation`, no
merchant / `badan_usaha` model anywhere in this repository.

`GuardPaymentSession.php:42-45` names the owner and the schedule:
> Those five records are owned by `.kiro/specs/booking-and-order-orchestration/`, whose tasks are
> unchecked and whose build `docs/planning/sprint-plan.md` schedules for Sprint 7.

**A.5.5 — The ruling's own instruction to a downstream lane that hits this wall.**
`GuardPaymentSession.php:58-62`:
> Tasks 3-8 sit downstream of a CREATED session and hit this same wall; the ruling's instruction when
> they do is to **escalate, not to widen scope or stub the upstream**.

And `.kiro/specs/platform-payment-adapter/tasks.md:49`:
> No payment session or test-only pass fixture was fabricated for these unavailable upstream
> contracts.

Fabricating a session by factory, `unguarded()`, query builder, raw SQL, or a test-only bypass is
described as prohibited at `ProcessWebhookEvent.php:28-30`.

**A.5.6 — The one seam that DOES extend cleanly today.**

The manual-payment path is genuinely subject-agnostic and needs **zero** shared-code change to carry a
renewal:

- `SubmitManualPayment::submit()` takes `string $reference` as free text
  (`SubmitManualPayment.php:55`); the column is plain `string(191)`, indexed, not unique, no FK
  (`database/migrations/2026_08_11_100000_create_payment_verifications_table.php:59,91`).
- The table deliberately has no FK to `payment_sessions` or any order/booking table
  (`...100000_create_payment_verifications_table.php:20-27`).
- `DocumentKind::PaymentProof` already exists for evidence upload
  (`SubmitManualPayment.php:88-95`).

**A renewal could therefore submit and have verified a manual payment today**, using a
renewal-reference string, without touching a single shared file. What it would NOT get: any
`payment_sessions` row, any `SessionState`, any journal entry, any outbox event, any order transition,
and any role-based authorization on the verify step (§A.3.2 finding).

The migration's own doc block flags that `reference` is not a settled production shape and will
"very likely" gain a real foreign key later
(`...100000_create_payment_verifications_table.php:30-34`) — so an L8 lane building on it should
expect a future migration to constrain the column it is writing.

**A.5.7 — Secondary code observation, unrelated to renewals but in the path L8 would use.**

`PaymentVerification::decide()` declares `string $decidedByActorRef` (non-nullable) —
`app/Platform/Payment/Models/PaymentVerification.php:132`. Its only caller passes a nullable
expression: `decidedByActorRef: $actorRef !== null ? (string) $actorRef : null`
(`app/Platform/Payment/VerifyManualPayment.php:96`). Both files declare `strict_types=1`
(`PaymentVerification.php:3`, `VerifyManualPayment.php:3`), so a null `$actorRef` reaching this call
raises a `TypeError`. Reported as an observation from reading, **NOT TESTED** — no test was run.

---

# B. Audit (`app/Platform/Audit/`)

## B.1 `Audit::record()` and `Audit::wrap()` — signatures and when to use which

**`Audit::record()`** — `app/Platform/Audit/Audit.php:93-103`:
```
public static function record(
    string $action,
    AuditSubject $subject,
    AuditOutcome $outcome,
    int|string|null $actorRef,
    string $actorRole,
    AuditSource $source,
    ?string $reason = null,
    ?string $correlationId = null,
    array $metadata = [],
): AuditEvent
```

**`Audit::wrap()`** — `app/Platform/Audit/Audit.php:233-244`:
```
public static function wrap(
    Closure $mutation,
    string $action,
    AuditSubject|Closure $subject,
    AuditOutcome $outcome,
    int|string|null $actorRef,
    string $actorRole,
    AuditSource $source,
    ?string $reason = null,
    ?string $correlationId = null,
    array $metadata = [],
): mixed
```

**Which to use** (`Audit.php:22-28`):
- `record()` — "when the mutation and the audit write are already inside the same transaction some
  other way".
- `wrap()` — runs the mutation and `record()` inside one `DB::transaction()` "so the pair can never be
  committed separately (AC4)".

`record()` **does not open its own transaction** (`Audit.php:58-64`).

`wrap()`'s `$subject` may be an `AuditSubject` directly, or a `Closure(TResult): AuditSubject`
receiving the mutation's return value — for when the subject id only exists after the mutation
(`Audit.php:210-217`; resolved at `Audit.php:259`). Both payment write paths use the closure form:
`SubmitManualPayment.php:103`, `VerifyManualPayment.php:103`, `GuardPaymentSession.php:241`.

**Never call `AuditEvent::create()`/`::insert()`/`save()` directly** (`Audit.php:30-34`) — AC2's
required-field list, AC3's sensitive-reason check and AC5's metadata allowlist are enforced in `Audit`,
not on the model.

`Audit` is a plain static-method class, deliberately **not** a Facade or container binding
(`Audit.php:36-54`).

**`$metadata` keys are allowlisted.** `MetadataAllowlist::assertAllowed($metadata)` at `Audit.php:108`;
allowed keys at `app/Platform/Audit/MetadataAllowlist.php:33-73`: `reference_number`,
`previous_state`, `new_state`, `note`, `method`, `recovery_codes_remaining`, `purpose`. Anything else
throws `AuditMetadataKeyNotAllowedException` (`MetadataAllowlist.php:80-84`).
`GuardPaymentSession.php:251-254` notes it added none: "`note` is an EXISTING
`MetadataAllowlist::ALLOWED_KEYS` key — this lane adds none."

## B.2 `SensitiveActions` — declaration, extension, and what `requiresReason` implies

**Declaration:** `app/Platform/Audit/SensitiveActions.php:25-105` — a `final class` with one
`public const array ACTIONS` (`:30`) of plain strings, and one static helper:
```
public static function requiresReason(string $action): bool   # :107
{
    return in_array($action, self::ACTIONS, true);             # :109
}
```

**Why a closed list, not a convention** (`SensitiveActions.php:16-23`):
> Deliberately a closed, explicitly-reviewed list rather than a magic-string convention (e.g. "any
> action ending in _REJECTED" …) … extend it deliberately, never infer sensitivity from an action's
> name at runtime.

**How an action is added:** append the literal string to `ACTIONS` **with a comment block naming the
lane, the ruling that authorized it, the writing class, and the risk-category argument.** This is the
house convention, visible in every addition — e.g. `SensitiveActions.php:76-84` for
`PAYMENT_REFUND`/`PAYMENT_CHARGEBACK`, `:86-93` for `ROLE_GRANT`/`ROLE_REVOKE`, `:96-104` for
`SCOPE_GRANT`/`SCOPE_REVOKE`. Note additions are one-line-per-string, no migration involved.

**What `requiresReason` implies for callers:** at `Audit.php:104-106`,
```
if (SensitiveActions::requiresReason($action) && self::reasonIsBlank($reason)) {
    throw AuditReasonRequiredException::forAction($action);
}
```
Inside `Audit::wrap()`, that throw **rolls back the mutation too** (`Audit.php:218-224`) — so a caller
that forgets a reason loses its own write. `VerifyManualPayment.php:28-39` documents exactly this and
explicitly does *not* re-implement the check.

"Blank" is Unicode-aware, not `trim()` — `Audit::reasonIsBlank()` at `Audit.php:195-203`:
```
return preg_match('/^[\p{Z}\p{C}\s]*$/u', $reason) !== 0;
```
`!== 0`, not `=== 1`, so invalid UTF-8 (`preg_match` returns `false`) counts as blank — fails closed
(`Audit.php:145-151`). `Audit.php:161-167` names this the authoritative check; everything upstream is
advisory. Known residual documented at `Audit.php:153-159`: Hangul fillers (U+3164, U+1160, U+FFA0)
are category `Lo` and still pass.

`AuditReasonRequiredException` is a plain `RuntimeException` with no `render()` — so it surfaces as a
**500**, not a 422, if it reaches the framework (`app/Platform/Audit/Rules/NonBlankReason.php:20-25`).
That is the entire reason `NonBlankReason` exists — see §B.4.

## B.3 Naming convention of existing action constants — five real examples, verbatim

From `app/Platform/Payment/PaymentAuditActions.php`:
```
public const string GUARD_DENIED = 'PAYMENT_GUARD_DENIED';                    # :37
public const string WEBHOOK_REJECTED = 'PAYMENT_WEBHOOK_REJECTED';            # :56
public const string MANUAL_SUBMITTED = 'PAYMENT_MANUAL_SUBMITTED';            # :102
public const string MANUAL_VERIFICATION = 'PAYMENT_MANUAL_VERIFICATION';      # :115
public const string REFUND = 'PAYMENT_REFUND';                                # :128
```

**The convention, stated concretely:**
- Values are `SCREAMING_SNAKE_CASE`, prefixed by the module/domain
  (`PAYMENT_*`, `MFA_*`, `ROLE_*`, `SCOPE_*`, `DOCUMENT_*`, `GATE_*`).
- Constants live in one per-module `*AuditActions` **class of `public const string`**, so no call site
  spells a magic string — `PaymentAuditActions.php:7-12` names the precedent classes:
  `App\Domain\Faq\FaqAuditActions`, `App\Domain\ServiceCatalog\ServiceCatalogAuditActions`,
  `App\Platform\IdentityAccess\Mfa\MfaAuditActions`. Also present:
  `app/Platform/IdentityAccess/Roles/RoleAuditActions.php`,
  `app/Platform/IdentityAccess/Scopes/ScopeAuditActions.php`,
  `app/Platform/IdentityAccess/Reauthentication/ReauthenticationAuditActions.php`.
- The **constant name** is short and module-local (`REFUND`); the **value** carries the module prefix
  (`'PAYMENT_REFUND'`) and must match the literal string in `SensitiveActions::ACTIONS` character for
  character (`PaymentAuditActions.php:122-126`).
- Each constant carries a doc block naming: who writes it, with which `AuditOutcome`, what the subject
  is, and whether it is on `SensitiveActions` and **why or why not**. That last part is not optional
  in this codebase — see `PaymentAuditActions.php:14-27`, `:50-54`, `:96-101`.
- Older non-prefixed entries exist for historical reasons (`'DITOLAK'`, `'PLOT_OVERRIDE'` —
  `SensitiveActions.php:31-32`); new work follows the prefixed form.

`AuditSubject` shape: `new AuditSubject('payment_verification', $verification->id)`
(`SubmitManualPayment.php:103`) — a snake_case type string plus an id, and optionally a version
(`Audit.php:118`). AC5: "a reference to the record this event is about, never its content"
(`Audit.php:69-70`).

## B.4 How an HTTP-boundary caller validates a reason

**The rule:** `App\Platform\Audit\Rules\NonBlankReason` —
`app/Platform/Audit/Rules/NonBlankReason.php:38-49`, a `ValidationRule` that delegates to
`Audit::reasonIsBlank()` rather than copying the regex (`:46`, rationale at `:32-36`).

**Every real wiring in the repo** (grep result — there are exactly two):

1. `app/Platform/Payment/Http/Controllers/VerifyManualPaymentController.php:66-72`:
```
$validated = $request->validate([
    'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
    'reason' => ['required', 'string', new NonBlankReason],
]);
```
2. `app/Platform/Payment/Http/Controllers/RecordPaymentReversalController.php:81-94`:
```
$validated = $request->validate([
    'reference' => ['required', 'string', 'max:191'],
    'amount_minor' => ['nullable', 'integer'],
    'reason' => ['required', 'string', new NonBlankReason],
]);
```

Both pair it with `'required', 'string'`. The doc block at
`VerifyManualPaymentController.php:68-70` explains why `required` alone is insufficient:
> `required` plus the `TrimStrings` middleware still let a control or private-use character through;
> it would then reach `Audit::record()` and surface as a 500 rather than a 422.

The CLI analogue is `App\Console\Commands\Concerns\RequiresAuditReason`
(`app/Console/Commands/Concerns/RequiresAuditReason.php:40`), which keeps its own copy of the pattern
and must be changed alongside `Audit::reasonIsBlank()` (`Audit.php:168-174`).

**Other reason-validation sites are advisory `trim()` pre-checks only** and do NOT catch Unicode-blank
input — enumerated verbatim at `Audit.php:176-190`:
`FinancialLedger\Actions\ManualPayout`, `FinancialLedger\Actions\ResolveException`,
`Domain\ServiceCatalog\Actions\RecordServiceDefinitionPriceVersion`,
`FeatureGate\GateActivationRecorder`.

Note `RecordPaymentReversalController.php:82-87` also documents a related convention: bound
free-text length at the boundary (`max:191`) to match the column width, so an over-length value gets a
422 instead of a raw PostgreSQL "value too long for varchar(191)".

---

# C. Identity / authorization

## C.1 `ActorRole` closed list and runtime reads

**Closed list:** `app/Platform/IdentityAccess/Roles/ActorRole.php:64-99` — a `final class` of
`public const string` plus `KNOWN_ROLES`:

| Constant | Value | Line |
| --- | --- | --- |
| `ADMIN` | `'admin'` | `:66` |
| `RESTRICTED_ADMIN` | `'restricted_admin'` | `:68` |
| `FINANCE` | `'finance'` | `:70` |
| `OPERATOR` | `'operator'` | `:72` |
| `CASE_MANAGER` | `'case_manager'` | `:74` |
| `VENDOR` | `'vendor'` | `:76` |
| `CUSTOMER` | `'customer'` | `:78` |
| `SYSTEM` | `'system'` | `:80` |

`KNOWN_ROLES` at `:90-99`; helpers `isKnown()` (`:101`), `assertKnown()` (`:112`).

**Declaration order IS precedence order, most privileged first** (`ActorRole.php:82-85`).

**`guest` and `authenticated_actor` must NEVER be added** — they are audit sentinels meaning "no role
applies" (`ActorRole.php:44-55`). Note both payment admin controllers pass exactly
`'authenticated_actor'` (§A.3.2 finding).

`issuer` and `auditor` are deliberately excluded (`ActorRole.php:31-35`).

**Runtime read API — three layers:**

1. **Per-request context, resolved once.**
   `App\Platform\IdentityAccess\ActorContextResolver::resolve(): ActorContext`
   (`app/Platform/IdentityAccess/ActorContextResolver.php:74-80`), cached in `$resolved` and bound
   with Laravel `scoped()` — not `singleton()` — so it does not leak across Horizon jobs
   (`ActorContextResolver.php:26-53`). Escape hatch `forget()` (`:55-62`).
2. **The context object.** `App\Platform\IdentityAccess\ActorContext`
   (`app/Platform/IdentityAccess/ActorContext.php:75`), readonly promoted properties at `:123-128`:
   `identityReference`, `roles`, `scopes`, `mfaState`, `lastAuthenticatedAt`.
   Methods: `isAuthenticated()` (`:141`), `hasRole(string $role): bool` (`:150`),
   `hasScope(string $scope): bool` (`:159`).
   **`$roles` is populated for real as of lane L5** (`ActorContext.php:36`).
   **An empty `$roles` must NEVER be read as "no roles required"** (`ActorContext.php:45-47`).
3. **Direct table readers**, both stateless and dependency-free:
   - `App\Platform\IdentityAccess\Roles\ActorRoleReader::rolesForActor(int|string $actorIdentifier): array`
     (`app/Platform/IdentityAccess/Roles/ActorRoleReader.php:42-58`) — active (non-revoked)
     assignments, de-duplicated, sorted against `ActorRole::KNOWN_ROLES` order, **not** DB order
     (`:51-55`).
   - `App\Platform\IdentityAccess\Scopes\ScopeAssignmentReader`
     (`app/Platform/IdentityAccess/Scopes/ScopeAssignmentReader.php:31`) —
     `grantedEntityIds(int|string $actorIdentifier, string $entityType): array` (`:44`),
     `actorsForEntity(string $entityType, int|string $entityId): array` (`:74`),
     `scopeStringsForActor(int|string $actorIdentifier): array` (`:94`).

   ⚠ `ActorRoleReader` and anything in its graph **must not depend on `ActorContext`** — that closes a
   container cycle which recurses to ~1GB RSS and OOMs the host (`ActorRoleReader.php:14-22`).

**Scope vocabulary:**
- `ScopeEntityType` (`app/Platform/IdentityAccess/Scopes/ScopeEntityType.php:29-39`):
  `CEMETERY='cemetery'`, `VENDOR='vendor'`, `ORDER='order'`, `CASE_RECORD='case'`, `GRAVE='grave'`,
  `BUSINESS_ENTITY='business_entity'`. **`GRAVE` already exists** — directly relevant to a
  renewal-scoped authorization decision.
- `ScopeGrantLevel` (`app/Platform/IdentityAccess/Scopes/ScopeGrantLevel.php:43-63`):
  `OWN='own'`, `ASSIGNED='assigned'`, `READ='read'`, `PRIVILEGED='privileged'`.

## C.2 Gates/Policies vs middleware vs bespoke reader

**Authorization in this codebase is a bespoke reader/authorizer pattern. It is NOT Laravel
Gates/Policies.**

Verified: `grep -rn "Gate::define\|Gate::allows\|Gate::authorize\|Gate::policy" app/` returns **zero**
functional hits — every match is doc-block prose or a `substitution_policies` table name. There is no
`AuthServiceProvider` policy map.

The three mechanisms actually used:

1. **Route middleware**, for session freshness and panel access —
   `App\Http\Middleware\RequireRecentAuthentication` (three attachments:
   `/admin/mfa/disable`, `routes/web.php:343`, `routes/web.php:373`),
   `App\Http\Middleware\EnforceMfaChallenge`,
   `App\Platform\IdentityAccess\Contracts\PanelAccessPolicy` /
   `Panel\AdminPanelAccessPolicy`.
2. **Explicit `*Authorizer` classes** behind `Contracts/*Authorizer` interfaces, injected into the
   Action that performs the write. Five exist, all in FinancialLedger:
   `FinanceLedgerReadAuthorizer`, `FinanceOrRestrictedAdminPayoutAuthorizer`,
   `FinanceReconciliationAuthorizer`, `FinanceVendorPayableAuthorizer`, plus
   `Contracts/PayoutProofVerifier`.
3. **Query-level scoping** via `ScopeAssignmentGlobalScope` (an Eloquent global scope), described in
   `docs/security/rbac-matrix.md:29-31` as mandatory and enforced separately from roles.

### End-to-end example of a privileged admin action being authorized

**`FinanceOrRestrictedAdminPayoutAuthorizer`** —
`app/Platform/FinancialLedger/FinanceOrRestrictedAdminPayoutAuthorizer.php:23-64`:

```
public function authorize(ActorContext $actor, string $vendorId): string    # :37
{
    $actorReference = $actor->identityReference;                            # :39
    if ($actorReference === null) {                                         # :41
        throw PayoutNotAuthorisedException::forActorContext($vendorId);     # :42
    }
    $role = $this->roleFromContext($actor);                                 # :45
    if ($role === null) {                                                   # :47
        throw PayoutNotAuthorisedException::forActorContext($vendorId);     # :48
    }
    $hasVendorGrant = ScopeAssignment::query()                              # :51
        ->where('actor_identifier', (string) $actorReference)               # :52
        ->where('entity_type', ScopeEntityType::VENDOR)                     # :53
        ->where('entity_id', $vendorId)                                     # :54
        ->where('grant_level', ScopeGrantLevel::PRIVILEGED)                 # :55
        ->whereNull('revoked_at')                                           # :56
        ->exists();                                                         # :57
    if (! $hasVendorGrant) {                                                # :59
        throw PayoutNotAuthorisedException::forActorContext($vendorId);     # :60
    }
    return $role;                                                           # :63
}
```

**The canonical shape, and the one an L8 authorizer should copy:**
1. Refuse a null `identityReference` first.
2. Find the single most-privileged matching role from `$actor->roles` against a class-local
   `AUTHORISED_ROLES` list (`:32-35`, `roleFromContext()` at `:66-75`) — an empty roles list is never
   read as permission (`:17-19`).
3. Require an **active, entity-scoped, level-specific `ScopeAssignment`** on top of the role.
4. Throw a domain exception, never return `false`.
5. **Return the matched role string**, so the calling Action can record it in the audit event — see
   the call site `app/Platform/FinancialLedger/Actions/ManualPayout.php:236`:
   `$actorRole = $this->authorizer->authorize($this->actorContext, (string) $payable->vendor_id);`

This matches `docs/security/rbac-matrix.md:29-31` verbatim:
> A role never by itself grants access to a record: the shipped authorizers require a role **and** a
> scope grant.

Other call sites of the same pattern: `FinancialLedger/Actions/ResolveException.php:170`,
`FinancialLedger/Actions/VendorPayable.php:180`,
`FinancialLedger/Actions/BulkFinancialExport.php:166`,
`app/Filament/Admin/Pages/FinanceReports.php:83,120`.

## C.3 ⚠ RBAC matrix — the external-renewal-marking question

`docs/security/rbac-matrix.md` is **31 lines total**. It was read in full.

### Finding: THE MATRIX HAS NO ROW FOR MARKING AN EXTERNAL RENEWAL OR EXTERNAL PAYMENT WITH EVIDENCE.

The complete list of `Capability` rows is (`docs/security/rbac-matrix.md:5-17`):
Public directory; Create At-Need intake; Manage FuneralCase/tasks; Confirm availability;
Hold/reserve plot; Override plot status; Quote/open payment; Restricted documents;
Issue/revoke certificate; Memorial edit/publish; Vendor work/evidence; Payout/refund;
Feature/capability gate.

The words "renewal", "perpanjang", "external", and "offline" appear **nowhere** in the file
(verified by grep). There is likewise **no row for manual payment verification** and **no row for
payment reversal** as distinct capabilities.

This matters because `.kiro/specs/renewal-and-grave-registry/requirements.md:18` states:
> 10. THE SYSTEM SHALL allow an admin/operator to mark an external renewal/payment with evidence.

— naming **admin/operator** as the actors, while the matrix contains no row that grants either of them
that capability, and while `ActorRole` has both `admin` and `operator` as real roles.

**Do not guess which role should be allowed. This requires escalation.**

### Nearest analogous rows, quoted verbatim

Header (`docs/security/rbac-matrix.md:3-4`):
```
| Capability | Customer/Family | Case Manager | Operator | Vendor | Admin | Finance/Issuer/Auditor |
|---|---:|---:|---:|---:|---:|---:|
```

The four closest rows, verbatim:
```
| Confirm availability | No | Record evidence | Assigned cemetery | No | Yes/fallback | No |
```
(`:8` — the only row in the matrix whose cell text is literally "Record evidence"; it grants that to
**Case Manager**, and gives Operator only "Assigned cemetery", Admin "Yes/fallback".)

```
| Quote/open payment | Accept only | Prepare/request | No | No | Authorized | Read/review |
```
(`:11` — the closest row to "renewal payment". Note **Operator is explicitly `No`** here, and Admin is
"Authorized". This row is in direct tension with AC10's "admin/**operator**".)

```
| Payout/refund | No | No | No | View own | Restricted | Dedicated finance |
```
(`:16` — the nearest row to payment reversal. Admin is "Restricted"; Operator is `No`.)

```
| Vendor work/evidence | View own outcome | Coordinate | View relevant | Own | Yes | Read |
```
(`:15` — the other row using the word "evidence", but scoped to vendor work, not payment.)

For completeness, the row nearest to a grave-scoped renewal record:
```
| Hold/reserve plot | Request | Assigned action | Assigned authority | No | Privileged | Read/audit |
```
(`:9`)

### The matrix's own instruction on how to read and extend it

`docs/security/rbac-matrix.md:19-22`, verbatim:
> The canonical role vocabulary is `App\Platform\IdentityAccess\Roles\ActorRole::KNOWN_ROLES`, and the
> roles are resolved per request into `ActorContext::$roles`. The columns above are capability
> groupings for review, not the role list itself — read the closed list from that class rather than
> inferring it from this table, and extend it there.

`:24-27`:
> This replaces the earlier note that exact roles depend on an external K1/K2 identity contract. That
> contract was never specified anywhere in this repository, so the roles are now mastered locally; the
> `IdentityAccessAdapter` seam remains, so a future K1/K2-backed adapter is still a binding swap
> rather than a rewrite.

`:29-31`:
> Query-level scope is mandatory, and is enforced separately from roles via `scope_assignments`
> (`ScopeAssignmentGlobalScope`). A role never by itself grants access to a record: the shipped
> authorizers require a role **and** a scope grant.

### Open questions this raises for escalation (not answered here)

1. AC10 says "admin/**operator**"; row `Quote/open payment` (`:11`) says Operator = `No`. Which
   governs? Per `AGENTS.md` source precedence, the spec sits above the matrix, but the matrix is the
   security document — this is a real conflict, not a lookup.
2. Should "mark external renewal/payment with evidence" become a **new matrix row**, or is it read as
   an instance of the existing `Quote/open payment` row?
3. What `ScopeEntityType` scopes the grant — `GRAVE` (which exists,
   `ScopeEntityType.php:37`) or `CEMETERY` (`:29`)? And at what `ScopeGrantLevel`?
4. Does it require `RequireRecentAuthentication` (like manual verification and reversals) — and does
   it require a **role check**, which those two currently lack (§A.3.2 finding)?

---

# D. Conventions the new module must follow

## D.1 Where domain models, Actions, closed lists, and Query classes live

**Layout:** one directory per module boundary under `app/Domain/<Module>/`, per
`docs/architecture/overview.md` §5. `app/Domain/README.md:1-7`:
> One directory per module boundary … Created empty during the Sprint 1 scaffold, deliberately. Module
> boundaries are never successfully retrofitted after features exist, so the structure lands before
> the code does.

**Rules** (`app/Domain/README.md:9-17`, citing `AGENTS.md` §Architecture):
> - Domain logic lives here, in Actions/Services — **not** in controllers, Livewire components, or
>   Filament Resources. Those are presentation only.
> - A module owns its own tables.
> - Cross-cutting concerns are **consumed**, never reimplemented. They live in `app/Platform/`.

**The four kinds, with a real example each:**

| Kind | Location convention | Real example |
| --- | --- | --- |
| Eloquent model | `app/Domain/<Module>/Models/<Noun>.php` | `app/Domain/GraveRegistry/Models/GraveRecord.php` |
| Action | `app/Domain/<Module>/Actions/<ImperativeVerbPhrase>.php` | `app/Domain/ServiceCatalog/Actions/PublishServicePackageVersion.php` (siblings: `DefineServicePackage.php`, `ReviseServicePackageVersion.php`, `RecordServiceDefinitionPriceVersion.php`) |
| Closed list (enum) | `app/Domain/<Module>/<Noun>.php`, module root — **not** in an `Enums/` subdirectory | `app/Domain/GraveRegistry/GraveRecordAccessMode.php`; `app/Domain/Renewal/RenewalJourneyStep.php`; `app/Domain/CemeteryDirectory/LaunchCityCode.php` |
| Query class | `app/Domain/<Module>/<Module>PublicQuery.php` or `<Noun>PublicQuery.php`, module root, static methods | `app/Domain/GraveRegistry/GraveRegistryPublicQuery.php:58` (`final class`, `public static function search(GraveSearchCriteria $criteria): GraveSearchOutcome` at `:89`); `app/Domain/CemeteryDirectory/CemeteryPublicQuery.php:57` (`launchCities()` `:93`, `published()` `:131`, `inCity()` `:173`, `findPublishedById()` `:234`) |

Supporting value objects also sit at module root: `GraveSearchCriteria.php`, `GraveSearchOutcome.php`,
`GraveRecordProjection.php`, `GraveNameNormalizer.php`.

**Closed lists are one of two shapes, both deliberate:**
- **Backed `enum`** where the value is a persisted column — e.g. `App\Platform\Payment\SessionState`
  (`app/Platform/Payment/SessionState.php:42`).
- **`final class` + `const`** where the vocabulary is fixed and not database-backed — e.g.
  `App\Domain\Renewal\RenewalJourneyStep` (`app/Domain/Renewal/RenewalJourneyStep.php:50`), whose doc
  block at `:27-32` names the convention:
  > This is NOT a database-backed closed list, so it has no `access_mode`-style column behind it and
  > no `booted()` hook validates it; it is the `final class` + `const` shape this codebase uses for a
  > fixed vocabulary (`GraveRecordAccessMode` documents that convention and its source).

Both shapes expose the same helper trio — `values()`/`KNOWN_*`, `isKnown()`, `assertKnown()` —
compare `SessionState.php:84-105`, `ActorRole.php:101-119`, `RenewalJourneyStep.php:99-114`.

**Closed lists are NEVER PostgreSQL enum types.** `ActorRole.php:57-62`:
> closed lists in this codebase are application-layer PHP constants, never Postgres enum types, so
> extending one never requires a migration.

**Current state of `app/Domain/Renewal/`** (verified by listing):
```
app/Domain/Renewal/Actions/.gitkeep
app/Domain/Renewal/Models/.gitkeep
app/Domain/Renewal/RenewalJourneyStep.php
```
Both subdirectories are `.gitkeep`-only. **There is no Renewal model, Action, table, or migration
anywhere in this repository** — confirmed independently by
`docs/superpowers/plans/2026-08-09-retrofit-renewal.md` ("Current shipped state"):
> `app/Domain/Renewal/Actions/.gitkeep`, `app/Domain/Renewal/Models/.gitkeep` — **both directories are
> empty.** No Renewal Action, no Renewal model, no Renewal-owned table, no Renewal migration exists
> anywhere in this repository.

The tables the spec names but that do not exist —
`.kiro/specs/renewal-and-grave-registry/design.md:25-28`:
```
renewals
renewal_quotes
renewal_external_markings
reminder_deliveries
```

**AC11's duplicate-period business key is already specified** —
`.kiro/specs/renewal-and-grave-registry/design.md:38-46`:
> ## Duplicate prevention
> Unique business key:
> ```
> grave_record_id + target_due_period
> ```
> External marking and online renewal share the same uniqueness domain.

That last sentence is load-bearing: the guard must span **both** `renewals` and
`renewal_external_markings`, which a plain two-column `UNIQUE` on one table cannot do. See D.2 for the
PostgreSQL tools this repo already uses for cross-table invariants.

## D.2 Migration conventions

**File naming:** `database/migrations/YYYY_MM_DD_HHMMSS_<snake_case_description>.php`, with the time
component used as a deliberate ordering channel rather than a real timestamp — e.g.
`2026_08_09_100000_create_payment_intents_table.php`,
`2026_08_09_100100_create_payment_sessions_table.php`,
`2026_08_11_100000_create_payment_verifications_table.php`,
`2026_08_11_100010_create_payment_reversals_table.php`. Note the `100000 / 100100 / 100010` spacing
for intra-lane ordering. Files return an **anonymous class**: `return new class extends Migration`
(e.g. `...100100_create_payment_sessions_table.php:42`), and declare `strict_types=1` (`:3`).

**`down()` is always written.** Verified across the payment and grave migrations:
- `...100100_create_payment_sessions_table.php:106-109` — `Schema::dropIfExists('payment_sessions');`
- `...100010_create_payment_reversals_table.php:124-127`
- `...100000_create_payment_verifications_table.php:108-111`
- `database/migrations/2026_08_08_100000_create_grave_records_table.php:237-253` — a non-trivial
  `down()` that drops its own index but **deliberately does not drop the shared `pg_trgm` extension**
  (`:247`: "a shared extension to reverse one table's creation is exactly the…").

**Unique constraints** — ordinary Laravel builder calls with an **explicit index name**:
```
$table->unique(['provider', 'provider_payment_id'], 'payment_sessions_provider_payment_unq');   # ...100100:86
$table->unique(['reversal_type', 'reference'], 'payment_reversals_type_reference_unique');      # ...100010:106
$table->index('reference', 'payment_reversals_reference_idx');                                  # ...100010:107
$table->index('status', 'payment_verifications_status_idx');                                    # ...100000:90
```
`...100010_create_payment_reversals_table.php:64` records the reasoning for preferring an ordinary
index: "An ordinary Laravel unique index (not a Postgres-only…)" — i.e. it holds on SQLite too, so the
local suite can actually prove it.

**Check constraints** — always raw `DB::statement`, always guarded to `pgsql`, always built from the
PHP closed list so the two cannot drift:
```
if (DB::connection()->getDriverName() === 'pgsql') {                                   # ...100100:96
    $states = implode("', '", SessionState::values());                                 # ...100100:97
    DB::statement(
        'ALTER TABLE payment_sessions ADD CONSTRAINT payment_sessions_state_check '.   # ...100100:100
        "CHECK (state IN ('{$states}'))"                                               # ...100100:101
    );
}
```
The guard's rationale, repeated at every site (`...100100:92-95`):
> SQLite cannot ADD CONSTRAINT, phpunit.xml defaults to sqlite, CI and every real environment run
> Postgres.

Same shape at `...100000_create_payment_verifications_table.php:98-105` and
`...100010_create_payment_reversals_table.php:117-119`.
Value checks use the same mechanism:
`journal_entries` — `'CHECK (amount_minor >= 0)'`
(`database/migrations/2026_08_09_110100_create_journal_entries_table.php:40-41`);
`vendor_payables` — same (`database/migrations/2026_08_09_120000_create_vendor_payables_table.php:101-102`).

**Every closed-list column is ALSO validated in the model's `saving()` hook**, so the constraint is
real on SQLite: `PaymentIntent.php:91-103`, `PaymentVerification.php:69-84`. This is the house pattern
and the L8 lane should follow it for any new closed-list column.

### PostgreSQL-specific artifacts — three real examples, all present

1. **Extension + GIN trigram index** —
   `database/migrations/2026_08_08_100000_create_grave_records_table.php`:
   ```
   DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');                              # :192
   DB::statement(
       'CREATE INDEX grave_records_name_trgm_idx ON grave_records '.
       'USING gin (deceased_name_normalized gin_trgm_ops)'                               # :232-233
   );
   ```
   With a full doc block on the privilege risk (`:116-131`: what happens "where the migration role
   lacks `CREATE EXTENSION` privilege") and a `down()` that drops the index but not the extension
   (`:247-253`).

2. **PARTIAL UNIQUE INDEX** — `provider_events` carries a partial unique on
   `(provider, provider_transaction_id, invoice_reference)` scoped to settling event types only. Cited
   in `app/Platform/Payment/ProcessWebhookEvent.php:72-78`:
   > Task 3 implemented the insert-time half — a PARTIAL unique index on
   > `(provider, provider_transaction_id, invoice_reference)` over settling event types only — and
   > recorded the remaining gap in the migration's own doc block.
   (Source migration: `database/migrations/2026_08_09_100200_create_provider_events_table.php`.)

3. **CONSTRAINT TRIGGER / PL-pgSQL function** — the strongest precedent, and the most relevant to
   AC11's cross-table uniqueness:
   `database/migrations/2026_08_10_120200_strengthen_payout_invariants.php` — a PL/pgSQL function
   declaring locals (`:34-38`), comparing a payout against its payable (`:54`), and attached as
   ```
   BEFORE INSERT OR UPDATE OF payable_id, vendor_id, entity_ref, amount_minor ON payouts   # :65
   ```
   plus a tightened check (`:21-23`): drop `payouts_amount_minor_check`, re-add as
   `CHECK (amount_minor > 0)`, with `down()` restoring `>= 0` (`:109-111`).

   A comparable balanced-journal aggregate check exists at
   `database/migrations/2026_08_09_110100_create_journal_entries_table.php:57`:
   `SUM(CASE WHEN direction = 'DR' THEN amount_minor ELSE -amount_minor END)`.

⚠ **The PostgreSQL-only artifacts are systematically NOT verified locally.** The local suite runs on
SQLite (`phpunit.xml` default). Every payment task's disposition says so —
`.kiro/specs/platform-payment-adapter/tasks.md:43,49,55`. The one exception is Task 6, which was
verified against a real `postgres:18` container (`tasks.md:61`). Any L8 PostgreSQL-only constraint
should plan for the same: either a container check or an explicit **NOT TESTED** carried to sign-off.

## D.3 Money representation across the codebase

**Authoritative: `App\Platform\FinancialLedger\Money`** — `app/Platform/FinancialLedger/Money.php:16`.
See §A.1.3 for the full API. Key properties:
- Integer minor units only; the constructor throws `\TypeError` on a non-int (`Money.php:26-28`).
- Class doc block (`Money.php:10-15`): "Immutable money value represented as an integer number of
  minor units. Decimal catalog values are converted here at the read seam. **No float input, property,
  or arithmetic is permitted on this type.**"
- `config/money.php` → `'currency' => 'IDR'`, `'minor_units' => 2`.

**Column convention:** every money column is named `*_minor` and typed `bigInteger` /
`unsignedBigInteger` — never `decimal`, never `float`. Complete list found by grep across
`database/migrations/`:
`payment_intents.requested_amount_minor` (`...100000:69`),
`provider_events.amount_minor` (`...100200:116`, nullable),
`journal_entries.amount_minor` (`...110100:19`),
`vendor_payables.amount_minor` (`...120000:65`),
`payouts.amount_minor` (referenced `2026_08_10_120200_strengthen_payout_invariants.php:23`).

**Cast convention:** `'integer'`, with an explicit doc block explaining why not `decimal` —
`PaymentIntent.php:80-85`, `PaymentSession.php:75-81`.

**Governing ruling:** "Wave 0 ruling 0c" — cited at `PaymentIntent.php:81`, `PaymentSession.php:76`,
`...100100_create_payment_sessions_table.php:66`, `GuardPaymentSession.php:104`,
and in the plan doc `docs/superpowers/plans/2026-08-09-platform-payment-adapter.md:7`:
> Money contract: **integer minor units (IDR × 100) everywhere** (Wave 0 ruling 0c, AC11-in-ledger) —
> no float anywhere in the payment path.

**For the L8 lane:** a renewal fee/quote amount must be `Money` in PHP and `bigInteger *_minor` in the
schema. `vendor_payables` (`...120000:101-102`) is the citable precedent for pairing that with a
`CHECK (amount_minor >= 0)`.

## D.4 How public Livewire journey components are structured and tested

### Structure

Both renewal components are **plain `Livewire\Component` subclasses** — no project base class exists.
- `final class RenewalStart extends Component` — `app/Livewire/Public/Renewal/RenewalStart.php:54`
- `final class GraveSearch extends Component` — `app/Livewire/Public/Renewal/GraveSearch.php:81`

Both declare `strict_types=1` and live in `App\Livewire\Public\Renewal`.

`RenewalStart.php:29-32` names the reference shape:
> Same structural shape as `App\Livewire\Public\Faq\FaqIndex`: a plain `Livewire\Component`,
> `->layout('layouts.app', [...])` attached per-render so `<title>` can follow the selected city,
> read-only, no `app/Domain/**` write path anywhere in this class.

**View location:** `resources/views/livewire/public/renewal/<kebab-name>.blade.php`, returned by name
from `render()`:
- `return view('livewire.public.renewal.start', [...])` — `RenewalStart.php:177`
- `return view('livewire.public.renewal.grave-search', [...])` — `GraveSearch.php:265`

**Layout:** attached per-render, never via a class property —
`RenewalStart.php:187-192`:
```
])->layout('layouts.app', [
    'title' => $selectedCityLabel !== null
        ? 'Perpanjangan Makam '.$selectedCityLabel.' - Makam.co.id'
        : 'Perpanjangan Makam - Makam.co.id',
    'active' => 'perpanjangan',
]);
```
Same at `GraveSearch.php:274`.

**State:** minimal, URL-bound via `#[Url]`, with the step **derived** rather than stored.
- `#[Url(as: 'kota', history: true)] public string $city = '';` — `RenewalStart.php:62-63`
- `GraveSearch` binds four: `tpu` (`:90`), `nama` (`:93`), `blok` (`:96`), `tanggal` (`:99`).
- `RenewalStart.php:57-61`: "Empty means step 1 is still open — which is what makes the stepper's
  current step derivable rather than tracked as a second, drift-prone piece of state."
- `private function currentStep(): int` — `RenewalStart.php:142-147`.

⚠ **Design constraint recorded for Sprint 13** —
`docs/superpowers/plans/2026-08-09-retrofit-renewal.md` (reconstructed brainstorming, Q2):
> "`RenewalStart` derives `currentStep()` from `$city` alone. What happens when steps 4-6 arrive and
> the journey needs real cross-step state — does this shape extend, or does it get thrown away?"

This is an explicitly open question the L8 lane inherits.

**Validation — two distinct approaches, deliberately:**
1. `protected function rules(): array` (`GraveSearch.php:168`) + `$this->validate()` inside the user
   action `search()` (`GraveSearch.php:189-191`).
2. **For `#[Url]`-hydrated values, a manual `Validator::make(...)` in `mount()`, NOT `$this->validate()`** —
   `GraveSearch.php:127-148`. The reasoning at `:127-139`:
   > `#[Url]`-bound values also arrive straight from the query string on … never met `rules()`. An
   > unvalidated `?tanggal=` reached … Deliberately NOT `$this->validate()`: that throws … the same
   > pattern `CemeteryDirectoryIndex` uses for its own `#[Url]` properties.
3. **Unknown/tampered URL values are silently discarded, never 404** —
   `RenewalStart::normalizeCity()` (`RenewalStart.php:90-95`), called from **both** `mount()` (`:76`)
   **and** `render()` (`:151`), because "mount() runs once, and a client-initiated property update
   re-hydrates without re-running it" (`:85-88`). Same no-op posture in `selectCity()` (`:99-101`) and
   `goToStep()` (`:131-136`).

**Degraded reads never 500** — `RenewalStart::render()` wraps the secondary query in try/catch, calls
`report($e)`, and sets a flag (`RenewalStart.php:157-167`), per design-system §6.5/§6.3.

**Server-resolved modes, never a client flag** — `RenewalStart.php:183-186`:
```
// Read from the server every render — never a client-supplied
// flag (design-system.md §6.9, and `ModeResolver` is the one
// place gate-id-to-mode pairing lives).
'graveSearchMode' => app(ModeResolver::class)->graveSearchMode(),
```
The payment analogue: `ModeResolver::paymentMode()` —
`app/Platform/FeatureGate/ModeResolver.php:33-36`:
```
public function paymentMode(): PaymentMode
{
    return PaymentMode::fromGateOpen($this->gates->isOpen('G-PAY-01'));
}
```
`PaymentMode` cases: `Online = 'online'` (`app/Platform/FeatureGate/Modes/PaymentMode.php:25`),
`ManualCoordination = 'manual_coordination'` (`:35`).

⚠ **`G-PAY-01` is closed.** The plan doc goal statement
(`docs/superpowers/plans/2026-08-09-platform-payment-adapter.md:5`) says `G-PAY-01` **"staying closed
for production"**, and `app/Livewire/Public/Renewal/GraveSearch.php:44-47` records that
`2026_07_26_120400_seed_feature_gate_registry.php` **seeds every gate closed**, so an unmodified
environment resolves `PaymentMode::ManualCoordination`. Renewal Step 5 must therefore render the
manual-coordination path as its **default**, not its exception.

**Stepper rendering** — passed as view data, never hardcoded in Blade:
`RenewalStart.php:181-182`:
```
'currentStep' => $this->currentStep(),
'stepLabels' => RenewalJourneyStep::labels(),
```
`<x-mk.stepper>` takes an optional `labels` prop whose default is the nine booking steps;
`RenewalJourneyStep.php:16-25` documents that this journey is the single reason the prop exists:
> "`labels` exists for a DIFFERENT JOURNEY, never for re-labelling booking." Every renewal screen
> passes the same six, so they are defined once here rather than retyped per view.

Back-nav target defaults to `goToStep` (`RenewalStart.php:111-136`).

⚠ **All six steps must stay visible even though 4-6 are unbuilt.** `RenewalJourneyStep.php:34-48`:
> Showing all six from day one is required, not optional: `requirements.md` AC1 … binds all six as
> visible, and design-system.md §9.2 MUST NOT 9 forbids hiding a documented step. … design-system.md
> §6.9's `PaymentMode` row is the concrete instance ("Step 8 is never removed.").

`RenewalJourneyStep::LAST_IMPLEMENTED = self::GRAVE_SEARCH` (`RenewalJourneyStep.php:84`) is the
constant the L8 lane will advance — and per `.kiro/specs/renewal-and-grave-registry/tasks.md` (quoted
in the retrofit plan), `RenewalJourneyStepTest::test_only_the_first_three_steps_are_implemented_in_sprint_4`
pins that boundary in code.

**Payment-state → design-token contract is owned by the payment spec, not by the renewal screen** —
`.kiro/specs/platform-payment-adapter/tasks.md:21-28`:
> Payment UI lives in the consuming specs (booking Step 8, renewal Step 5, marketplace checkout), but
> the **state contract** is owned here. …
> - `MENUNGGU_PEMBAYARAN` → `pending`; `MENUNGGU_VERIFIKASI_PEMBAYARAN` → `pending`, **never
>   `success`**; `DIBAYAR` → `success`.
> - `MANUAL_COORDINATION` renders an `<x-mk.alert intent=info>` banner (§6.9) that is **not
>   dismissible** … Step 8 is never removed.
> - Provider unavailable follows §6.5: fallback path or truthful pending, never a dead end.
> - Duplicate submission follows §6.6: the same confirmation, never a second order.
> - Never surface a provider name, stack trace, or correlation ID to a public user; return a support
>   reference instead.
> - Resolve all states through the shared `StatusIntent` helper; never `match` on an enum in a view.

⚠ Note the tension: those order-scope names (`MENUNGGU_VERIFIKASI_PEMBAYARAN`, `DIBAYAR`) exist
**nowhere in code** — `SessionState.php:17-25` explicitly forbids adding them to the session enum, and
`app/Domain/OrderWorkflow/` is empty. `.kiro/specs/platform-payment-adapter/tasks.md:55` confirms the
banner is unbuilt because it "reads from session/order state that does not exist yet".

### Testing

Test files (verified by listing):
```
tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php        (258 lines, 13 test methods)
tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php
tests/Unit/Domain/Renewal/RenewalJourneyStepTest.php              (100 lines, 7 test methods)
```

Conventions, from `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php`:
- `final class RenewalStartTest extends TestCase` (`:31`) with `use RefreshDatabase;` (`:33`),
  `declare(strict_types=1)` (`:3`), namespace `Tests\Feature\Livewire\Public\Renewal` (`:5`).
- **`Livewire::test(RenewalStart::class)`**, not `$this->get('/perpanjangan')` — `:47`, `:75`, `:84`,
  `:99` etc. The doc block at `:23-29` explains: `routes/web.php` was a shared file the batch did not
  own, so **"The route, its name, and its HTTP status are therefore NOT TESTED here"**.
  The L8 lane, if it owns its routes, should test them.
- PHPUnit-style method names, long and behavioural, `test_`-prefixed:
  `test_all_five_launch_cities_are_offered_in_the_canonical_order` (`:45`),
  `test_an_unknown_city_code_is_discarded_rather_than_404ing` (`:125`),
  `test_a_client_supplied_unknown_city_is_discarded_on_update_not_only_on_mount` (`:144`),
  `test_the_stepper_shows_this_journeys_six_steps_not_the_nine_booking_ones` (`:164`),
  `test_the_closed_data_gate_renders_an_honest_banner_without_removing_the_step` (`:244`),
  `test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing` (`:297`).
- Tests are grouped under `// ===` banner comments naming the AC they cover (`:35-37`).
- Each test's doc block quotes the governing authority (`AGENTS.md` §Mandatory MVP UX at `:40-44`).
- Gate-dependent behaviour is tested **both ways** — closed (`:244`) and opened (`:260`) — by writing
  a real `FeatureGate` model (imported at `:12`), never by mocking the resolver.
- Failure paths are tested by forcing the real read to throw (`:297-309`), asserting degradation
  rather than a 500.

⚠ **This host cannot run the test suite.** `docs/superpowers/plans/2026-08-09-retrofit-renewal.md`
(Global Constraints):
> **This host cannot run PHP tests at all** — `vendor/` is empty and `CLAUDE.md` forbids
> `composer install`/`npm run build` here. `php -l` and `bash ci/verify-docs.sh` are the only
> executable local gates. Every reviewer must state test execution as BLOCKED rather than implying a
> pass.

**Nothing in this survey was executed. All statements above are derived from reading committed files.**

---

# E. Consolidated open questions for the L8 plan doc

1. **A.5 / escalation:** renewal Step 5 cannot create a payment session. Does the L8 lane (a) build
   only the manual-fallback path on the existing subject-agnostic `payment_verifications` seam,
   (b) block on `booking-and-order-orchestration` (Sprint 7) for `Quote`/`Confirmation`/
   `AuthorizePaymentOpening`/`Merchant`, or (c) something else? Ruling 1b-L3-01's standing instruction
   to a lane that hits this wall is **escalate** (`GuardPaymentSession.php:58-62`).
2. **C.3 / escalation:** `docs/security/rbac-matrix.md` has no row for marking an external
   renewal/payment with evidence, and its nearest row (`Quote/open payment`, `:11`) says
   Operator = `No`, contradicting AC10's "admin/operator". Needs a human decision on role, scope
   entity type, and grant level.
3. **A.3.2 finding:** neither shipped payment admin write path performs a role check. Should the L8
   external-marking action follow that precedent, or introduce the `*Authorizer` pattern
   (`FinanceOrRestrictedAdminPayoutAuthorizer` shape) that FinancialLedger uses? A decision either way
   should be explicit, since copying the precedent silently inherits the gap.
4. **AC11 cross-table uniqueness:** `design.md:38-46` requires `grave_record_id + target_due_period` to
   be unique across **both** `renewals` and `renewal_external_markings`. A single-table `UNIQUE` cannot
   express that. The repo's existing tool for cross-table invariants is the constraint trigger at
   `database/migrations/2026_08_10_120200_strengthen_payout_invariants.php:34-65` — PostgreSQL-only,
   therefore unverifiable on the local SQLite suite.
5. **`payment_verifications.reference` is a flagged, non-final shape**
   (`...100000_create_payment_verifications_table.php:30-34`) and is expected to gain a real foreign
   key. Building renewal Step 5 on it means accepting a future migration against the column.
6. **`G-PAY-01` is closed by default**, so `PaymentMode::ManualCoordination` is the default resolved
   mode. Renewal Step 5's primary rendered path should be the manual one, with the non-dismissible
   `<x-mk.alert intent=info>` banner (`.kiro/specs/platform-payment-adapter/tasks.md:24`).
7. **A.5.7 (minor, unrelated to renewals):** `PaymentVerification::decide()` declares a non-nullable
   `string $decidedByActorRef` but its only caller can pass `null`
   (`VerifyManualPayment.php:96`), which under `strict_types=1` is a `TypeError`. NOT TESTED —
   observation from reading only.
