# ADR-0030: Fresh Laravel Skeleton, Not a Starter Kit

## Status

Accepted — 25 July 2026. Records a scaffold already executed under S1-T6 ([`sprint-plan.md`](../planning/sprint-plan.md), OQ-1).

## Context

Sprint 1 needed an application skeleton before any module code could land. Laravel offers a starter-kit path (Livewire/Breeze-style) that ships opinionated registration, login, and password-reset scaffolding. This project instead requires the `IdentityAccessAdapter` module boundary ([`overview.md`](../architecture/overview.md) §5) and, per [`AGENTS.md`](../../AGENTS.md), same-origin session auth with **mandatory TOTP MFA for privileged roles** and recent re-authentication for sensitive actions. A starter kit's auth views and flows are not written against that boundary or that MFA model, so adopting one would mean ripping out generated code rather than building on it.

## Decision

Scaffold from the authentic `laravel/laravel` skeleton (no starter kit), and create explicit, empty module namespaces under `app/Domain/` and `app/Platform/` at scaffold time rather than growing them organically.

1. **Skeleton, not starter kit.** `composer create-project laravel/laravel` gave `laravel/framework ^13.0`. The skeleton's `php: ^8.3` constraint was tightened to `~8.5.0` to match [`technology-baseline.md`](../architecture/technology-baseline.md) §2. Livewire 4, Filament 5, and Laravel Horizon 5 were added to `composer.json`, since none ship in the base skeleton; Larastan was added to `require-dev` for the static-analysis CI stage.
2. **Module namespaces created empty, not deferred.** `app/Domain/` holds one directory per non-platform module in `overview.md` §5 — 18 directories. `app/Platform/` holds one per the eight `platform-*` foundation specs from ADR-0029 — 8 directories. Both are scaffolding only (`.gitkeep`/`README.md`), with no code inside. Module boundaries are rarely retrofitted cleanly once feature code exists, so the directories exist before the first feature does.
3. **Lockfiles are resolved, not aspirational.** `composer update --no-install` and `npm install --package-lock-only` produced `composer.lock` and `package-lock.json` by dependency resolution only; no `vendor/` or `node_modules/` was downloaded on the combined host, because [`ci-cd-and-release.md`](../operations/ci-cd-and-release.md) §10 forbids heavy builds on the 2 vCPU / 4 GB combined dev+staging host. Resolution pinned `laravel/framework v13.22.0`, `livewire/livewire v4.3.3`, `filament/filament v5.7.3`, `laravel/horizon v5.48.1`.
4. **Default placeholder content removed, not kept.** The skeleton's `welcome.blade.php` was deleted rather than left in place: it hardcodes colours and Tailwind arbitrary values that the token-driven design-system gates (ADR-0028) reject, and `AGENTS.md` requires the homepage to present exactly four services in a fixed order — a generic placeholder at `/` would misrepresent the product.

## Consequences

### Positive

- No starter-kit auth scaffolding to strip out later; `platform-identity-and-access` starts from nothing rather than from code shaped for a different auth model.
- Module boundaries are explicit from day one instead of negotiated after features exist.
- Lockfiles reflect a real dependency resolution (0 Composer security advisories reported at resolution time), not a placeholder.

### Negative

- Session handling, login, and password-reset UI must now be built from scratch for `platform-identity-and-access`, rather than adapted from generated code — more first-party work than a starter kit would have left.
- The 18 `app/Domain/` and 8 `app/Platform/` directories are empty scaffolding today. Nothing in the repository enforces that future code actually lands in the correct module directory.

## NOT TESTED

- **Correction to the drafting record.** The agent that first wrote this ADR checked `.github/workflows/ci.yml` and correctly found its header commented *"this workflow has never run"* — an accurate statement at the time that file was authored, and the agent was right not to assert CI was green on faith. It has since actually run: CI is verified green as of 25 Jul 2026, run `30147031136` (commit `e8e8c23`), all six jobs passing. It took two fix cycles — the first real run failed five of six jobs and surfaced six genuine bugs no local check had caught (two missing test directories, a Bunny Fonts CDN import contradicting the self-hosted-font policy in ADR-0028, a broken link invisible outside a clean checkout, a missing PyYAML install, a missing `phpstan.neon`). The `ci.yml` header comment is updated to match.
- **No application code beyond the three skeleton PHP files exists.** `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, and `app/Http/Controllers/Controller.php` are the only PHP files in `app/`; everything under `app/Domain/` and `app/Platform/` is an empty directory marker. The application has been proven to install, lint, statically analyse, and build **inside CI**; it has not been observed to boot, and no `vendor/` or `node_modules/` has ever existed on the deployment host, by design (`ci-cd-and-release.md` §10).
