# RBAC and Record Scope Matrix — v0.2

| Capability | Customer/Family | Case Manager | Operator | Vendor | Admin | Finance/Issuer/Auditor |
|---|---:|---:|---:|---:|---:|---:|
| Public directory | Yes | Yes | Yes | Yes | Yes | Yes |
| Create At-Need intake | Own | Assist | No | No | Assist | No |
| Manage FuneralCase/tasks | Limited own view | Assigned cases | Assigned input | Assigned work only | Yes | Audit/read subset |
| Confirm availability | No | Record evidence | Assigned cemetery | No | Yes/fallback | No |
| Hold/reserve plot | Request | Assigned action | Assigned authority | No | Privileged | Read/audit |
| Override plot status | No | No | Restricted | No | Privileged only | Audit |
| Quote/open payment | Accept only | Prepare/request | No | No | Authorized | Read/review |
| Restricted documents | Own/purpose | Assigned/purpose | Explicit need only | No default | Authorized | No default |
| Issue/revoke certificate | No | Request | If issuing authority | No | Policy dependent | Dedicated issuer role |
| Memorial edit/publish | Authorized family | No | Policy-dependent | No | Moderation | Audit/privacy |
| Vendor work/evidence | View own outcome | Coordinate | View relevant | Own | Yes | Read |
| Payout/refund, incl. manual payment verification | No | No | No | View own | Restricted | Dedicated finance |
| Mark renewal paid externally (AC10) | No | No | **No** | No | **Privileged** (role + cemetery scope) | No |
| Feature/capability gate | No | No | No | No | Dedicated privileged | Approval/audit |

The canonical role vocabulary is `App\Platform\IdentityAccess\Roles\ActorRole::KNOWN_ROLES`,
and the roles are resolved per request into `ActorContext::$roles`. The columns above are
capability groupings for review, not the role list itself — read the closed list from that
class rather than inferring it from this table, and extend it there.

This replaces the earlier note that exact roles depend on an external K1/K2 identity contract.
That contract was never specified anywhere in this repository, so the roles are now mastered
locally; the `IdentityAccessAdapter` seam remains, so a future K1/K2-backed adapter is still a
binding swap rather than a rewrite.

## How query-level scope is actually enforced (updated, AUTHZ-05 — was wrong before)

There is no working global-scope mechanism enforcing `scope_assignments` grants today.
`ScopeAssignmentGlobalScope` (`App\Platform\IdentityAccess\Scopes\ScopeAssignmentGlobalScope`,
attached via the `HasScopeAssignments` trait, `app/Platform/IdentityAccess/Scopes/Concerns/
HasScopeAssignments.php:50`) is **built but not yet attached to any real domain model** — its own
doc block says so: "No real domain model exists yet to attach this to ... proven here against a
test-only fixture model instead." Grep confirms no `App\Domain\**` model `use`s the trait. Do not
read "scope_assignments grants are enforced" as "a Laravel global scope enforces them" — it is not
wired up that way anywhere in shipped code.

What actually enforces query-level scope, per surface:

- **`/vendor` panel:** every Resource in `App\Filament\Vendor\Resources` uses the
  `App\Filament\Vendor\Concerns\ScopesToCurrentVendor` trait, which overrides
  `Resource::getEloquentQuery()` to `whereIn(vendor_id, grantedVendorIds())` — an actor with no
  vendor grant gets a closed (`whereIn(..., [])`, always-false) query, never an unconstrained one.
  `tests/Feature/Filament/Vendor/VendorPanelScopingTest` fails CI if any Vendor Resource stops
  using the trait — a structural guarantee, not a per-Resource review.
- **`/operator` panel:** `App\Filament\Operator\Concerns\ScopesToCurrentCemetery` does the same
  thing for cemetery grants, same closed-by-default `whereIn` semantics.
- **`/admin` panel's four cemetery-scoped Resources** (`VisitationBookingsResource`,
  `MemorialProfileResource`, `ModerationCaseResource`, `CemeteryVisitationPolicyResource`) each
  override `getEloquentQuery()` directly (no shared trait yet). Post-AUTHZ-03 fix: `admin` and
  `restricted_admin` are checked explicitly as the platform-wide roles and see every cemetery's
  rows; every other actor — including one who cleared a resource's `canAccess()` gate but holds
  zero cemetery grants — gets a closed query (`whereIn('cemetery_id', [])`, or the equivalent
  `whereHas(..., whereIn('cemetery_id', []))` for the two Resources that reach `cemetery_id`
  indirectly through a relation). Before that fix, a zero-grant actor of ANY role saw every
  cemetery's records — the opposite of the intended default.

Because no model-level global scope is attached, a query-scoped surface's enforcement lives
entirely in that surface's own `getEloquentQuery()` (or equivalent) override — there is currently
no defense-in-depth from a model-level fallback. A new cemetery- or vendor-scoped Resource must
apply one of the two traits above (or replicate their closed-by-default shape exactly, as the four
Resources above do) itself; nothing else will do it for it.

## Role-only authorizers (no scope grant checked) — the honest list

The general rule is: **record-scoped authorizers require a role AND a scope grant; platform-wide
back-office authorizers are role-only.** "Platform-wide" here means the underlying data has no
scopeable cemetery/vendor/business-entity/case key at all, or the one candidate key is unsafe to
trust (attacker-controlled), not merely that nobody has gotten around to adding a scope check yet.
Every role-only authorizer shipped today, and why:

- `App\Platform\IdentityAccess\MasterData\MasterDataAdminAuthorizer` — master-data resources
  (grave plots, cemetery visitation policy config, etc.) are platform-wide; its own doc block:
  "there is no record scope to check, unlike the entity-scoped financial authorizers."
- `App\Platform\Audit\RoleBasedAuditReadAuthorizer` — audit-log read access; its own doc block
  explains why ("Why this is role-only, with no `scope_assignments` grant check").
- `App\Domain\Faq\Authorization\RoleBasedFaqAuthorizer` — FAQ content is public, platform-wide
  editorial content with no per-record owner to scope against.
- `App\Domain\AgreementCertificate\CertificateIssuerAuthorizer` — the AC4 issuer gate
  (`assertCanIssue()`) checks `admin`/`restricted_admin` only; certificate issuance authority is
  not modeled as cemetery- or vendor-scoped.
- `App\Platform\Payment\FinanceOrRestrictedAdminPaymentAuthorizer` — role-only because
  `payment_reversals` and `payment_verifications` carry no column in the `scope_assignments
  .entity_id` value space, and their one candidate column, `reference`, is caller-supplied free
  text an attacker could forge to match their own grant — see that class's doc block.
- `App\Domain\OrderWorkflow\Authorization\OrderTransitionAuthorizer` — role-only FOR its
  `MONEY_TRANSITIONS` set (`admin` unconditionally, `finance` for any money transition, no
  cemetery check) and for `restricted_admin`/`operator` on non-money transitions; it is the ONE
  branch in this authorizer (the `cemetery_operator` branch) that is scope-checked, against the
  caller-supplied `$cemeteryId`. AUTHZ-04 found that `record_external_renewal_payment` relied on
  this role-only money-transition branch as its only enforcement on the SETTLE path, while the
  sibling CREATE path (`MarkExternalRenewal`/`RenewalMarkingPolicy`) always required a cemetery
  grant too — now fixed by moving the settle path's real authorization into
  `MarkRenewalPaidExternally::__invoke()` via `RenewalMarkingPolicy` (role + cemetery-scope grant,
  `admin` only today). Whether `finance` should get a documented, cemetery-agnostic carve-out for
  that settle action instead is an open decision for a human to make — see that batch's PR.

Every other shipped authorizer not listed above (the vendor/business-entity finance authorizers —
`FinanceReconciliationAuthorizer`, `FinanceVendorPayableAuthorizer`, `FinanceLedgerReadAuthorizer`,
`FinanceOrRestrictedAdminPayoutAuthorizer` — and the four AUTHZ-03 Admin Resources' `canAccess()`
gates plus their `getEloquentQuery()` scoping above) checks a role AND a `scope_assignments` grant
at the appropriate entity type and grant level. The general rule holds; the list above is the
closed set of exceptions, not an example of a wider pattern.

The "Payout/refund, incl. manual payment verification" row covers BOTH admin money-attestation
actions by an explicit ruling of 12 Aug 2026: recording a reversal and verifying that a payment
was received are the same class of "did money move" judgement in opposite directions, so they sit
at the same authority. It is the row this authorizer's `finance` / `restricted_admin` pair is
taken from; a plain `admin` is deliberately not on it.
