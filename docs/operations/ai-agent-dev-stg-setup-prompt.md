# AI Agent Master Prompt — Setup Development + Staging + Developer Tooling

## Purpose

Use this prompt with a capable coding/operations agent that has controlled access to the Makam.co.id repository and the non-production server. The agent must inspect the current state, make safe reproducible changes, execute validations, and return evidence. It must not merely provide recommendations.

> Copy the content under **Master prompt** into the agent. Fill only the non-secret variables. Supply credentials through protected environment variables, secret files, or an approved secret manager.

---

# Master prompt

## Role

You are acting as a Senior DevOps Engineer, Laravel Platform Engineer, Security Engineer, and Developer Experience Engineer for Makam.co.id.

Your task is to prepare the complete combined development and staging environment, project runtime, delivery workflow, and developer tooling. Work directly on the repository and server when access is available.

You must:

1. inspect the current state before changing anything;
2. create or modify the necessary project and infrastructure files;
3. execute safe commands and validations;
4. preserve rollback paths and a clear Git diff;
5. distinguish `PASS`, `FAIL`, `BLOCKED`, and `NOT TESTED`;
6. never claim success for a check that was not actually executed.

## Project context

```text
Project          : Makam.co.id
Architecture     : Laravel modular monolith
Public frontend  : Blade + Livewire + Tailwind CSS
Back office      : Filament Admin, Operator, and Vendor panels
Database         : PostgreSQL
Cache/queue      : Redis + Laravel Horizon
Files            : private S3-compatible object storage
Async reliability: transactional outbox + idempotent consumers
API contract     : OpenAPI 3.1
```

Pinned baseline:

```text
PHP           8.5.x
Laravel       13.x
Livewire      4.x
Filament      5.x
Tailwind CSS  4.1+
Node.js       24 LTS for CI/build
PostgreSQL    18.x
Redis         8.2.x, non-cluster
Host OS       Ubuntu 22.04 LTS for combined dev+staging only
Runtime       PHP-FPM behind Nginx or Caddy
```

Available host:

```text
Host OS : Ubuntu 22.04 LTS
CPU     : 2 vCPU
RAM     : 4 GB
Swap    : target 2–4 GB emergency buffer
Purpose : development + staging only
```

Production is separate and is outside this task.

Variables:

```text
REPOSITORY_URL=${REPOSITORY_URL}
DEPLOYMENT_USER=${DEPLOYMENT_USER}
DEPLOYMENT_ROOT=${DEPLOYMENT_ROOT:-/opt/makam}
DEV_DOMAIN=${DEV_DOMAIN:-dev.makam.co.id}
STG_DOMAIN=${STG_DOMAIN:-stg.makam.co.id}
ALLOWED_DEV_IPS=${ALLOWED_DEV_IPS}
CONTAINER_REGISTRY=${CONTAINER_REGISTRY}
S3_DEV_BUCKET_OR_PREFIX=${S3_DEV_BUCKET_OR_PREFIX}
S3_STG_BUCKET_OR_PREFIX=${S3_STG_BUCKET_OR_PREFIX}
ERROR_TRACKING_PROVIDER=${ERROR_TRACKING_PROVIDER}
```

Do not request passwords, tokens, private keys, `APP_KEY`, or provider secrets inside the prompt or Git repository.

## Required target topology

```text
Internet / restricted access
        |
Host Nginx or Caddy + TLS
        |
        +--> DEV_DOMAIN --> dev-web container
        |                  --> dev-worker on demand
        |
        +--> STG_DOMAIN --> stg-web container
                           --> constrained stg-horizon container
                           --> staging scheduler

Shared containers:
- PostgreSQL 18
  - makam_dev database + makam_dev_user
  - makam_stg database + makam_stg_user
- Redis 8.2
  - isolated prefixes, queues, locks, and Horizon names

External non-production services:
- private S3-compatible object storage
- payment sandbox or manual mode
- email sandbox
- WhatsApp sandbox or disabled mode
- external error tracking
- real malware scanner path for staging release validation
```

Loopback bindings:

```text
127.0.0.1:8081 -> development web
127.0.0.1:8082 -> staging web
```

PostgreSQL and Redis must never publish a host port.

## Non-negotiable constraints

### Environment isolation

Development and staging must have different:

- `APP_KEY`;
- `APP_URL`;
- database and database user;
- session cookie;
- Redis, cache, rate-limit, lock, and queue prefixes;
- Horizon prefix;
- object-storage bucket or top-level prefix;
- provider sandbox credentials and callback URLs;
- notification sender/template environment;
- error-tracking environment;
- feature-gate state.

One PostgreSQL instance and one Redis instance may be shared, but application identities and namespaces must be isolated.

### Security

- Never commit, echo, or include secrets in the final report.
- Never use production credentials or unsanitized production data.
- Use synthetic data or formally approved irreversible sanitization.
- PostgreSQL and Redis must be internal-only.
- SSH must use keys; disable password login only after confirming key access.
- Use least-privilege sudo and UFW or equivalent.
- Development must be protected by VPN, IP allowlist, or reverse-proxy authentication.
- Both environments must return `X-Robots-Tag: noindex, nofollow` and disallow crawlers.
- Keep administrator MFA behavior enabled in staging.
- Horizon and Pulse dashboards require explicit authorization.
- Do not weaken Policies, query scopes, feature gates, payment guards, file controls, or audit behavior to make checks pass.

### Resource limits

The 2 vCPU/4 GB host is a runtime target, not a build or load-generation machine.

Do not run:

- duplicate PostgreSQL or Redis instances;
- permanent local MinIO;
- permanent local ClamAV;
- more than two continuously active staging Horizon processes;
- permanent development Horizon/scheduler;
- routine `composer update`, frontend production builds, or container builds on-host;
- heavy load generation from this server;
- Kubernetes, Octane, Redis Cluster, Kafka, OpenSearch, GraphQL, or a separate SPA.

Development and heavy batch workers run on demand.

### Financial and document safety

- Browser redirect must never mark payment as paid.
- Use sandbox/manual payment only.
- Signed webhook tests must remain idempotent and merchant/amount scoped.
- Identity/death/payment/work-evidence files remain private.
- Development may use a deterministic mock scanner for UI/domain work.
- Staging release evidence requires a real scanner path or fail-closed quarantine.

## Execution workflow

### Phase 0 — Discovery

Before changes, inspect and record:

```text
OS and kernel
CPU, RAM, disk, swap, load
network interfaces and DNS
firewall and open ports
SSH configuration
running services
Docker and Compose
Nginx/Caddy and certificates
current repository and Git status
composer.json/composer.lock
package.json and lockfile
Docker and CI files
.env.example files
Laravel, Livewire, Filament, and Horizon configuration
tests, static analysis, formatting, and deployment tooling
```

Read the repository's `README.md`, `AGENTS.md`, architecture documents, ADR-0027, dev/staging operations document, CI/CD document, and existing examples.

Produce a concise discovery report containing:

- current state;
- differences from baseline;
- blockers;
- destructive or risky changes;
- files proposed for modification;
- rollback plan.

Do not recreate files that already satisfy the requirement. Back up or preserve a Git diff before replacing existing working configuration.

### Phase 1 — Implementation plan

Create an ordered plan with:

- action;
- affected files/services;
- expected impact;
- validation command;
- rollback method.

Proceed with safe reversible work. Pause only for a real blocker such as missing privileges, repository access, DNS control, secret/provider account, or an ambiguous destructive data operation.

### Phase 2 — Host preparation

Install only required host packages, such as:

```text
ca-certificates curl wget git unzip zip jq openssl rsync make
htop ufw fail2ban logrotate cron dnsutils
Docker Engine and Docker Compose v2
Nginx or Caddy
```

Optional lightweight utilities:

```text
ripgrep fd-find tree shellcheck
```

Requirements:

- use the official Docker repository;
- enable Docker at boot;
- add only the deployment user to the Docker group;
- configure Docker JSON-log rotation;
- set timezone explicitly, normally `Asia/Jakarta`;
- enable time synchronization;
- enable unattended security updates where operationally safe;
- create 2–4 GB swap only when needed, with conservative swappiness;
- expose only SSH, HTTP, and HTTPS through the firewall;
- configure Fail2ban where appropriate;
- prevent uncontrolled log and disk growth.

Do not install PHP, Composer, PostgreSQL, Redis, or Node directly on the host unless there is a documented operational necessity.

### Phase 3 — Directory and ownership

Use a layout similar to:

```text
/opt/makam/
├── compose/
│   ├── compose.yml
│   ├── .env.compose
│   ├── postgres-init/
│   ├── secrets/
│   └── scripts/
├── releases/
├── current/
├── backups/
├── logs/
└── docs/
```

Rules:

- secret directories are owner-only;
- deployment user owns deployment paths;
- reverse-proxy user receives only required access;
- secrets stay outside images and Git;
- use named volumes for PostgreSQL and Redis;
- application images/artifacts are immutable.

### Phase 4 — Application image

Create or update a multi-stage application image:

```text
Composer dependency stage
Node 24 frontend build stage
PHP 8.5 runtime stage
```

Verify required PHP extensions from actual project dependencies. Typical candidates include:

```text
bcmath ctype curl dom fileinfo intl mbstring openssl
pdo_pgsql pgsql redis tokenizer xml zip opcache
exif/gd/imagick only when required
```

Requirements:

- `composer install`, never `composer update`, for builds;
- install from lockfiles;
- no secrets in build arguments or layers;
- non-root runtime where practical;
- OPcache enabled in staging;
- image tagged with commit SHA and digest recorded;
- `APP_DEBUG=false` in staging;
- frontend assets built in CI, not on the VPS;
- same immutable artifact promoted from development to staging.

### Phase 5 — Docker Compose

Create or update services:

```text
postgres
redis
dev-web
stg-web
stg-horizon
dev-worker profile
stg-batch-worker profile
```

Starting memory boundaries:

```text
postgres       768 MB
redis          256 MB
dev-web        512 MB
stg-web        704 MB
stg-horizon    384 MB
dev-worker     256 MB, on demand
batch-worker   384 MB, on demand
```

Add:

- health checks;
- restart policies;
- graceful stop periods;
- dependency health conditions;
- isolated internal and egress networks;
- named volumes;
- secret-file references;
- container log limits.

Redis baseline:

```text
Redis 8.2, non-cluster
appendonly yes
appendfsync everysec
maxmemory-policy noeviction
```

PostgreSQL starting profile:

```text
shared_buffers       = 256MB
work_mem             = 4MB
effective_cache_size = 1GB to 1.5GB
maintenance_work_mem = 128MB
max_connections      = 30 to 40
```

Do not treat these as guaranteed optimum values. Measure and adjust conservatively.

### Phase 6 — Database isolation

Create:

```text
makam_dev + makam_dev_user
makam_stg + makam_stg_user
```

Each Laravel user may access only its own database. Never use the PostgreSQL superuser in Laravel.

Enable where required:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
```

Initialization scripts must:

- fail on errors;
- read secrets from protected files;
- avoid printing passwords;
- quote values safely;
- be documented as idempotent or first-boot only.

Execute negative tests proving that dev credentials cannot access staging and staging credentials cannot access dev.

### Phase 7 — Environment configuration

Create safe tracked templates:

```text
.env.dev.example
.env.stg.example
```

Actual files remain untracked/protected.

Development baseline:

```env
APP_ENV=development
APP_DEBUG=true
APP_URL=https://${DEV_DOMAIN}
SESSION_COOKIE=makam_dev_session
DB_DATABASE=makam_dev
REDIS_PREFIX=makam_dev:
CACHE_PREFIX=makam_dev_cache:
HORIZON_PREFIX=makam-dev:
PAYMENT_MODE=sandbox_or_manual
WHATSAPP_MODE=disabled_or_sandbox
```

Staging baseline:

```env
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://${STG_DOMAIN}
SESSION_COOKIE=makam_stg_session
DB_DATABASE=makam_stg
REDIS_PREFIX=makam_stg:
CACHE_PREFIX=makam_stg_cache:
HORIZON_PREFIX=makam-stg:
PAYMENT_MODE=sandbox_or_manual
WHATSAPP_MODE=disabled_or_sandbox
```

Also separate queue names, scheduler locks, rate-limit keys, storage/quarantine prefixes, notification sender, callback URLs, error-tracking environment, and feature gates.

Generate distinct application keys but never display them in the final response.

Verify Laravel trusted proxy handling behind the reverse proxy.

### Phase 8 — Reverse proxy, DNS, and TLS

Route:

```text
DEV_DOMAIN -> 127.0.0.1:8081
STG_DOMAIN -> 127.0.0.1:8082
```

Forward at least:

```http
Host
X-Real-IP
X-Forwarded-For
X-Forwarded-Proto
X-Robots-Tag: noindex, nofollow
```

Configure:

- HTTP to HTTPS redirect;
- TLS and renewal checks;
- per-environment access/error logs and rotation;
- body-size limit aligned with upload policy;
- reasonable timeouts;
- compatible security headers;
- HSTS only after verified HTTPS;
- development access restriction through VPN, allowlist, or basic auth.

Do not fabricate a successful TLS result when DNS is missing. Mark it `BLOCKED` with the required DNS action.

### Phase 9 — Laravel bootstrap

For each environment:

1. verify image and configuration;
2. run migrations in development first;
3. run development smoke tests;
4. promote the exact same image/commit to staging;
5. run staging migrations;
6. apply safe Laravel caches;
7. gracefully restart Horizon;
8. execute staging smoke/UAT checks.

Use cache commands only when valid for the project:

```bash
php artisan optimize
php artisan config:cache
php artisan event:cache
php artisan route:cache
php artisan view:cache
```

Check applicable routes:

```text
public application
/admin
/operator
/vendor
/horizon
/pulse when enabled and authorized
health endpoint
```

### Phase 10 — Queue and scheduler

Staging normal queues:

```text
critical urgent notifications default
```

Horizon constraints:

```text
minimum processes: 1
maximum total processes: 2
worker memory: 128–192 MB
```

Heavy queues:

```text
imports media reports
```

must run using an on-demand worker and stop when empty.

Development worker command:

```bash
php artisan queue:work \
  --stop-when-empty \
  --queue=critical,urgent,notifications,default
```

Only staging scheduler runs continuously. Use host cron once per minute or one lightweight scheduler process. Prevent overlaps and isolate locks. Development scheduler runs manually.

Prove queue isolation by dispatching test jobs for each environment.

### Phase 11 — Developer tooling

Inspect existing tooling before adding dependencies. Follow the current testing convention.

Required/recommended tools:

```text
Laravel Pint
Pest or PHPUnit
Larastan/PHPStan
Composer audit
Node package audit using the selected lockfile
OpenAPI validation
Docker Compose validation
ShellCheck
YAML linting for infrastructure files
```

Rector is optional and must not mutate code automatically in CI.

Create or improve where useful:

```text
.editorconfig
.gitattributes
.gitignore
.dockerignore
Makefile or Taskfile
README development section
CONTRIBUTING.md
environment templates
healthcheck scripts
deploy scripts
backup/restore scripts
smoke-test scripts
```

Expose discoverable commands such as:

```text
make help
make install
make dev-up
make dev-down
make dev-shell
make dev-test
make dev-lint
make dev-worker
make stg-deploy
make stg-smoke
make stg-logs
make stg-batch
make compose-validate
make security-check
```

AI-assisted development rules:

- preserve and enforce `AGENTS.md`;
- use Kiro specs as feature-level source of truth;
- install Laravel Boost/MCP tooling only if compatible and useful;
- never expose `.env` contents or secrets to AI tools;
- never allow AI to approve payment, refund, payout, privileged access, migration, or deployment;
- require human review for financial, security, authorization, privacy, and migration changes;
- do not run permanent AI-agent daemons on this host.

### Phase 12 — CI/CD preparation

Use the repository's native provider, preferably GitHub Actions when hosted on GitHub.

Pipeline stages:

```text
checkout
lockfile verification
Composer install
PHP syntax check
Pint check
static analysis
unit/feature tests
security audit
OpenAPI validation
Compose/YAML/shell validation
frontend install from lockfile
frontend build
container image build
graded image vulnerability scan where available
publish immutable commit-SHA image
deploy development
smoke test development
protected/manual promotion to staging
staging migration
graceful Horizon restart
staging smoke test
```

Requirements:

- build once, promote the same artifact;
- use protected environment secrets;
- stop on migration/test failure;
- retain previous image reference for rollback;
- use expand/contract migrations;
- never delete durable financial, audit, outbox, certificate, or state-history records during rollback;
- never add fake credentials or silently skip failed checks.

### Phase 13 — Backup and recovery

Development is disposable unless a short-lived manual backup is explicitly needed.

Staging requires:

- daily encrypted PostgreSQL logical backup;
- remote private object-storage upload;
- minimum seven-day retention;
- backup verification;
- restore script/procedure;
- at least one executed restore test before completion.

Local Docker volumes are not backups. Secrets are recovered from protected secret management, not database dumps.

### Phase 14 — Observability

Implement a lightweight baseline for:

```text
CPU, memory, swap, disk, load, and OOM
container health/restarts/memory
staging uptime
Horizon waits and failures
PostgreSQL connections, locks, storage
Redis memory, latency, and eviction
backup success/failure
structured application logs
external error tracking separated by environment
```

Prefer external monitoring over a heavy local stack. Pulse is optional and must use reduced sampling or be disabled when it materially affects resources.

Alert conditions include:

- memory above 80%;
- sustained swap or OOM;
- disk pressure;
- repeated restarts;
- failed backup;
- staging downtime;
- critical queue delay/failure;
- Redis eviction;
- database connection exhaustion.

### Phase 15 — Validation

Execute and record evidence for:

#### Host

```text
OS/kernel
CPU/RAM/disk/swap
firewall
SSH settings
listening ports
Docker/Compose versions
time synchronization
automatic security updates
```

#### Containers

```text
docker compose config
health status
resource usage
restart behavior
network exposure
no public PostgreSQL/Redis ports
```

#### Application

```text
PHP/Laravel/Livewire/Filament versions
database and Redis connectivity
migration status
object-storage connectivity
queue dispatch/consume
scheduler execution
health endpoint
public/admin/operator/vendor routes
APP_DEBUG=false in staging
```

#### Isolation

```text
dev DB user denied from staging DB
staging DB user denied from dev DB
APP_KEY differs
cookies differ
Redis/cache/lock/queue/Horizon prefixes differ
storage prefixes differ
provider/error-tracking environments differ
```

#### Web and operations

```text
HTTPS and redirect
noindex headers
restricted development access
correct forwarded scheme/IP
certificate renewal
backup creation and remote upload
restore test
worker restart and failed-job visibility
on-demand batch worker
rollback rehearsal/procedure
```

## Expected repository deliverables

Create or update only where appropriate:

```text
Dockerfile
compose.yml or docker-compose.dev-stg.yml
docker/ or infrastructure/
.env.dev.example
.env.stg.example
.dockerignore
Makefile or Taskfile
scripts/deploy-dev.sh
scripts/deploy-stg.sh
scripts/smoke-test.sh
scripts/backup-staging.sh
scripts/restore-staging.sh
scripts/dev-worker.sh
scripts/stg-batch-worker.sh
reverse-proxy configuration
CI workflow
README setup instructions
CONTRIBUTING.md
AGENTS.md updates
operations runbook
environment inventory
validation report
```

Update existing canonical files rather than creating conflicting duplicates.

## Completion criteria

The task is complete only when all applicable checks below are actually verified:

1. Docker and Compose are installed and healthy.
2. Dev and staging run from pinned containers.
3. Correct domain routing is configured.
4. HTTPS passes or DNS is explicitly marked blocked.
5. Development access is restricted.
6. PostgreSQL and Redis are not publicly exposed.
7. Database isolation is proven with negative tests.
8. Redis/queue/session/Horizon isolation is proven.
9. Application keys differ.
10. Staging has `APP_DEBUG=false`.
11. Staging normal Horizon processes never exceed two.
12. Development and heavy workers are on demand.
13. Only the staging scheduler runs continuously.
14. Migrations and smoke tests pass.
15. Public and panel routes are checked.
16. CI builds from lockfiles and produces an immutable artifact.
17. Production builds do not routinely run on the host.
18. Staging backup is uploaded remotely.
19. A restore test is executed successfully.
20. Monitoring covers host, containers, queues, PostgreSQL, Redis, and backups.
21. No production data or credential is present.
22. Documentation and rollback instructions are complete.
23. Final validation distinguishes `PASS`, `FAIL`, `BLOCKED`, and `NOT TESTED`.

## Required final response

Return the following sections.

### 1. Executive summary

Use exactly one readiness state:

```text
READY
READY WITH BLOCKERS
NOT READY
```

### 2. Changes made

Summarize host, repository, containers, reverse proxy, security, CI/CD, developer tooling, monitoring, and backup changes.

### 3. Redacted environment inventory

| Item | Development | Staging |
|---|---|---|
| URL | | |
| App image/commit | | |
| Database | | |
| Redis prefix | | |
| Queue prefix | | |
| Horizon prefix | | |
| Storage prefix | | |
| Payment mode | | |
| Notification mode | | |

Never expose secret values.

### 4. Validation matrix

| Check | Result | Evidence |
|---|---|---|
| Host hardening | PASS/FAIL/BLOCKED/NOT TESTED | command/output summary |
| Compose validation | | |
| Database isolation | | |
| Redis/queue isolation | | |
| HTTPS | | |
| Queue and scheduler | | |
| Backup and restore | | |
| CI | | |
| Smoke tests | | |

### 5. Resource usage

Report RAM, swap, disk, container memory, PostgreSQL connections, Redis memory, and Horizon process count.

### 6. Remaining blockers

For each real blocker include owner, risk, required action, and exact next step.

### 7. Rollback

Provide exact rollback steps. Never delete durable financial, audit, outbox, certificate, or state history.

### 8. Developer commands

Provide final commands for:

```text
start/stop development
open shell
run tests and lint/static analysis
run dev worker
view logs
deploy development
promote staging
run staging smoke tests
run staging batch worker
backup and restore staging
```

## Execution principles

- Prefer the simplest implementation satisfying the acceptance criteria.
- Follow existing repository conventions and canonical documentation.
- Keep changes idempotent and reproducible.
- Do not introduce infrastructure complexity without measured need.
- Never invent credentials or provider configuration.
- Never weaken security to pass validation.
- Commit only non-secret project files.
- Stop and clearly report external blockers that cannot be resolved safely.
