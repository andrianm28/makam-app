---
inclusion: always
---

# Makam.co.id — Technical Steering

Part of the v0.6 steering set (split from the former monolithic `project.md` on 25 Jul 2026 — see `governance.md` for the split rationale and file map). Architecture and infrastructure canon:

1. `../../docs/architecture/overview.md`
2. `../../docs/architecture/technology-baseline.md`
3. `../../docs/architecture/queue-and-outbox.md`
4. `../../docs/operations/dev-staging-environment.md`
5. `../../docs/operations/ci-cd-and-release.md`

Platform foundation specs (`platform-*` under `../specs/`) are canonical for the cross-cutting concerns — identity, payment, notifications, documents, audit, feature gates, outbox, financial ledger. A feature spec consumes them and must not redefine them.
