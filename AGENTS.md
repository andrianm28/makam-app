# Makam.co.id Project Instructions — v0.7

## Source precedence

1. RKS K23–K35.
2. Stakeholder MVP expectation in `docs/product/mvp-scope.md`.
3. Approved ADR and feature specs.
4. Benchmark extensions only when explicitly approved.

Never remove a stakeholder MVP item merely because an external gate is closed. Implement the documented fallback.

## Architecture

- Use Laravel modular monolith unless superseded by ADR.
- Keep domain logic outside controllers, Livewire components, and Filament Resources.
- Use Actions/Services, events, queues, policies, query scopes, and adapters.
- Public UX is mobile-first and follows `information-architecture.md`.


## Pinned technology and dependencies

- Follow `docs/architecture/technology-baseline.md`.
- Application baseline: PHP 8.5, Laravel 13, Livewire 4, Filament 5, PostgreSQL 18, Redis 8.2, Node 24 LTS.
- Production OS baseline: Ubuntu 24.04 LTS or managed equivalent.
- Combined development/staging host: Ubuntu 22.04 LTS, 2 vCPU/4 GB, with containerized application/database/cache versions.
- Commit and respect lockfiles; do not run unconstrained dependency updates in production builds.
- Do not introduce Octane, Kubernetes, Redis Cluster, OpenSearch, GraphQL, or separate SPA without an approved ADR and measured need.

## Queue and event reliability

- Use the queue names and priorities in `queue-and-outbox.md`.
- Critical domain events are inserted into the transactional outbox in the same database transaction as state mutation.
- Consumers are idempotent; queue delivery is at-least-once.
- Imports/reports/media must not starve critical or urgent queues.

## Database, migrations, and delivery

- Production uses managed PostgreSQL with backup/PITR and regular restore tests.
- Use pooled connection for application traffic and direct connection for migrations/maintenance when applicable.
- Migrations follow expand/contract; do not rely on destructive production `down()` migrations for rollback.
- CI/CD builds immutable artifacts from lockfiles and runs static analysis, tests, OpenAPI validation, frontend build, and security audit.

## Authentication and uploads

- Use same-origin session auth for MVP, with password-based recent re-authentication (`App\Http\Middleware\RequireRecentAuthentication`) required for financial, gate, bank-detail, certificate, plot-override, and bulk-export actions. TOTP MFA was built, then removed entirely — see `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note.
- Every untrusted file enters private quarantine and cannot be used/downloaded before validation and malware scan acceptance.

## Observability and performance

- Preserve trace/request IDs across request, outbox, queue, provider, and notification flows.
- Never place restricted data in logs, Pulse, Horizon tags, or error trackers.
- Meet `performance-and-capacity.md` and release evidence requirements before production activation.



## Combined development/staging constraints

- Follow `docs/operations/dev-staging-environment.md` and ADR-0027.
- Never copy production credentials or unsanitized production data to the combined host.
- Preserve separate APP keys, database users, Redis/Horizon prefixes, queues, cookies, storage, and provider sandboxes.
- Do not add permanent local MinIO, always-on ClamAV, extra continuous workers, or on-host build tooling without capacity review.
- Development and batch workers run on demand; staging normal Horizon pool is capped at two processes.
- A passing test on the 2/4 host is not by itself production-capacity evidence.


## Infrastructure-agent execution

- Use `docs/operations/ai-agent-dev-stg-setup-prompt.md` for combined dev+staging provisioning and developer-tool setup.
- Provide only non-secret variables from `ai-agent-dev-stg-setup-variables.env.example`; secrets must use protected injection or secret management.
- Follow `ai-agent-dev-stg-execution-checklist.md` before granting SSH, DNS, firewall, provider, or destructive database permissions.
- Inspect first, preserve a Git diff and rollback path, and update existing canonical files instead of creating conflicting alternatives.
- Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly.
- Pause for SSH lockout risk, destructive database/volume changes, DNS ownership ambiguity, missing provider secrets, or any request involving production data/credentials.
- AI agents may prepare migrations and deployment changes but human review is mandatory before security, authorization, financial, privacy, destructive migration, DNS, firewall, or production-affecting changes.

## Mandatory MVP UX

- Homepage has exactly these four primary services: Pemesanan Makam, Layanan Pemakaman, Perpanjangan Makam, FAQ.
- Booking exposes Steps 1–9 exactly as documented.
- Launch locations include Jakarta, Bogor, Depok, Tangerang, and Bekasi.
- Use canonical service, marketplace, and FAQ catalogs; do not invent alternate labels without product change approval.
- Google Maps/navigation link must be generated from approved URL/coordinate data.
- Every transactional screen has loading, empty, error, pending, success, and support states.
- Draft autosave/resume is mandatory.

## Domain and financial invariants

- Never create payment before valid confirmation/reservation, accepted quote, and authorized opening.
- Never mark paid from browser return URL.
- Closed online-payment gate uses manual fallback in Step 8.
- Webhooks are durable, signed, merchant-scoped, amount-checked, replay-protected, and idempotent.
- At-Need/Urgent creates or uses a FuneralCase.
- Paid Pre-Need is impossible while legal gate is closed; register interest instead.
- Package/class confirmation is default; specific plot requires authoritative inventory and atomic reservation.
- Operator silence preserves admin/manual fallback.
- Service payment and fulfillment are separate states.
- One reminder per grave/window, one invoice per cycle, and one renewal settlement per period.

## Authorization and files

- Policies and query-level scope are mandatory.
- Scope by cemetery, vendor, order, case, grave, and business entity.
- Store KTP, KK, death documents, payment proof, and work evidence privately.
- Signed deceased-document URLs expire within five minutes.
- Audit every restricted file access.
- Email/WhatsApp never contains private attachments.

## Notifications

- Implement `docs/contracts/notification-matrix.md`.
- Channel failure never changes business state.
- Do not claim WhatsApp/email delivery without delivery state.
- Always create relevant admin/operator/vendor in-app records using record scope.

## Marketplace

- MVP is one vendor per checkout.
- Support the exact catalog in `marketplace-catalog.md`.
- Vendor panel includes products, orders, calendar, evidence, transaction history, and payout status.
- Paid does not mean completed.
- Do not implement land rights listing through generic marketplace code.

## FAQ

- Seed and preserve six required categories.
- Draft/unpublished articles must never be public.
- FAQ claims about payment, Urgent, or service hours must reflect active approved configuration.

## Testing

- Every traceability item marked `Covered` needs test evidence.
- Browser tests cover all four homepage routes, nine booking steps, marketplace flow, renewal flow, FAQ, admin, and vendor.
- Test online and fallback payment modes.
- Test notification recipient scope and channel failure.
- Test mobile/responsive and accessibility behavior.
- Every bug fix requires regression test.

## Documentation

- Update spec, traceability, screen inventory, API contract, and test when behavior changes.
- `tasks.md` is planning only; issue tracker owns progress.
- Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations.

## Development methodology

- New feature work and retrofits of already-shipped modules both follow Superpowers SDD: `brainstorming` -> `writing-plans` -> `subagent-driven-development` -> `finishing-a-development-branch`. Skip stages only for a change with no design decisions in it (typo, doc fix).
- Every plan is committed at `docs/superpowers/plans/<date>-<slug>.md` before implementation starts.
- Implementation happens in an isolated git worktree under `.worktrees/`, never on the working checkout directly.
- Execution state (task briefs, task reports, review diffs) is ledgered at `.superpowers/sdd/<plan-slug>/progress.md` inside the worktree — git-ignored, ephemeral, scoped to one execution session. It answers "how did this pass go," not "what does the spec require" — `tasks.md` stays the durable answer to that question.
- Review is two-tier: each task is reviewed against its brief before the next task starts, then the whole branch is reviewed once as a unit before merge. Findings are triaged Critical/Important/Minor; Critical and Important get one bounded fix wave with a scoped re-review; Minor is ledgered and parked unless trivial.
- Every unit of work lands as its own PR against `docs/design-system-and-planning` (the working trunk — see the ADR recorded when `master` was formally retired as a promotion target). Direct commits to the trunk branch are no longer the default.
- Kiro specs (`.kiro/specs/*/{requirements,design,tasks}.md`) remain the "what to build" authority — acceptance criteria, traceability, durable per-spec progress. A Superpowers plan implements one or more Kiro AC items; it does not restate or replace them. `grill-spec` interrogates Kiro artifacts before `writing-plans` starts, not instead of it.
- This does not resolve the open "which issue tracker" decision — `tasks.md` still says the issue tracker owns progress and none is named; that stays open.
