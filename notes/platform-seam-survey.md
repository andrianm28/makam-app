# Platform seam survey — inputs for L7 order orchestration

Captured 12 Aug 2026 against trunk `d9fea9f`. This is a **working note**, not a
canonical document: every statement here is a snapshot of code that existed at
that commit. Verify against the code before relying on any signature — do not
cite this file as authority in a spec, ADR, or plan.

## 1. `app/Platform/Payment/`

**Models** (all `HasUuids`, uuid string PK):

- `Models\PaymentSession` (`payment_sessions`) — fillable: `payment_intent_id,
  provider, provider_payment_id, payment_link_url, amount_minor(int), currency,
  merchant_ref, badan_usaha_ref, state, expires_at`. **`creating` hook
  unconditionally throws `PaymentSessionCreationUnavailableException`** — no row
  is insertable via Eloquent today (ruling 1b-L3-01, deny-only guard).
  `paymentIntent(): BelongsTo`.
- `Models\PaymentIntent` (`payment_intents`, no `updated_at`) — append-only
  decision record. Fillable: `requested_amount_minor(int), currency,
  payment_mode, decision, denied_condition, denial_reason, missing_upstream,
  denied_conditions(array), public_message, actor_ref, actor_role,
  correlation_id, evaluated_at`. `update()`/`delete()`/`performUpdate()` always
  throw. Only writer: `GuardPaymentSession::record()` via `PaymentIntent::create()`.
- `Models\ProviderEvent` (`provider_events`) — append-only except
  `MUTABLE_COLUMNS = [status, rejection_detail, validated_at,
  signature_mechanism, updated_at]`. Fillable includes `provider,
  provider_event_id, event_id_source, provider_transaction_id,
  invoice_reference, event_type, merchant_ref, amount_minor(int),
  declared_currency, event_occurred_at, raw_payload(encrypted,hidden),
  payload_digest, signature_mechanism, signature_timestamp,
  signature_header(encrypted,hidden), status, rejection_detail, received_at,
  validated_at, correlation_id`. `markStatus(ProviderEventStatus, ?string
  $rejectionDetail = null): void` is the only lifecycle mutator.
- `Models\PaymentVerification` (`payment_verifications`) — **decoupled: no FK to
  any order/booking table, `reference` is free text.**
  `createSubmitted(array): static`, `attachProof(string $documentId): void`,
  `decide(PaymentVerificationDecision, string $decidedByActorRef, ?string
  $reason = null): void`, `status(): PaymentVerificationStatus`.
- `Models\PaymentReversal` (`payment_reversals`) — self-contained, no FK to
  sessions/verifications/order. `createRecorded(array): static` is the only
  writer.

**Guard** — `GuardPaymentSession`, `final readonly`, ctor `(ModeResolver,
ActorContextResolver, CorrelationContext)`, `__invoke(Money $requestedAmount):
GuardResult`. Deny-only: no reachable PASS. Evaluates all six
`GuardCondition::inEvaluationOrder()` without short-circuiting, writes one
`payment_intents` row plus an `Audit::wrap()`'d `PAYMENT_GUARD_DENIED` per call.

`GuardResult` — private ctor; only factory `denied(array $denials): self`
(throws if empty); `isAllowed()` always false; `condition()/reason()/
publicMessage()/missingUpstream()` report the first failure; `denials():
non-empty-list<ConditionDenial>`; `deniedConditionValues(): non-empty-list<string>`.

`GuardCondition` (fixed `ORDER`, `position(): int` 1-based): `ProductGateOpen |
ConfirmationOrReservation | QuoteAcceptedAndUnexpired | AuthorizedOpening |
AmountMatchesQuoteTotal | MerchantAndBadanUsahaBound`.

`GuardDenialReason`: `DomainDenied | UnavailableUpstream`.

**Webhook receiver** — `ReceiveWebhook::__construct(WebhookValidator)`,
`__invoke(InboundWebhook): ReceiveWebhookResult`. Persists the `ProviderEvent`
BEFORE validating (evidence-first), acks <= 2s, dispatches
`ProcessProviderEventJob::dispatch($event->getKey())->afterCommit()`.

**Idempotency contract (exact):**

- Primary: unique `(provider, provider_event_id)` on `provider_events`.
  `provider_event_id` = `svix-id` header when present and shape-valid, else
  `'sha256:' . hash('sha256', $rawBody)`; the choice is recorded in
  `event_id_source` (`svix-id` | `body-digest`).
- Secondary: partial unique index on `(provider, provider_transaction_id,
  invoice_reference)` scoped to settling event types only
  (`ProviderEventType::Completed`) — deliberately partial so an out-of-order
  `expired`-after-`completed` delivery can still persist.
- Insert `QueryException` → `resolveDuplicate()` re-locates the original row
  `lockForUpdate()`, compares `payload_digest` with `hash_equals`, then either
  resumes validation (still `RECEIVED`), returns current state
  (`Processing`/`RetryableFailure`), or writes `PAYMENT_WEBHOOK_DUPLICATE` audit
  and returns `Duplicate`. The original row is never re-statused.
- Apply-time claim — `ProcessWebhookEvent::__invoke(string $providerEventId):
  ProcessWebhookEventOutcome`: compare-and-set `VALIDATED → PROCESSING` under
  `lockForUpdate()`, plus a third-level claim over `(provider,
  provider_transaction_id)` for settling events (locks the whole settling set
  for that transaction id, in `id` order, so two invoices cannot claim one
  transaction). Conflict → `MANUAL_REVIEW` +
  `PAYMENT_WEBHOOK_SETTLEMENT_CONFLICT` audit.
  **This class only claims — it applies no paid effect.** Setting `PAID` and
  calling `Journal::post()` is explicitly NOT implemented and scoped to whoever
  builds the real session-creation path (i.e. L7).
- Outcomes: `NotFound | NotClaimable | Claimed | SettlementConflict`.

**Enums:** `PaymentIntentDecision`: `Denied` only (`Allowed` deliberately
absent, CHECK-constrained). `PaymentVerificationDecision`: `Approve | Reject` →
`resultingStatus()`. `PaymentVerificationStatus`: `SUBMITTED | VERIFIED |
REJECTED` (own machine, does NOT reuse `SessionState`). `ProviderEventStatus`:
`RECEIVED | VALIDATED | PROCESSING | PROCESSED | DUPLICATE | REJECTED_PAYLOAD |
REJECTED_SIGNATURE | REJECTED_REPLAY | REJECTED_MERCHANT | REJECTED_SESSION |
REJECTED_CURRENCY | REJECTED_AMOUNT | RETRYABLE_FAILURE | MANUAL_REVIEW`
(`REJECTED_SESSION` is where every valid webhook lands today).
`ProviderEventType`: `Completed('payment.completed') | Failed | Expired | Test`;
`settlingValues() = [Completed]`. `PaymentReversalType`: `REFUND | CHARGEBACK`.
`SessionState`: `CREATED | AWAITING_PAYMENT | PAID | FAILED | EXPIRED |
REFUNDED` — **payment-session scope only, never order scope**; nothing writes
these yet. `PaymentAuditActions` consts: `GUARD_DENIED, WEBHOOK_REJECTED,
WEBHOOK_DUPLICATE, WEBHOOK_SETTLEMENT_CONFLICT, MANUAL_SUBMITTED,
MANUAL_VERIFICATION, REFUND, CHARGEBACK`.
`PaymentProviders::SUMOPOD_SANDBOX = 'sumopod-sandbox'`.

## 2. `app/Platform/FinancialLedger/`

`Money` (`final readonly`) — `__construct(mixed $minorUnits)` (TypeError unless
`int`); public readonly `int $minorUnits`. Static `fromDecimal(string $amount):
int` **returns a raw int, not a `Money`** — the idiom is `new
Money(Money::fromDecimal($decimalString))`. Instance: `toMinorInt(), add(self),
subtract(self), negate(), compare(self): int, isPositive(): bool, format():
string`. Arithmetic is overflow-checked.

`Journal implements Contracts\Journal` — **not bound in a service provider**;
`app(Contracts\Journal::class)` will not resolve, `app(Journal::class)` does via
autowiring.

```
post(string $businessKey, int|string $entityRef, string $sourceType,
     int|string $sourceId, array $entries, ?string $correlationId = null,
     ?string $occurredAt = null): JournalBatch
```

`$entries` is `list<{account, direction:'DR'|'CR', amountMinor:int, reference?}>`.
`$businessKey` must be source-prefixed (`"payment:{provider_event_id}"`,
`"manual_verify:{verification_id}"`), is UNIQUE, and **is the idempotency key** —
a collision throws. `$entityRef` is the badan usaha, must not be blank. Entries
must balance, enforced by a Postgres trigger (not by this class — `post()` only
validates shape). Does not open its own transaction: call inside the caller's
`DB::transaction()`. `postReversal(...)` posts flipped entries, links via
`reverses_batch_id` (real FK, UNIQUE), wraps its own transaction plus a
`JOURNAL_REVERSAL` audit.

**Prices** — `price_versions`, polymorphic (`priceable_type`/`priceable_id`,
FQCN, no morph map). `amount` is `decimal(12,2)` / `'decimal:2'` cast, so it
**hydrates as a PHP string**. Convert at the read seam with
`Money::fromDecimal($priceVersion->amount)`. Append-only except `superseded_at:
null → value`. A quote line must snapshot the `PriceVersion` id/amount/currency/
version_number at creation and never re-read a live current price.

## 3. `app/Platform/IdentityAccess/`

`Roles\ActorRole::KNOWN_ROLES`: `admin, restricted_admin, finance, operator,
case_manager, vendor, customer, system`. `guest`/`authenticated_actor` must
never be added — they are audit sentinels meaning "no role".

`Scopes\Models\ScopeAssignment` — `ScopeEntityType::KNOWN_TYPES` already
includes **`order`** (`ORDER = 'order'`) and `CASE_RECORD = 'case'`, plus
`cemetery, vendor, grave, business_entity`. **No migration needed to scope by
order or case.** `ScopeGrantLevel::KNOWN_LEVELS`: `own | assigned | read |
privileged` (metadata only; not enforced by the query-scope mechanism itself).

Authorization pattern — there is **no generic helper**. Each Action writes its
own authorizer against `ActorContext`. Precedent:
`FinanceOrRestrictedAdminPayoutAuthorizer` checks `roles`, then an explicit
`ScopeAssignment::query()->where(...)->exists()`, then throws a dedicated
`*NotAuthorisedException`. No `AuthorizePaymentOpening` exists yet — that is
what `GuardCondition::AuthorizedOpening` waits on.

`ActorContext`: `identityReference`, `roles: list<string>`, `scopes:
list<string>` (format `"entity_type:entity_id"`), `mfaState`,
`lastAuthenticatedAt`; `hasRole()`, `hasScope()`. Empty means "none granted",
never "none required".

## 4. `app/Platform/DocumentVault/`

`Actions\UploadDocument::upload(DocumentKind $kind, UploadedFile|StreamInterface
$file, string $ownerType, int|string $ownerId, ?string $clientUploadId, array
$meta): Document` — `owner_type`/`owner_id` are free text with no FK; this is
how a domain module attaches a document to its own record. Quarantine-first,
idempotent resume via `client_upload_id`.

`Actions\IssueSignedUrl::issue(...)` requires `DocumentAccessPolicy::canView()`
plus `ACCEPTED` state; max TTL 300s.

`DocumentKind` — 9 closed cases including `PaymentProof`; **no `Order`/`Quote`
kind yet**, add one if needed. `DocumentAccessPurpose`: `VIEW | DOWNLOAD |
UPDATE | DELETE | GRANT`.

## 5. `app/Platform/Audit/`

```
Audit::record(action, subject, outcome, actorRef, actorRole, source,
              reason = null, correlationId = null, metadata = []): AuditEvent
Audit::wrap(mutation, action, subject, outcome, actorRef, actorRole, source,
            reason = null, correlationId = null, metadata = []): mixed
```

`wrap()` runs the mutation and `record()` in one `DB::transaction()` — the right
tool for "apply order state change + audit atomically".
`AuditSubject(type, id, version = null)`. `AuditOutcome`: `Allowed | Denied |
Failed`. `AuditSource`: `Panel | Api | Job | Console`.

Mandatory-reason enforcement (`SensitiveActions::requiresReason()` plus
`Audit::reasonIsBlank()`, Unicode-aware, recently fixed) is **the only
authoritative check** — do not reimplement it.

`SensitiveActions::ACTIONS` at `d9fea9f`: `DITOLAK, PLOT_OVERRIDE,
TARIFF_SOURCE_CHANGE, GATE_CHANGE, PAYMENT_MANUAL_VERIFICATION,
CERTIFICATE_REVOKE, VENDOR_PAYOUT, JOURNAL_REVERSAL, PRICE_VERSION_RECORDED,
SERVICE_DEFINITION_PRICE_VERSION_RECORDED, MFA_RESET, DOCUMENT_DELETE,
RECONCILIATION_EXCEPTION_RESOLVED, PAYMENT_REFUND, PAYMENT_CHARGEBACK,
ROLE_GRANT, ROLE_REVOKE, SCOPE_GRANT, SCOPE_REVOKE`.

## 6. `app/Domain/ServiceCatalog/`

`FulfillmentOwner::KNOWN_OWNERS`: `platform | cemetery_operator | vendor`. A
quote line must reference a **published, frozen `ServicePackageVersion`**
(immutable once published, structurally enforced), never the mutable
`ServicePackage`.

## Conventions

Primary keys are mixed per table: `uuid('id')->primary()` + `HasUuids` dominates
recent Payment/FinancialLedger/DocumentVault tables, while
`actor_role_assignments`, `journal_entries`, and `scope_assignments` use bigint
auto-increment. Check each migration rather than assuming. New payment-adjacent
tables should use uuid PKs to match the dominant recent pattern.

## Confirmed absent

No `Order`, `Quote`, or `QuoteLine` class exists anywhere.
`app/Domain/OrderWorkflow/` and `app/Domain/Quotation/` are `.gitkeep`-only;
`app/Domain/PlotReservation/` likewise. `ScopeEntityType::ORDER` and
`CASE_RECORD` are pre-reserved and ready to use.
