# Makam.co.id Kiro Steering — index

**Split 25 Jul 2026** into five domain files, matching Kiro's documented multi-file steering convention ([kiro.dev/docs/steering](https://kiro.dev/docs/steering/)) instead of one monolithic always-loaded list. This file is kept as an index only — every external reference to `.kiro/steering/project.md` (`CLAUDE.md`, `README.md`, `docs/planning/parallelization-analysis.md`, `docs/planning/kiro-specs-analysis.md`, `docs/design/design-system.md`) still resolves here rather than breaking.

| File | Inclusion mode | Covers |
|---|---|---|
| [`product.md`](product.md) | `always` | Product brief, MVP scope, IA, screen inventory, booking wizard fields, service/marketplace/FAQ catalogues |
| [`tech.md`](tech.md) | `always` | Architecture overview, technology baseline, queue/outbox, dev/staging environment, CI/CD |
| [`governance.md`](governance.md) | `always` | Traceability matrix, assumptions/gates, financial model, `AGENTS.md` |
| [`design.md`](design.md) | `fileMatch` (`resources/**`, `app/Filament/**`, `app/Livewire/**`, `docs/design/**`) | `design-system.md`, `tokens.css` |
| [`planning.md`](planning.md) | `manual` | `kiro-specs-analysis.md` |

Feature specs are canonical under `../specs/`. `design-system.md` (via `design.md`) is the single source of truth for component contracts and the ten required UI states; `tokens.css` is the single source of truth for every design value. Never hardcode a hex, px, ms, or shadow, and never use a Tailwind arbitrary value for a design decision.
