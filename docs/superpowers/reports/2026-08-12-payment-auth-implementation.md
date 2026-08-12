# Payment controller authorization hotfix — implementation report

**Date:** 2026-08-12
**Branch:** `fix/payment-controller-authorization` (worktree `.worktrees/platform-payment-auth-hotfix`)
**Plan:** [`docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md`](../plans/2026-08-12-payment-controller-auth-hotfix.md)
**State:** implemented, not committed — all changes left in the working tree as instructed.

---

## 1. Files created

| File | What it is |
| --- | --- |
| `app/Platform/Payment/Contracts/PaymentActionAuthorizer.php` | Interface. `authorize(ActorContext $actor): string` — returns the approved role, throws on refusal. No scope parameter (plan D1). |
| `app/Platform/Payment/FinanceOrRestrictedAdminPaymentAuthorizer.php` | Implementation. Refuses a null `identityReference`; refuses when `$actor->roles` holds neither `ActorRole::RESTRICTED_ADMIN` nor `ActorRole::FINANCE`; otherwise returns the matched role. |
| `app/Platform/Payment/Exceptions/PaymentActionNotAuthorisedException.php` | `extends RuntimeException`, no `render()`, one named constructor `forActorContext()`. |
| `tests/Unit/Platform/Payment/FinanceOrRestrictedAdminPaymentAuthorizerTest.php` | 15 unit tests of the policy in isolation (plain PHPUnit `TestCase`, no framework boot). |
| `tests/Feature/Payment/PaymentAuthorizerBindingTest.php` | 3 tests: provider registered, interface resolves to the concrete policy, binding is transient not shared. |

## 2. Files changed

| File | Change |
| --- | --- |
| `app/Platform/Payment/Providers/PaymentServiceProvider.php` | Added `$this->app->bind(PaymentActionAuthorizer::class, FinanceOrRestrictedAdminPaymentAuthorizer::class)` in `register()` (transient, not `singleton()`). Class doc block updated from "two container-level concerns" to three. |
| `app/Platform/Payment/Http/Controllers/RecordPaymentReversalController.php` | Authorize first; `try`/`catch (PaymentActionNotAuthorisedException) { abort(403); }`; both `actorRole: 'authenticated_actor'` occurrences (old lines 76, 102) replaced with `$actorRole`. Existing class doc block preserved and amended — the "`satisfy()` first" description was now inaccurate. |
| `app/Platform/Payment/Http/Controllers/VerifyManualPaymentController.php` | Same, plus authorization placed before `PaymentVerification::findOrFail()`. Both `actorRole: 'authenticated_actor'` occurrences (old lines 61, 81) replaced. Existing class doc block preserved and amended. |
| `tests/Feature/Payment/RecordPaymentReversalRouteTest.php` | See §4. |
| `tests/Feature/Payment/VerifyManualPaymentRouteTest.php` | See §4. |

**Not touched, as instructed:** `GuardPaymentSession.php`, `GuardCondition.php`, `SensitiveActions.php`, and the signatures of `ReversalService::record()` / `VerifyManualPayment::verify()`. The four `FinancialLedger` authorizers were also left alone (see §6.1).

Final ordering in both controllers: resolve `ActorContext` → authorize (throws → 403) → `satisfy($role)` → `validate()` → `findOrFail()` (verification only) → write API call with the real role.

## 3. Role-granting mechanism used in tests

**Real `actor_role_assignments` rows**, not a container-bound fake `ActorContext`.

```php
private function grantRole(User $user, string $role): ActorRoleAssignment
{
    return ActorRoleAssignment::create([
        'actor_identifier' => (string) $user->id,
        'role' => $role,
    ]);
}
```

Why this and not `ManualPayoutTest`'s shape: `ManualPayoutTest` and `BulkFinancialExportTest` call `$this->app->instance(ActorContext::class, new ActorContext(...))`, which is correct for their direct-call Action tests but would stub out identity resolution entirely. These are HTTP tests of an authorization fix, so they run the whole live chain: `actor_role_assignments` → `Roles\ActorRoleReader` → `Adapters\LocalUsersTableIdentityAccessAdapter` → `ActorContextResolver` → controller → authorizer. No existing test helper for seeding a role exists anywhere in `tests/`; the only precedents (`ActorRoleReaderTest`, `ActorRoleAssignmentModelTest`) create the model directly, which is what these helpers do.

Three helpers were added to each route test file:

- `grantRole(User, string): ActorRoleAssignment` — above.
- `freshlyAuthenticatedUser(): User` — user + a recent `actor_sessions` row, **no** role. Every denial test needs this: without the `actor_sessions` row `RequireRecentAuthentication` would redirect to the challenge and the authorization refusal would never be reached, so a denial test built on a bare user would pass for the wrong reason.
- `authorizedUser(string $role = ActorRole::FINANCE): User` — both gates satisfied.

## 4. Existing tests modified, and why

Every success-path test in both files failed after the change, exactly as the plan predicted. Modifications are `authorizedUser()` substitutions unless noted.

### `RecordPaymentReversalRouteTest.php` (12 pre-existing methods)

| Test | Change | Why |
| --- | --- | --- |
| `test_recording_a_refund_with_a_fresh_authentication_actually_records_it` | `authorizedUser()` | Was 403 without a role. |
| `test_recording_a_chargeback_with_a_fresh_authentication_actually_records_it` | `authorizedUser()` | Same. |
| `test_a_missing_reason_is_rejected_at_the_http_layer` | `authorizedUser()` | Authorization now precedes validation, so a role-less actor gets 403 and never reaches the 422. |
| `test_a_reason_the_audit_layer_calls_blank_...` (3 data cases) | `authorizedUser()` | Same. |
| `test_a_missing_reference_is_rejected_at_the_http_layer` | `authorizedUser()` | Same. |
| `test_a_second_refund_for_the_same_reference_via_http_fails_and_leaves_one_row` | `authorizedUser()` | First POST must succeed for the duplicate to be exercised. |
| `test_recording_via_http_leaves_payment_sessions_untouched` | `authorizedUser()` | Would have passed vacuously on a 403 — the point is that a *successful* reversal leaves `payment_sessions` alone. |
| `test_recording_without_a_fresh_authentication_redirects_to_the_challenge_...` | role granted, `actor_sessions` row still deliberately absent | Would still have passed without a role, but ambiguously: the redirect could have been either gate. Granting the role makes it unambiguously the middleware. |
| `test_an_invalid_reversal_type_segment_is_rejected_by_the_route_itself` | now uses `freshlyAuthenticatedUser()` (no role) | Route constraint means no route matches, so the controller is never reached. Using an *unauthorized* actor additionally pins that the 404 is the router's, not a leak from inside the controller. |
| `test_an_unauthenticated_request_is_refused_by_the_auth_guard` | unchanged | No user at all; `auth` fires first. |
| `test_the_route_is_registered_with_the_reauthentication_middleware` | unchanged | Route-table introspection only. |

The `ActorSession::query()->create([...])` block, previously copy-pasted into 9 methods, is now in `freshlyAuthenticatedUser()`. That is the bulk of the diff's line count.

### `VerifyManualPaymentRouteTest.php` (9 pre-existing methods)

Identical pattern: `test_verification_with_a_fresh_authentication_actually_verifies`, `test_a_missing_reason_...`, `test_a_reason_the_audit_layer_calls_blank_...` (3 data cases), `test_an_invalid_decision_value_...`, and `test_deciding_via_http_leaves_payment_sessions_untouched` all take `authorizedUser()`. `test_verification_without_a_fresh_authentication_...` gets a role but no `actor_sessions` row, for the same disambiguation reason. `test_an_unauthenticated_request_...` and `test_the_route_is_registered_...` unchanged.

## 5. New coverage added

Plan §4 items 1–5, per controller, plus extras:

| Test | Plan item |
| --- | --- |
| `test_an_authenticated_actor_with_no_role_is_refused_and_writes_nothing` | 1 — 403, no `payment_reversals` / undecided `payment_verifications`, **and `ReauthenticationEvent::count() === 0`**, which is what pins D3's ordering. |
| `test_a_real_but_unauthorized_role_is_refused` | 2 — `customer`, a genuine `KNOWN_ROLES` entry, proving an allow-list rather than "holds any role". |
| `test_each_authorized_role_may_record_a_reversal` / `..._may_decide_a_verification` (`#[DataProvider]`) | 3 — `finance` and `restricted_admin` both succeed. |
| `test_the_audit_trail_records_the_real_role_never_the_sentinel` (`#[DataProvider]`) | 4 — asserts the `AuditEvent.actor_role` **and** the `ReauthenticationEvent.actor_role` equal the granted role, and that zero rows in either table carry `authenticated_actor`. |
| `test_an_unknown_verification_id_is_403_not_404_for_an_unauthorized_actor` | 5 — verification controller only; asserts both a fabricated UUID and a real id return the *same* status, and that it is 403. |
| `test_a_revoked_role_grant_no_longer_authorizes` | extra — `revoked_at` is honoured end to end. |
| `test_an_actor_holding_both_roles_is_recorded_under_the_more_privileged_one` | extra — `restricted_admin` wins, per `ActorRole::KNOWN_ROLES` precedence order. |
| `test_an_unauthorized_actor_gets_403_not_a_validation_error` | extra — empty body; a well-formed request would be 422, so this pins authorize-before-validate. |

Unit tests (`FinanceOrRestrictedAdminPaymentAuthorizerTest`, 15 tests): implements the contract; each authorized role returned by name; null identity refused even when roles are present; `ActorContext::guest()` refused; empty roles refused; all six other `KNOWN_ROLES` entries refused via `#[DataProvider]` (including `admin` — a plain admin is deliberately not a payout/refund role); the `authenticated_actor` sentinel refused; most-privileged role wins regardless of input order; a string `identityReference` accepted; the refusal message names the actor reference and nothing else.

## 6. Mutation test — MANDATORY, both directions observed

The mutation applied to each controller replaces the authorization block with the pre-fix behaviour, which is the honest inverse of the change (simply deleting the block leaves `$actorRole` undefined and would fail to compile, proving nothing about the check):

```php
-        try {
-            $actorRole = app(PaymentActionAuthorizer::class)->authorize($actorContext);
-        } catch (PaymentActionNotAuthorisedException) {
-            abort(403);
-        }
+        // MUTATION TEST — authorization removed, pre-fix behaviour restored.
+        $actorRole = 'authenticated_actor';
```

### 6.1 `RecordPaymentReversalController` — MUTATED

Command: `php vendor/bin/phpunit tests/Feature/Payment/RecordPaymentReversalRouteTest.php`

Observed: **`Tests: 22, Assertions: 62, Errors: 1, Failures: 6`** — 7 of the new tests died.

```
1) test_an_authenticated_actor_with_no_role_is_refused_and_writes_nothing
Expected response status code [403] but received 302.
2) test_a_real_but_unauthorized_role_is_refused
Expected response status code [403] but received 302.
3) test_a_revoked_role_grant_no_longer_authorizes
Expected response status code [403] but received 302.
4) test_the_audit_trail_records_the_real_role_never_the_sentinel@finance
-'finance'
+'authenticated_actor'
5) test_the_audit_trail_records_the_real_role_never_the_sentinel@restricted_admin
-'restricted_admin'
+'authenticated_actor'
6) test_an_actor_holding_both_roles_is_recorded_under_the_more_privileged_one
-'restricted_admin'
+'authenticated_actor'

There was 1 error:
1) test_an_unauthorized_actor_gets_403_not_a_validation_error
Error: Call to a member function all() on array
```

The 302s are the vulnerability itself: a role-less actor's refund was recorded and they were redirected to the dashboard. The single `Error` is a Laravel `TestResponse` quirk when `assertForbidden()` fails against a 302 that carries no validation-error bag — it is still a genuine, non-passing outcome for that test.

### 6.2 `RecordPaymentReversalController` — RESTORED

Same command. Observed: **`OK (22 tests, 74 assertions)`**.

### 6.3 `VerifyManualPaymentController` — MUTATED

Command: `php vendor/bin/phpunit tests/Feature/Payment/VerifyManualPaymentRouteTest.php`

Observed: **`Tests: 20, Assertions: 53, Errors: 1, Failures: 7`** — 8 of the new tests died.

```
1) test_an_authenticated_actor_with_no_role_is_refused_and_writes_nothing
Expected response status code [403] but received 302.
2) test_a_real_but_unauthorized_role_is_refused
Expected response status code [403] but received 302.
3) test_a_revoked_role_grant_no_longer_authorizes
Expected response status code [403] but received 302.
4) test_an_unknown_verification_id_is_403_not_404_for_an_unauthorized_actor
Expected response status code [403] but received 404.
5) test_the_audit_trail_records_the_real_role_never_the_sentinel@finance
-'finance'
+'authenticated_actor'
6) test_the_audit_trail_records_the_real_role_never_the_sentinel@restricted_admin
-'restricted_admin'
+'authenticated_actor'
7) test_an_actor_holding_both_roles_is_recorded_under_the_more_privileged_one
-'restricted_admin'
+'authenticated_actor'

There was 1 error:
1) test_an_unauthorized_actor_gets_403_not_a_validation_error
Error: Call to a member function all() on array
```

Failure 4 is the most valuable observation in this report: with authorization removed, a fabricated verification id returns **404** while a real one returns 302/403 — the existence oracle plan D3 predicted, reproduced live. With the fix in place both return 403.

### 6.4 `VerifyManualPaymentController` — RESTORED

Observed, whole payment module: **`OK (218 tests, 928 assertions)`**.

Both controller files were restored from byte-for-byte backups taken before mutation; `git diff --stat` after restore matched the pre-mutation state.

## 7. Verification executed

| Check | Command | Result |
| --- | --- | --- |
| Payment module suite | `php vendor/bin/phpunit tests/Feature/Payment/ tests/Unit/Platform/Payment/` | **PASS** — `OK (218 tests, 928 assertions)` |
| Reversal route tests | `php vendor/bin/phpunit tests/Feature/Payment/RecordPaymentReversalRouteTest.php` | **PASS** — `OK (22 tests, 74 assertions)` |
| Verification route tests | `php vendor/bin/phpunit tests/Feature/Payment/VerifyManualPaymentRouteTest.php` | **PASS** — `OK (20 tests, 67 assertions)` |
| Authorizer unit + binding tests | `php vendor/bin/phpunit tests/Unit/Platform/Payment/... tests/Feature/Payment/PaymentAuthorizerBindingTest.php` | **PASS** — `OK (19 tests, 21 assertions)` |
| Style | `php vendor/bin/pint --test app/Platform/Payment tests/Feature/Payment tests/Unit/Platform/Payment` | **PASS** |
| Static analysis | `php -d memory_limit=512M vendor/bin/phpstan analyse app/Platform/Payment tests/Feature/Payment tests/Unit/Platform/Payment` | **PASS** — `[OK] No errors` |
| Doc gates | `bash ci/verify-docs.sh` | **PASS** — `RESULT: ALL DOC GATES PASS` |

## 8. NOT TESTED / BLOCKED — read this before approving

1. **PostgreSQL 18 — NOT TESTED.** `phpunit.xml` pins `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, so every run above was SQLite. Plan §5 requires a real PostgreSQL 18 run before done, because this changes authorization and audit behaviour. Not executed here: the host is under memory pressure with four other lanes active, and the instruction was scoped runs only. **This is an outstanding plan requirement, not a waived one.**
2. **Full test suite — NOT TESTED.** Only `tests/Feature/Payment/`, `tests/Unit/Platform/Payment/`, and the two new files were run, per the memory-pressure instruction. Mitigating evidence, not proof: a repo-wide grep for `admin.payments.reversals.record`, `admin.payments.manual-verifications.verify`, `RecordPaymentReversalController`, `VerifyManualPaymentController`, and `PaymentServiceProvider` across `app/`, `tests/`, `routes/`, `bootstrap/` returns no test file outside `tests/Feature/Payment/`. No file outside `app/Platform/Payment/` was modified.
3. **No live HTTP exercise.** No login flow exists in this repo, so the change was never driven through a real browser session. Same standing gap the pre-existing tests already document.
4. **The residual risk from plan D2 is still open.** `ReversalService::record()` and `VerifyManualPayment::verify()` still accept a caller-supplied `actorRole` that lands unchecked in the audit row. Not exploitable today (each has exactly one production caller, now authorized), but a second caller reopens it. Recorded in the authorizer's class doc block; deliberately not fixed here.

## 9. Deviations from the brief, and one correction applied

1. **The instructed doc-block sentence was factually wrong and was NOT written.** The brief asked the authorizer's class doc block to record "that it fails closed today because `ActorContext::$roles` is `[]` under the current local identity adapter." That is stale. `LocalUsersTableIdentityAccessAdapter` resolves real, live grants through `Roles\ActorRoleReader` (commit `77532bc`, `feat(identity-seam): resolve real roles and scopes into ActorContext`, an ancestor of this branch), and its own doc block says so explicitly. I detected this while reading the adapter and wrote the doc block accurately; lane `lane-payment-auth-hotfix` independently sent the same correction mid-task, which I applied to tighten the wording further. The authorizer now states it is live enforcement, names both stale sources (plan §6 and the four `FinancialLedger` authorizers) as deliberately not repeated, and keeps the still-true framing that an empty role list means "holds no grants today", never "no role required".

   **Plan §6 is therefore stale on this point** and should be corrected by whoever owns the plan doc.

2. **The four `FinancialLedger` authorizers still carry the same stale claim** in their own doc blocks. Left untouched — out of scope for this hotfix, and another lane's to fix.

3. **Authorization placed above the `reversalType` `match`** in `RecordPaymentReversalController`, not below it. The `match`'s `default` arm is unreachable behind the route's `->where('reversalType', 'refund|chargeback')` constraint, so this is not load-bearing; putting authorization unconditionally first is simply the ordering that cannot be got wrong later.

4. **Two helper methods and one extra test file beyond the brief's list.** `PaymentAuthorizerBindingTest` mirrors `FinancialLedgerBindingsTest` — for an authorization policy, an unbound interface is how a controller silently stops checking, and that deserves its own regression test.

5. **`abort(403)` after a `try` block assigning `$actorRole`** follows `FinanceExportController:51-59`'s existing shape exactly. PHPStan accepts it (`abort()` is `never`-returning).
