# Plan — Batch M5c: dead code and static analysis (ARCH-11, ARCH-12, STAT-02, STAT-04)

**Branch:** `fix/batchm5c-dead-code-and-static-analysis`
**Parent:** `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` (Phase 3)
**Date:** 7 Sep 2026

## Scope

Four independent audit findings, none touching the same production code
path, bundled into one batch per the audit remediation program's grouping:

- **ARCH-11** — Subscriptions Filament panel: `PauseSubscriptionAction`/
  `CancelSubscriptionAction` always refuse via a hardcoded notification
  regardless of any real check, while their doc blocks and the real Domain
  Actions (`PauseSubscription`/`CancelSubscription`) falsely claim an AC7
  gate exists. An unregistered `CreateSubscriptionAction` is also dead code.
- **ARCH-12** — `phpstan.neon` pinned at level 2 with a stale "empty
  scaffold" justification; `tests/` excluded from `paths` entirely (706 of
  1,740 PHP files got zero static analysis).
- **STAT-02** — `DocumentVaultConfigurationTest`'s two staging fail-closed
  tests only override `getenv()`/`$_ENV`, missing `$_SERVER` — a real gap in
  what the "fail closed" guarantee actually proves, and a $_SERVER leak
  into later tests in the same process.
- **STAT-04** — `HasScopeAssignments`/`ScopeAssignmentGlobalScope` (the
  declarative record-scoping mechanism) have zero production adopters; the
  spec (`platform-identity-and-access/tasks.md`) falsely claims this scopes
  "every domain query."

## Decisions

### ARCH-11 — Option (b): disable the Pause/Cancel controls, delete the unwired Create action

**Chosen: delete the working-looking-but-always-refusing behavior, keep a
plainly disabled control as the only UI.**

Why not option (a) (wire a real AC7 gate): AC7
(`recurring-care-subscriptions/requirements.md` #7) requires "THE SYSTEM
SHALL NOT apply cancellation, pause, ... behavior until the corresponding
policy is explicitly configured." No configuration surface for a
pause/cancellation policy exists anywhere in this codebase — no model,
migration, config key, or admin UI for one. Building that surface (what a
"policy" even contains — a notice period? An eligibility window? Who can
configure it?) is a real product/config decision this batch has no mandate
to invent, and doing so exceeds what a dead-code/static-analysis batch
should absorb. Confirmed via `.kiro/specs/` search: no other spec defines
this policy either.

Fix applied: `PauseSubscriptionAction`/`CancelSubscriptionAction` still
render (correct empty/visible states per subscription status), but now
call `->disabled()->tooltip(...)` instead of `->requiresConfirmation()`
+ `->action(...)`. A disabled Filament action never dispatches its handler,
so there is no "always refuses" behavior left to be dishonest about — the
control visibly communicates "not available yet," not "click me and watch
me pretend to work." The stale `AC7: throws if pause/cancel policy is not
configured` claims in the real Domain Actions
(`App\Domain\CareSubscription\Actions\PauseSubscription`/
`CancelSubscription`) are corrected to state plainly that no such check is
implemented there — AC7 is enforced today only by the UI keeping the
control disabled, and any future direct caller of these Domain Actions is
responsible for re-verifying AC7 itself.

`CreateSubscriptionAction` (unregistered, so also dead) is deleted rather
than wired up. Its own doc block already flagged a real, unresolved
correctness gap: the create form has no customer-selection field, so
`run()` assigns the **admin's own identity** as `customer_id`. Registering
this action now would ship a financially-adjacent admin control that
silently attaches subscriptions to the wrong customer — a genuine bug, not
a cosmetic one — to fix "dead code." The real fix (a searchable customer
`Select`) is a product decision (which customer picker, what search
fields) this batch does not have the mandate to invent either. Deleting
the unregistered action removes the dead code without shipping a new bug.

Typo fix: "Pengjedaan" → "Penjedaan" (root `jeda`, peN- prefix nasalizes to
"pen-" before /j/, not "peng-").

Test changes: `CarePlansResourceTest`'s two
`callAction(...)->assertNotified('Belum dapat diaktifkan')` tests are
replaced with `assertActionVisible(...)->assertActionDisabled(...)`,
matching the new behavior — there is no notification to fire once the
handler cannot be dispatched.

### ARCH-12 — ratchet to level 5 with a baseline; fix only the two named "free" issues for real

- Rewrote the stale "empty scaffold" comment in `phpstan.neon` to state the
  real current situation and the plan going forward (see the file itself).
- Added `tests` to `paths`.
- Generated `phpstan-baseline.neon` via
  `vendor/bin/phpstan analyse --generate-baseline` at level 5 across
  `app` + `tests` (359 pre-existing violations baselined — not fixed in
  this PR, per the finding's own instruction that this is "a much bigger
  undertaking").
- Set `level: 5` and included the baseline.
- Fixed the two categories of "free" issues named in the finding, for real,
  not by baselining them:
  - **Five dead `catch` blocks** — all five turned out to be a `Threable`
    union member redundant with an already-present `\Throwable`/`Throwable`
    catch in the SAME statement (`catch (RuntimeException|Throwable $e)`,
    `catch (AuthorizationException|\Throwable $exception)`), across
    `BuildBrandAssets`, `GenerateFilamentPaletteCommand`,
    `VerifyFilamentPaletteCommand`, and `MarkExternalRenewalAction`. Fixed
    by dropping the redundant, more-specific member and keeping the
    `Throwable` catch (behavior is identical — `Throwable` already caught
    everything the redundant member could).
  - The fifth ("`OverflowException` is never thrown",
    `ProviderStatementCsvParser.php:140`) is **not** the same
    pattern — `Money::fromDecimal()` genuinely does `throw new
    OverflowException(...)` on overflow, and removing that catch would
    have let a real overflow condition escape uncaught as a regression.
    PHPStan's `catch.neverThrown` check only knows a user method's throw
    contract from its `@throws` docblock, and `fromDecimal()`'s docblock
    was missing the `@throws OverflowException` tag (it only declared
    `@throws InvalidArgumentException`). Fixed by adding the missing tag,
    not by touching the catch — verified this makes the specific
    `catch.neverThrown` finding disappear with no other change.
  - **One missing `match` arm, a genuine bug**:
    `App\Platform\Payment\Actions\OpenPaymentSession::__invoke()`'s
    `match ($command->orderType)` had no arm for `OrderType::CareSubscription`
    — a real command with that order type would have thrown PHP's own
    `UnhandledMatchError` instead of the documented, catchable
    `PaymentSessionOrderTypeNotSupportedException` `OrderType`'s own doc
    block promises exists for exactly this "not implemented yet" case.
    Fixed by adding the arm:
    `OrderType::CareSubscription => throw PaymentSessionOrderTypeNotSupportedException::forOrderType($command->orderType)`.
    Regression test added:
    `OpenPaymentSessionTest::test_a_care_subscription_order_type_is_refused_with_a_domain_exception_not_an_unhandled_match_error`.
  - The three "Match expression does not handle remaining value: string"
    findings (`MarketplaceProductCategory`, `ProductType`,
    `BookingOrderInfolist`) are matches over a plain `string` subject (not
    a backed enum), which PHPStan cannot prove exhaustive by type alone —
    a different, much lower-signal category than the enum-backed
    `OrderType` case above, and not named by the finding. Left in the
    baseline.

### STAT-02 — tried `Illuminate\Support\Env`'s repository, empirically it can't write; fell back to the three-way manual option

Verified `Env::getRepository()` exists in this Laravel version and (via
`vendor/vlucas/phpdotenv`'s `RepositoryBuilder::createWithDefaultAdapters()`)
registers `ServerConstAdapter` ($_SERVER), `EnvConstAdapter` ($_ENV), and
`PutenvAdapter` (getenv/putenv) together into one `AdapterRepository`. But
that repository is built `->immutable()`
(`Illuminate\Support\Env::getRepository()`'s own source), and phpdotenv's
`ImmutableWriter::write()`/`delete()` explicitly refuse to overwrite or
clear a variable that is already "externally defined" — which `APP_ENV`
always is in this test process, since PHPUnit's own `<env name="APP_ENV"
value="testing"/>` sets it as a real environment variable before any test
runs. Confirmed empirically: `$repository->set('APP_ENV', 'staging')`
silently no-ops (returns without error, but `env('APP_ENV')` still reads
`testing`), which made both staging-gate tests fail when first written
against this API — not because the mechanism was wrong, but because
"cleaner" turned out not to mean "works here."

Fell back to the finding's other named option: both staging-gate tests now
capture the prior value across all three of getenv()/`$_ENV`/`$_SERVER`,
override all three together via two small private helpers
(`setAcrossAllThreeEnvSources()`/`clearAcrossAllThreeEnvSources()`), and
restore all three together in a `finally` block
(`restoreAcrossAllThreeEnvSources()`) — three-way consistent by
construction (one call site touches all three), not by remembering to
duplicate the same three lines by hand in every test as the original code
did.

`Env::getRepository()` DOES still help for the new regression test below —
*reading* a value is not subject to the immutable-writer restriction, only
writing/clearing is.

Added a new regression test,
`test_a_value_set_only_via_server_super_global_is_picked_up_by_the_staging_gate`,
that writes `$_SERVER` directly (bypassing `putenv()`/`$_ENV` entirely, the
shape a real FPM pool `env[...]` directive or `fastcgi_param` produces) and
asserts the fail-closed gate still rejects the configuration — proving the
$_SERVER gap this finding described is actually closed, not just
theoretically closed by using a repository that happens to cover it.

### STAT-04 — Option (b): delete the unadopted global-scope mechanism, correct the stale spec claim

**Chosen: delete `HasScopeAssignments`, `ScopeAssignmentGlobalScope`,
`ScopeAssignable`, and their proving test fixtures.**

Why not option (a) (attach to real entity models): `ScopeAssignmentGlobalScope`
is closed-by-default with no admin/bypass escape hatch — an actor with zero
`scope_assignments` rows sees nothing. Confirmed via search: **no**
existing code populates `scope_assignments` for cemetery/vendor/order/
case/grave/business-entity today, and **no** bypass mechanism (e.g. "admins
see everything") exists in `ScopeAssignmentGlobalScope` or
`ScopeAssignmentResolver`. Attaching `HasScopeAssignments` to any of AC5's
named entity models today would make every row of that model instantly
invisible to every actor everywhere — admins included — until every
existing actor's grants were backfilled and every admin/Filament/booking
code path that queries that model was re-audited and re-tested. That is a
data-migration-plus-cross-cutting-regression-audit project, not a change
this batch (already covering three other findings) should absorb; the
finding itself names this exact fallback ("if attaching it now is too
large for this batch, DELETE").

Crucially, this is **not** "the record-scoping mechanism has zero
adopters" — `ScopeAssignmentResolver`/`ScopeAssignmentReader` (the
`scope_assignments`-reading half, which this fix keeps) ARE real,
production-used consumers today, via the OTHER enforcement mechanism
design.md's Enforcement points §3 names ("global scopes **or** explicit
builders"): `DocumentVault\Policies\DocumentAccessPolicy`,
`Notification\RecipientResolver`, and
`Notification\InAppNotificationInboxQuery` all call
`ScopeAssignmentResolver` directly as an explicit, opt-in query constraint.
Only the DECLARATIVE global-scope path
(`HasScopeAssignments`/`ScopeAssignmentGlobalScope`) — the alternative
design.md offered but production code never actually adopted — is dead.

Deleted:
- `app/Platform/IdentityAccess/Scopes/Concerns/HasScopeAssignments.php`
- `app/Platform/IdentityAccess/Scopes/ScopeAssignmentGlobalScope.php`
- `app/Platform/IdentityAccess/Scopes/Contracts/ScopeAssignable.php`
- `tests/Feature/IdentityAccess/Scopes/ScopeAssignmentGlobalScopeTest.php`
- `tests/Fixtures/ScopedTestModel.php`
- `tests/Fixtures/CreatesScopedTestModelTable.php`

(All verified to have zero other references anywhere in `app/`/`tests/`
before deletion — every other file that mentions
`ScopeAssignmentGlobalScope`/`HasScopeAssignments` does so only in prose
inside a doc block, never a `use`/`implements`/`extends` statement.)

Corrected the stale spec claim in
`.kiro/specs/platform-identity-and-access/tasks.md` (Implementation status
bullet "Scope assignment + mandatory query scopes") that asserted
`HasScopeAssignments` provided "the cemetery/vendor/order scoping every
domain query inherits" — false; no model ever attached it. The bullet now
names the real, adopted consumers and states AC5 is satisfied only where a
consumer explicitly applies the resolver, not as a blanket guarantee.
Also corrected `ScopeAssignmentResolver`'s own doc block, which listed
`ScopeAssignmentGlobalScope` as an "existing consumer" of its unchanged
public API — that class no longer exists.

## Testing

Host PHP is 8.5 via a dev-dependency composer install layered into an
ephemeral container built from
`ghcr.io/andrianm28/makam-app:sha-0319bd77895c` (that tag's baked `vendor/`
is a `--no-dev` production install and lacks pint/phpstan entirely, so a
`composer install` — with dev deps, network access, no `--no-dev` — was run
once against this worktree inside an ephemeral `composer:2` container to
get `vendor/bin/pint`/`vendor/bin/phpstan`; `vendor/` stays gitignored and
nothing from that install is committed). Real `postgres:18` (`m5c-pg`) and
`redis:8.2-alpine` (`m5c-redis`) containers, prefixed `m5c-` per the
parallel-agent convention. `composer.lock` in this worktree includes
Pulse/Sentry deps not yet present in that image tag's own lock (a known,
separately-tracked pending-composer-install gap) — irrelevant to the four
findings in this batch, which touch neither package.

See the verification summary in the final report for actual command output
and pass/fail status per gate — this plan does not restate it to avoid it
going stale independently of the real run.
