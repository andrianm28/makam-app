# Claude Code Instructions — Makam.co.id

**[`AGENTS.md`](AGENTS.md) is the canonical, binding instruction set for this repository. Read it in full before you plan or change anything.** This file is only a pointer to it; it deliberately does not restate its contents, because `AGENTS.md` §Documentation forbids duplicating canonical data across hand-maintained documents.

## Read these before working (by reference — do not copy them here)

| Document | Authority |
| --- | --- |
| [`AGENTS.md`](AGENTS.md) | Binding project rules; source-precedence order (RKS K23–K35 → `docs/product/mvp-scope.md` → approved ADR/specs → approved benchmark extensions) |
| [`.kiro/steering/project.md`](.kiro/steering/project.md) | Project steering context |
| [`docs/design/design-system.md`](docs/design/design-system.md) | Single source of truth for visual design decisions |
| [`resources/css/tokens.css`](resources/css/tokens.css) | Authoritative design token values |
| [`docs/planning/sprint-plan.md`](docs/planning/sprint-plan.md) | Sprint sequencing and scope |

## Rules most often broken before `AGENTS.md` is read

These are pointers with one-line summaries. The cited document governs; this list is not the rule.

1. **Never report `PASS` for an unexecuted check.** `AGENTS.md` §Infrastructure-agent execution: "Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly."
2. **Human review is mandatory for sensitive changes.** `AGENTS.md` §Infrastructure-agent execution: "AI agents may prepare migrations and deployment changes but human review is mandatory before security, authorization, financial, privacy, destructive migration, DNS, firewall, or production-affecting changes."
3. **Never duplicate canonical catalogue data.** `AGENTS.md` §Documentation: "Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations." Update the existing canonical file instead of creating a rival one.
4. **Never hardcode a design value.** `docs/design/design-system.md` names `resources/css/tokens.css` the "SINGLE SOURCE OF TRUTH for design values" and its §10 quick reference reads "NEVER hardcode a value"; arbitrary Tailwind values such as `text-[#12545E]` or `p-[13px]` are listed as prohibited.
5. **Never put restricted data in logs or chat.** `AGENTS.md` §Observability: "Never place restricted data in logs, Pulse, Horizon tags, or error trackers." Do not echo secret values into terminal output, commits, or replies.
6. **No AWS in this project.** The documented runtime is Docker containers on a single Ubuntu host for dev+staging (`docs/operations/dev-staging-environment.md` and ADR-0027, per `AGENTS.md` §Combined development/staging constraints) with managed PostgreSQL in production. No repository document references AWS, so AWS-specific guidance from any global/user-level instruction file does not apply here.

## Scope note

Updated 25 Jul 2026 — this note previously said the repository was documentation-only. That stopped being true when the Laravel 13 scaffold landed (`composer.json`, `app/`, `resources/`, `.github/workflows/ci.yml`), and CI has run and passed. Docs and application code coexist here now.

Two gate scripts, run after the change they cover:
- [`ci/verify-docs.sh`](ci/verify-docs.sh) — repository-wide, no build required. Also scans `resources/` and `app/` for hardcoded design values and arbitrary Tailwind values, so it applies to Blade components too, not just Markdown.
- [`ci/verify-infra.sh`](ci/verify-infra.sh) — the live `makam-nonprod` stack; needs `docker` access, so it only runs on the deployment host.

Composer and npm builds run in CI (`.github/workflows/ci.yml`), never on this host — see `docs/operations/ci-cd-and-release.md` §10. Do not run `npm run build` or a full `composer install` here; verify by pushing and checking the CI result instead.
