# Technology Baseline and Version Policy — v0.5

## 1. Status

**Accepted engineering baseline, updated 23 July 2026.**

Still v0.5: content unchanged since then. Last reviewed against documentation package baseline v0.6 on 25 July 2026 — see the versioning convention in `../specs/README.md`.

This document pins the supported application technology line and separates production runtime requirements from the temporary combined development/staging host. Exact patch versions are recorded by lockfiles, runtime image digest, and deployment manifest.

## 2. Pinned application baseline

| Component | Baseline | Repository constraint / rule | Rationale |
|---|---|---|---|
| PHP | 8.5.x | Runtime image pins exact patch; `composer.json` requires `~8.5.0` | Greenfield support runway |
| Laravel | 13.x | `laravel/framework:^13.0` | Supported framework baseline |
| Livewire | 4.x | `livewire/livewire:^4.0` | Public wizard without separate SPA |
| Filament | 5.x | `filament/filament:^5.0` | Component-based admin/operator/vendor panels |
| Tailwind CSS | 4.1+ | `tailwindcss:^4.1` | Shared design-system baseline |
| Node.js | 24 LTS | build image and `.nvmrc` pin patch | CI asset build only; not required as a permanent server process |
| PostgreSQL | 18.x | Current managed/container minor | ACID, `pg_trgm`, relational integrity |
| Redis | 8.2.x | Current patch; non-cluster for Horizon | Queue/cache/session/lock |
| Object storage | S3-compatible private storage | Provider adapter | Encryption, signed URL, lifecycle |
| API specification | OpenAPI 3.1 | CI validates YAML | Contract-first integrations |

## 3. Environment operating-system matrix

| Environment | Host/runtime baseline | Status |
|---|---|---|
| Local developer | Containerized application runtime; host OS flexible | Accepted |
| Combined development + staging | **Ubuntu 22.04 LTS host, 2 vCPU / 4 GB RAM**; application/database/cache versions supplied by containers | Accepted temporary non-production baseline |
| Production | **Ubuntu 24.04 LTS or managed equivalent** | Required production baseline |

Ubuntu 22.04 on the combined host is the host operating system only. It must not force the application to use the host's default PHP/PostgreSQL packages. The runtime remains PHP 8.5, PostgreSQL 18, and Redis 8.2 through immutable images.

The combined host must be migrated or replaced before it becomes production and before its supported maintenance window is no longer acceptable to the organization.

## 4. Compatibility matrix

| Stack | Required compatibility |
|---|---|
| Laravel 13 | Project baseline PHP 8.5 |
| Filament 5 | Laravel/Livewire/Tailwind intersection in pinned lockfile |
| Horizon | Redis-backed queues; not Redis Cluster |
| Pulse | Optional/lightweight in non-production; production dashboard authorization required |
| Ubuntu 22.04 combined host | Docker/Compose and kernel/runtime compatibility verified in CI/staging |

The project uses the stricter intersection represented by the lockfiles and immutable image.

## 5. Locking rules

Required files:

```text
composer.json
composer.lock
package.json
package-lock.json or pnpm-lock.yaml
.nvmrc
Dockerfile or immutable runtime manifest
compose non-production example/configuration
ci/version-matrix.yml or equivalent
```

Rules:

1. Commit all lockfiles.
2. Build from a clean lockfile installation, never an unconstrained production update.
3. Pin runtime images by immutable digest when supported.
4. Build Composer and frontend assets in CI, not on the 2/4 host.
5. Database/Redis minor upgrades require pre-production validation and backup.
6. Dependency updates pass CI, security review, and staging smoke tests.

## 6. Non-production host constraints

The Ubuntu 22.04 2/4 host is valid only when:

- development and staging use separate application secrets and logical data scope;
- PostgreSQL and Redis are shared instances, not duplicated per environment;
- staging keeps a constrained worker pool; development workers run on demand;
- object storage, email, payment, WhatsApp, and error tracking use external sandbox/non-production services;
- production data and production credentials are absent;
- load testing, always-on ClamAV, local MinIO, and large builds are excluded;
- memory, swap, queue delay, and disk are monitored.

## 7. Upgrade policy

| Update type | Policy |
|---|---|
| Security patch | Apply after CI/staging verification as soon as practical |
| PHP/Laravel/Filament/Livewire patch | Monthly or urgent security window |
| Major framework update | Separate ADR/change plan |
| PostgreSQL minor | Test extensions, query plans, and restore |
| PostgreSQL major | Planned restore/upgrade project; never automatic |
| Redis patch/minor | Verify queue, lock, cache, ACL, persistence, failover |
| Non-production host OS | Patch regularly; migrate to Ubuntu 24.04 before production or support-risk acceptance expires |

## 8. Package-selection rule

Prefer first-party Laravel/Filament capabilities and small maintained packages. Before adding a package, document need, compatibility, license, maintenance, security history, permissions, and removal path. Unmaintained plugins must not become critical-path dependencies.

## 9. Deferred runtime choices

Not baseline requirements:

- Laravel Octane;
- Kubernetes;
- Redis Cluster;
- Elasticsearch/OpenSearch;
- separate React/Vue/Svelte frontend;
- GraphQL;
- Kafka;
- local MinIO or always-on malware scanner on the 2/4 host.

They require measured need and a new ADR.
