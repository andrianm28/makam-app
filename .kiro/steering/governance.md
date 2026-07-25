---
inclusion: always
---

# Makam.co.id — Governance Steering

Part of the v0.6 steering set. Governance, gates, and financial canon:

1. `../../docs/domain/traceability-matrix.md`
2. `../../docs/governance/assumptions-and-gates.md`
3. `../../docs/domain/financial-model.md`
4. `../../AGENTS.md`

## Why this file exists (steering split, 25 Jul 2026)

`project.md` was a single flat list of 20 canonical documents with no YAML frontmatter — every document loaded on every interaction regardless of relevance, and the file carried no `inclusion` mode at all (Kiro's documented default is still `always` in that case, so behaviour was correct by accident, not by declaration). Split into five domain files — `product.md`, `tech.md`, `governance.md` (this file), `design.md`, `planning.md` — matching Kiro's documented convention of one-domain-per-file with an explicit `inclusion` mode per file ([kiro.dev/docs/steering](https://kiro.dev/docs/steering/)). `design.md` is the one file that actually benefits from this: it now loads conditionally (file-path-matched to `resources/**`, `app/Filament/**`, `app/Livewire/**`) instead of unconditionally on every single interaction regardless of whether the work touches UI at all.

`AGENTS.md` is the binding, canonical instruction set for this repository — read it in full. This steering file is only a pointer to it, per `AGENTS.md` §Documentation's own rule against duplicating canonical data across hand-maintained documents.

`docs/domain/traceability-matrix.md`: `Covered` is reserved for an item whose test exists and passes (`AGENTS.md` requires test evidence). `Specified` means spec/screen exist with no test evidence yet — see that file's own status legend before marking anything `Covered`.
