---
name: makam-verify
description: How work is actually proven in this repository — the verification ladder from php -l through ci/verify-docs.sh to the sibling project and finally CI, and the honesty rules about PASS, BLOCKED, and NOT TESTED. Use before claiming any check passed, before ticking a task box, when a gate fails, or when you need to confirm real framework behaviour and vendor/ is empty.
---

# Verification

**`vendor/` and `node_modules/` are empty here, by policy.** `CLAUDE.md` and `docs/operations/ci-cd-and-release.md` §10 keep builds off this 2 vCPU / 4 GB host. So `composer install`, `npm ci`, `npm run build`, `php artisan test`, `vendor/bin/pint`, and `vendor/bin/phpstan` **cannot run here**. Do not attempt them and do not report their results.

## The ladder

**1 · `php -l`** on every PHP file touched. The one local check that genuinely works. Note it does **not** validate Blade — a `.blade.php` file is not plain PHP.

**2 · `bash ci/verify-docs.sh`** — 12 gates, pure bash + python, no `vendor/` needed. Run it **before** a batch so a failure is attributable, and **after**. It also scans `resources/` and `app/` for hex literals and Tailwind arbitrary values, so it applies to Blade, not just Markdown.

**3 · The sibling project** — `/home/ubuntu/platform-galang-dana-app` has the identical Laravel **13.22.0** and Livewire **4.3.3** installed. Use it whenever real framework behaviour is in question instead of reasoning from memory.

This matters more than it looks: **Livewire 4 and Filament 5 are new majors.** Model training data is dominated by Livewire 3 and Filament 3, so a pattern that "feels right" is often the previous major's. Check, don't recall. This is the repo's habit already — `AdminPanelHttpAccessTest` and `FaqArticleResource` both record the exact installed source they were traced against.

> **Never write inside that repository.** Copy what you need out to your scratchpad and work there. Record `git status --porcelain --untracked-files=all` before and after and confirm they match. A previous session deleted tracked files there with an unscoped `rm -rf`; caught and restored, but the rule stands.

A working harness — boots the sibling app, points Blade at scratchpad views, renders through a real Livewire component — is at
`/home/ubuntu/.tmp/claude-1000/-home-ubuntu/e61027f0-caf4-481e-b5ee-85ee4c282bab/scratchpad/p3/probe.php`.

**4 · CI is the oracle.** `.github/workflows/ci.yml` runs Pint, Larastan, `php artisan test` against real Postgres 18 + Redis, the frontend build, OpenAPI validation, the dependency audit, and the Filament-palette gate. **A batch is not done until its own CI run is green** — not when local checks pass. Push, watch the run, then report.

`ci/verify-infra.sh` is the operations companion (11 gates) and only runs on the deployment host, since it needs `docker`.

## Honesty rules

`AGENTS.md`: *"Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly."*

- `PASS` — you ran it and saw it pass. Paste the output.
- `BLOCKED` — you tried and something stopped you. Say what.
- `NOT TESTED` — you did not run it. Say why.

Gates passing is not evidence your change is correct — it is evidence you broke nothing a gate watches. Say which of the two you have. Several real gaps in this repo (the module table vs `app/`, the screen inventory vs `routes/web.php`) drifted precisely because **no gate watches them**.

**Never weaken a check to make it green.** Removing an assertion from `verify-contrast.py` to pass a build is an accessibility regression and must be rejected in review. If a gate fails for a reason you believe is wrong, report it — that has twice turned out to be more valuable than the task it interrupted.

## What cannot be verified here at all

State these up front rather than discovering them at the end:

- **Responsive** at 320/360/768/1024/1280 px — no browser on this host.
- **Accessibility** — axe, keyboard walkthrough, screen reader, 200 % zoom. Token- and class-level compliance is checkable; real behaviour is not.
- **Browser E2E** suites — Sprint 5.
- **Production capacity.** `release-gates.md` §I forbids citing this host as production evidence, and a passing test here is not capacity evidence.

## A note on tests and local drivers

`phpunit.xml`'s local default is SQLite in-memory; CI runs Postgres. Behaviour that only exists on Postgres — `CHECK` constraints, `SELECT … FOR UPDATE SKIP LOCKED` — is guarded with `markTestSkipped()` on other drivers. A test that passes locally may be silently skipping. See `makam-testing`.
