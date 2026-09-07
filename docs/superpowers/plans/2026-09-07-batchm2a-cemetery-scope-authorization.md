# Batch M2a — Cemetery-scope authorization fixes (AUTHZ-03, AUTHZ-04, AUTHZ-05)

Phase 3, Batch M2a of `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`. Three related
Medium audit findings, all about cemetery-level access control fail-open bugs and doc drift.
**This is an authorization-affecting change and requires human review per `AGENTS.md`
§Infrastructure-agent execution** before merge.

## AUTHZ-03 — cemetery scoping fails open on four Admin resources

`getEloquentQuery()` on four `app/Filament/Admin/Resources/*` resources read the actor's
`scope_assignments` cemetery grants and applied a `whereIn` filter ONLY when the actor held at
least one grant. An actor who cleared the resource's `canAccess()`/`MasterDataAdminAuthorizerContract`
gate but held ZERO cemetery grants fell through to an unfiltered query and saw every cemetery's
rows — the opposite of the intended "no grant = no rows" default the vendor/operator
`ScopesToCurrent{Vendor,Cemetery}` traits already enforce.

Affected resources (all four in the "cemetery-scoped Admin resource" family):

- `app/Filament/Admin/Resources/VisitationBookings/VisitationBookingsResource.php`
- `app/Filament/Admin/Resources/MemorialProfiles/MemorialProfileResource.php`
- `app/Filament/Admin/Resources/ModerationCases/ModerationCaseResource.php`
- `app/Filament/Admin/Resources/CemeteryVisitationPolicies/CemeteryVisitationPolicyResource.php`
  (the fourth resource in the family, found by grepping for the same
  `ScopeAssignmentReader::grantedEntityIds` query-scoping pattern)

**Fix chosen: option (a).** `admin`/`restricted_admin` get an explicit branch — checked BEFORE the
grant read — that returns the unconstrained query, because "platform-wide" is a role fact for
these two roles specifically (matches every other doc block in this codebase describing them).
Every other actor, including one with zero grants, now gets the query closed
(`whereIn('cemetery_id', [])` or the `whereHas` equivalent for the two resources that reach
`cemetery_id` indirectly) rather than left unfiltered. This is applied identically across all four
resources — no mixing with the "close for everyone with zero grants, including admin" alternative.

Each resource gets a regression test: an actor whose only cemetery grant is **revoked** (a
`ScopeAssignment` row created then deleted, or never granted at all for a role that is not
`admin`/`restricted_admin`) must see zero rows, not every row — the negative criterion the finding
specifically calls out (not just "an actor who never had a grant").

## AUTHZ-04 — inconsistent enforcement between the renewal external-payment CREATE and SETTLE paths

`MarkExternalRenewal` (CREATE — opens a brand-new `source=EXTERNAL` renewal row) has always called
`RenewalMarkingPolicy::allows($actor, $grave)` — role (`admin` only, Ruling B 12 Aug 2026) AND a
`ScopeGrantLevel::PRIVILEGED` cemetery grant — before its `Audit::wrap()` mutation runs.

`MarkRenewalPaidExternally` (SETTLE — transitions an existing `MENUNGGU_PEMBAYARAN` row to
`DIBAYAR`) trusted whatever `$actorRef`/`$actorRole` strings its caller passed in and never
checked `RenewalMarkingPolicy` itself. Its only real caller,
`RecordExternalRenewalPaymentAction` (the Filament View-page header action), gated the button and
the pre-mutation re-check through `OrderTransitionAuthorizerContract::authorizeTransition()`,
which is **role-only** for the `record_external_renewal_payment` money transition
(`admin` unconditionally, `finance` for any money transition — no cemetery-scope check at all).

Net effect before this fix: a `finance` actor with **zero** cemetery grant could settle (mark
paid) any cemetery's renewal, while that same actor attempting to CREATE an external renewal from
scratch would be correctly refused by the scoped policy. Not a documented intentional split
anywhere in the repo — an inconsistency.

**Fix:** moved the check into `MarkRenewalPaidExternally::__invoke()`, mirroring
`MarkExternalRenewal` exactly — the action now resolves `ActorContext` via `ActorContextResolver`,
calls `RenewalMarkingPolicy::allows($actor, $renewal->graveRecord)` before `Audit::wrap()` runs,
and uses the role the policy matched for the audit row instead of a caller-supplied string. The
Filament `->authorize()` callback on `RecordExternalRenewalPaymentAction` is unchanged and is now
explicitly documented as the button/mount-level gate only, not the source of truth.

### Decision point requiring human sign-off (flagged, not silently resolved)

`RenewalMarkingPolicy::PERMITTED_ROLES` is `admin` only. Moving the SETTLE path onto this same
policy means a `finance` actor who could previously settle a renewal (via the role-only
`OrderTransitionAuthorizer` money-transition branch) **can no longer complete this action at all**,
even holding a cemetery grant — there is no such branch in `RenewalMarkingPolicy` to grant it
through. `RenewalOrderResourceTest::test_finance_can_run_record_external_payment_action` still
passes unchanged (it only asserts the button-level `isAuthorized()`, which is untouched), but a
`finance` actor clicking the button will now get "Gagal mencatat pembayaran" from the
`AuthorizationException` `RenewalMarkingPolicy` throws.

This PR implements the CONSISTENT (scoped, `admin`-only) version as the safer default rather than
silently admitting `finance` to a cemetery-scope bypass. **A human should confirm** whether
`finance` actually needs a cemetery-agnostic (or cemetery-scoped) carve-out for this specific
settle action, and if so have that ruling recorded explicitly in
`docs/security/rbac-matrix.md` (added as a `RenewalMarkingPolicy::PERMITTED_ROLES` entry, not a
separate ad hoc check) — the same way Ruling B (12 Aug 2026, admin-only for the CREATE path) is
already recorded.

A side effect of this fix: `App\Support\ExampleData\RenewalExampleData` (demo-data seeding, no
authenticated actor exists at all when it runs from `DemoDataSeedCommand`) can no longer call
`MarkRenewalPaidExternally` directly — the settle mutation is now inlined into a private
`RenewalExampleData::settleExternallyForDemo()` helper that performs the identical write and audit
call `MarkRenewalPaidExternally` does, minus the policy check, at the same "trusted demo actor
string, no real authorization" level `OpenRenewal`/`ExpireRenewal` calls already operate at in that
same file.

## AUTHZ-05 — `docs/security/rbac-matrix.md` doc drift (pure doc fix, done last)

Rewrote the "how query-level scope is enforced" section (previously: "Query-level scope is
mandatory, and is enforced separately from roles via `scope_assignments`
(`ScopeAssignmentGlobalScope`)... shipped authorizers require a role and a scope grant. One narrow
exception: `FinanceOrRestrictedAdminPaymentAuthorizer`.") to state what actually ships:

- `ScopeAssignmentGlobalScope` is built but attached to no real domain model — its own doc block
  says so; the trait's only consumer is a test fixture.
- Query-level scope is actually enforced per-surface: `ScopesToCurrentVendor` (vendor panel,
  guarded by a structural test), `ScopesToCurrentCemetery` (operator panel), and the four
  AUTHZ-03 Admin resources' own `getEloquentQuery()` overrides (described post-fix, which is why
  this doc change is the last commit in the PR, after AUTHZ-03 lands).
- A full list of role-only (no scope check) authorizers with justification for each: found by
  grepping every `*Authorizer` class for a `ScopeAssignment`/scope check and reading its own doc
  block — `MasterDataAdminAuthorizer`, `RoleBasedAuditReadAuthorizer`, `RoleBasedFaqAuthorizer`,
  `CertificateIssuerAuthorizer`, `FinanceOrRestrictedAdminPaymentAuthorizer`, and
  `OrderTransitionAuthorizer`'s money-transition branch (with the AUTHZ-04 cross-reference).

## Testing

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (bumped memory limit as needed)
- `bash ci/verify-docs.sh`
- Full `php artisan test` (or targeted Filament/Renewal suites) inside the CI-parity Docker image
  against real PostgreSQL/Redis containers (prefix `m2a-`), per this batch's dispatch instructions.

All results are reported honestly in the PR description — `BLOCKED`/`NOT TESTED` used explicitly
for anything not actually executed, never a fabricated `PASS`.
