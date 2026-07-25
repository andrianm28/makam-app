# Makam.co.id Kiro Steering — v0.5

Read these canonical documents before planning or implementation:

1. `../../docs/product/product-brief.md`
2. `../../docs/product/mvp-scope.md`
3. `../../docs/product/information-architecture.md`
4. `../../docs/product/screen-inventory.md`
5. `../../docs/product/booking-wizard-fields.md`
6. `../../docs/product/service-catalog.md`
7. `../../docs/product/marketplace-catalog.md`
8. `../../docs/product/faq-catalog.md`
9. `../../docs/domain/traceability-matrix.md`
10. `../../docs/governance/assumptions-and-gates.md`
11. `../../docs/architecture/overview.md`
12. `../../docs/architecture/technology-baseline.md`
13. `../../docs/architecture/queue-and-outbox.md`
14. `../../docs/domain/financial-model.md`
15. `../../docs/operations/dev-staging-environment.md`
16. `../../docs/operations/ci-cd-and-release.md`
17. `../../AGENTS.md`
18. `../../docs/design/design-system.md`
19. `../../resources/css/tokens.css`
20. `../../docs/planning/kiro-specs-analysis.md`

Feature specs are canonical under `../specs/`.

`design-system.md` is the single source of truth for component contracts and the ten required UI states; `tokens.css` is the single source of truth for every design value. Never hardcode a hex, px, ms, or shadow, and never use a Tailwind arbitrary value for a design decision.

Platform foundation specs (`platform-*`) are canonical for the cross-cutting concerns — identity, payment, notifications, documents, audit, feature gates, outbox, financial ledger. A feature spec consumes them and must not redefine them.

The Stakeholder Workflow MVP is a committed acceptance baseline. External gates may change the operating mode, but they must use the documented fallback rather than removing a required public flow.

Benchmark-derived features not listed in `mvp-scope.md` remain `Proposed`, `Optional`, or `Gated`.
