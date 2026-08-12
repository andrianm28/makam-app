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

Query-level scope is mandatory, and is enforced separately from roles via `scope_assignments`
(`ScopeAssignmentGlobalScope`). A role never by itself grants access to a record: the shipped
authorizers require a role **and** a scope grant.
One narrow, deliberate exception: `Payment\FinanceOrRestrictedAdminPaymentAuthorizer` is
role-only, because `payment_reversals` and `payment_verifications` carry no column in the
`scope_assignments.entity_id` value space and their one candidate column, `reference`, is
caller-supplied free text that an attacker could forge to match their own grant — see that
class's doc block for the full argument. The general rule above is unchanged and still governs
every record that has a scopeable key.

The "Payout/refund, incl. manual payment verification" row covers BOTH admin money-attestation
actions by an explicit ruling of 12 Aug 2026: recording a reversal and verifying that a payment
was received are the same class of "did money move" judgement in opposite directions, so they sit
at the same authority. It is the row this authorizer's `finance` / `restricted_admin` pair is
taken from; a plain `admin` is deliberately not on it.
