# Release Gates — v0.5 MVP

## A. Stakeholder scope acceptance

- [ ] Homepage displays four primary menus in exact order.
- [ ] Five launch regions are present.
- [ ] Booking Steps 1–9 pass desktop and mobile browser tests.
- [ ] Exact service catalog is available.
- [ ] Marketplace categories and vendor processing pass.
- [ ] Renewal six-step journey passes.
- [ ] FAQ six categories and customer-service CTA pass.
- [ ] Admin and vendor modules pass role-scoped tests.
- [ ] Traceability contains no `Missing` or `Partial` item for stakeholder MVP.

## B. UX and accessibility

- [ ] Loading, empty, error, pending, success, and support states reviewed.
- [ ] Autosave/resume and browser back behavior pass.
- [ ] Keyboard navigation, focus, labels, and touch targets pass.
- [ ] Responsive behavior passes agreed viewport matrix.
- [ ] Copy is empathetic and does not overpromise Urgent/payment availability.

## C. Payment

Either mode must pass:

### Online

- [ ] Shared payment/journal/reconciliation gate approved.
- [ ] Merchant, quote, amount, signature, replay, retry, and concurrency tests pass.
- [ ] No direct paid path.

### Manual fallback

- [ ] Instructions and reference are approved.
- [ ] Proof/verification and authorization pass.
- [ ] Pending state is truthful.
- [ ] Invoice only follows approved verification.

## D. Notifications

- [ ] Notification matrix implemented.
- [ ] Email baseline passes.
- [ ] WhatsApp enabled only with approved template/provider.
- [ ] Admin/operator/vendor recipient scope passes.
- [ ] Channel failure does not change business state.
- [ ] No sensitive attachment is sent.

## E. Marketplace/vendor

- [ ] All minimum products/categories seeded or configured.
- [ ] Single-vendor cart constraint is explicit.
- [ ] Vendor can accept/process/update/evidence.
- [ ] Vendor transaction history and payout reference are scoped.
- [ ] Customer order tracking passes.

## F. Renewal/data

- [ ] Search performance target passes.
- [ ] Empty/manual assistance behavior passes.
- [ ] Tariff source and last-updated display pass.
- [ ] External renewal marking and duplicate prevention pass.

## G. Security/operations

- [ ] No unresolved critical/high security issue without formal acceptance.
- [ ] Authorization, audit, upload, migration, backup/restore, and rollback tests pass.
- [ ] Support contacts, hours, incident owner, and escalation are configured.

## H. Technical production-readiness

- [ ] Runtime/package versions match `technology-baseline.md` and lockfiles.
- [ ] Horizon supervisors, queue priorities, long-wait alerts, and graceful restart pass.
- [ ] Transactional outbox loss/duplicate/replay tests pass.
- [ ] FIN-DEC decisions required by the activated money path are approved.
- [ ] Balanced journal, refund/payable/payout, and reconciliation tests pass for enabled features.
- [ ] Managed PostgreSQL backup/PITR configured and restore evidence is current.
- [ ] CI/CD immutable build, expand/contract migration, smoke test, and rollback rehearsal pass.
- [ ] Pulse, error tracking, uptime, DB/Redis metrics, and correlation IDs are configured and access-controlled.
- [ ] Upload quarantine and malware-scanner fail-closed behavior pass.
- [ ] Privileged MFA, session revocation, and recent re-authentication pass.
- [ ] Performance/capacity profiles pass or exceptions are formally accepted.


## I. Combined development/staging host acceptance

- [ ] Host is Ubuntu 22.04 LTS with current security updates, firewall, key-only SSH, and restricted access.
- [ ] PHP 8.5/Laravel 13/PostgreSQL 18/Redis 8.2 come from pinned images, not host-default packages.
- [ ] Development and staging have different APP keys, database users, Redis/Horizon prefixes, queues, cookies, storage, and provider credentials.
- [ ] No production data or credentials exist on the host.
- [ ] Staging normal Horizon pool is capped at two processes; development/batch workers run on demand.
- [ ] Remote staging backup and restore procedure passes.
- [ ] Memory, swap, disk, OOM, PostgreSQL, Redis, queue, and container monitoring is active.
- [ ] Restricted staging upload remains fail-closed without a real scanner.
- [ ] Dev/staging domains are access-restricted and `noindex`.
- [ ] The host is not recorded as production capacity/PITR/HA evidence.
