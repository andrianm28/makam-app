# Batch 2I — Payment verification admin decision actions (FIL-04)

Derived from `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` §Batch
2I (read from `fix/phase0-critical-stopgaps` worktree, not yet merged to
trunk — this document restates only the scope needed to execute, not the
full audit finding).

## Problem

- `PaymentVerificationsResource` registers only `index`/`view` pages.
  `Pages/ViewPaymentVerification` is an empty `ViewRecord` subclass with no
  header action — the only way to decide a manual payment verification today
  is the raw HTTP endpoint `VerifyManualPaymentController`, with no admin UI.
- `RecordPaymentReversalController` has no admin UI caller either, and does
  not catch the one domain exception (`PaymentReversalAlreadyRecordedException`)
  its callees (`RecordRefund`/`RecordChargeback`, via `ReversalService`) can
  throw — a duplicate `(type, reference)` submission would 500.

## Scope

1. `app/Filament/Admin/Resources/PaymentVerifications/Actions/DecidePaymentVerificationAction.php`
   (new) — parameterized by `PaymentVerificationDecision`, structured after
   `RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php`:
   required `Textarea::make('reason')`, `->authorize()` calling
   `PaymentActionAuthorizer::authorize()`, then in `->action()`: re-check the
   authorizer (capturing the approved role) **then**
   `ReauthenticationGuard::assertFresh()`, catching
   `ReauthenticationRequiredException` with the standard session-flash +
   redirect-to-`PasswordReauthentication::ROUTE_NAME` block. On success,
   calls `VerifyManualPayment::verify()`. Visible only while the record is
   `SUBMITTED`.
2. `app/Filament/Admin/Resources/PaymentVerifications/Actions/RecordPaymentReversalAction.php`
   (new) — same shape, parameterized by `PaymentReversalType`, form fields
   `reference` (defaulted to the verification's own `reference`),
   `amount_minor` (nullable integer), `reason` (required). Calls
   `ReversalService::record()`. Visible only once the verification is
   `VERIFIED` (nothing to reverse before that). Catches
   `PaymentReversalAlreadyRecordedException` with a dedicated notification
   message, distinct from the generic `Throwable` catch.
3. `Pages/ViewPaymentVerification.php` — add `getHeaderActions()` wiring all
   four actions (approve, reject, record refund, record chargeback).
4. `RecordPaymentReversalController.php` — catch
   `PaymentReversalAlreadyRecordedException` and respond `abort(409,
   $exception->getMessage())`, mirroring `FinanceExportController`'s
   `InvalidLedgerReportException` convention (message is safe to surface —
   it names only the reversal type and the caller-supplied reference).

## Non-goals

- No change to `PaymentVerificationsResource::getPages()` — `index`/`view`
  remain the only pages; no `create`/`edit`.
- No new `PaymentReversal` Filament resource/browse screen — reversal
  actions are reached only from the verification's own view page, per the
  audit finding's literal scope.
- No change to `VerifyManualPaymentController`/`VerifyManualPayment`/
  `RecordRefund`/`RecordChargeback` domain logic.

## Test plan

`tests/Feature/Filament/PaymentVerificationAdminActionsTest.php` (new),
through `Livewire::test(ViewPaymentVerification::class, ...)->callAction(...)`
(never calling the action class directly):

- A `finance`-role actor with a fresh session can approve a `SUBMITTED`
  verification with a linked order — asserts `VERIFIED` status, an
  `audit_events` row, and the linked marketplace order marked paid.
- A `finance`-role actor can reject a `SUBMITTED` verification — asserts
  `REJECTED` status and the audit row, no order mutation.
- A stale session (seeded `ActorSession` older than
  `config('reauthentication.freshness_seconds')`) is refused: the action
  redirects to `PasswordReauthentication::ROUTE_NAME`, `assertNotified`, and
  the verification is untouched (regression pattern matching
  `CertificateAdminTest`'s step-up tests on `fix/batch2a-stepup-authentication`).
- An `operator`-role actor cannot see/run either decide action
  (`isAuthorized()` false).
- A `finance`-role actor can record a refund against a `VERIFIED`
  verification — asserts a `payment_reversals` row and audit event.
- Recording a duplicate `(type, reference)` reversal surfaces the
  duplicate-specific notification rather than a 500.

## Verification

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress`
- `bash ci/verify-docs.sh`
- Full new test file + touched-area regression run against real
  `postgres:18`/`redis:8.2-alpine` in Docker (container prefix `b2i-`).
