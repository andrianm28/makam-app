# Combined Development and Staging Environment — Ubuntu 24.04, 8 vCPU / 31 GB — v0.6

> **Updated 17 Aug 2026 — host migration (adrivm → yiemvm).** The combined host
> moved to yiemvm (`103.92.214.243`, Ubuntu 24.04.4, 8 vCPU / 31 GB / 93 GB free).
> The migration (`docs/operations/2026-08-17-makam-migration-to-yiemvm.md`)
> carried the compose stack, images, PostgreSQL data (verified row-count match),
> the repo + all worktrees, credentials, and the nginx TLS front (Let's Encrypt,
> carried) — `dev.makam.co.id` now resolves to yiemvm via Domainesia DNS.
> adrivm (old host) was kept running as the 7-day rollback standby.

## 1. Decision

Use one temporary non-production host for both development and staging:

```text
Host OS: Ubuntu 24.04 LTS
Compute: 8 vCPU
Memory: 31 GB RAM
Swap: 2–4 GB emergency buffer
Role: combined development + staging only
```

Production remains isolated and follows the Ubuntu 24.04/managed production baseline.

## 2. Objectives

- minimize non-production infrastructure cost;
- preserve production-compatible PHP/Laravel/PostgreSQL/Redis versions;
- provide a stable staging/UAT environment;
- keep development available without duplicating heavy services;
- prevent non-production compromise or data mistakes from affecting production.

## 3. Topology

```text
Internet / restricted access
        |
Host Nginx or Caddy + TLS
        |
        +--> dev.makam.co.id --> development web container
        |                       --> worker on demand
        |
        +--> stg.makam.co.id --> staging web container
                                --> constrained Horizon worker
                                --> host cron/scheduler

Shared infrastructure containers
- PostgreSQL 18
  - makam_dev database + makam_dev_user
  - makam_stg database + makam_stg_user
- Redis 8.2
  - isolated prefixes, queues, Horizon prefixes, cookies, locks

External non-production services
- private S3-compatible buckets/prefixes
- payment sandbox
- email sandbox
- WhatsApp sandbox/disabled mode
- error tracking with environment separation
- optional external malware scanner
```

## 4. Isolation requirements

Development and staging must have different values for:

- `APP_KEY`;
- application URL and TLS certificate;
- session cookie name/domain;
- database name and database user;
- Redis prefix, cache prefix, queue names, Horizon prefix, lock namespace;
- object-storage bucket or top-level prefix;
- payment merchant/sandbox credentials and callback URL;
- email/WhatsApp sender and template environment;
- error-tracking environment/release;
- feature-gate state;
- OAuth/API tokens if later added.

Production credentials must never be copied to the host. Production data is prohibited. Staging uses synthetic data or a formally approved, irreversibly sanitized dataset.

## 5. Access controls

- `dev.makam.co.id`: **public, by explicit decision — see note below.** No VPN, IP allowlist, or reverse-proxy authentication as of 25 Jul 2026.
- `stg.makam.co.id`: limited stakeholder/UAT access; authentication required where appropriate. **Unaffected by the note below** — this line still applies to staging as originally written.

> **Updated 25 Jul 2026 (ADR-0031).** This line originally required `dev.makam.co.id` to refuse unauthenticated access, implemented as HTTP basic auth the same day. Hours later the user explicitly requested making dev public, was shown this exact conflict, and reaffirmed the request — see [ADR-0031](../adr/0031-make-dev-environment-public.md) for the full reasoning and consequences. `auth_basic` was removed from the nginx vhost; `ci/verify-infra.sh` GATE I9 was updated in the same change to expect `200` rather than `401`/`403`. The `X-Robots-Tag: noindex` requirement in the line below is unchanged and still enforced — dev is public but still asked not to be indexed/crawled. Because dev is now reachable by anyone, including scanners (already observed probing this host for `.env`/`.git`/backup files on unrelated vhosts), §4's synthetic-data-only rule and normal secret hygiene matter more here than before, not less.
- Both environments return `X-Robots-Tag: noindex, nofollow` and disallow crawler indexing.
- SSH uses keys, no password login, least-privilege sudo, and restricted firewall rules.
- PostgreSQL and Redis are not exposed publicly.
- Admin login flows remain enabled in staging to test production behavior — MFA itself was removed entirely (22-23 Aug 2026, PR #147; see `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note), so this now refers to the password-based recent re-authentication (`App\Filament\Admin\Pages\PasswordReauthentication`) that replaced it, not a TOTP/MFA flow.

## 6. Resource budget

Indicative steady-state budget:

| Component | Target memory budget |
|---|---:|
| Ubuntu, Docker, host reverse proxy, monitoring agent | 550–750 MB |
| Shared PostgreSQL 18 | 550–800 MB |
| Shared Redis 8.2 | 100–200 MB |
| Staging web runtime | 450–700 MB |
| Development web runtime | 300–500 MB |
| Staging Horizon worker pool | 200–400 MB |
| Scheduler and small agents | 50–150 MB |
| Required headroom | 500–800 MB |

Resource values are monitoring targets, not guaranteed reservations. Persistent normal memory above 80%, sustained swap use, or OOM events trigger capacity review.

## 7. PostgreSQL profile

Use one PostgreSQL cluster with separate databases, owners, and credentials.

Suggested starting limits for the 4 GB host:

```text
shared_buffers = 256MB
work_mem = 4MB
effective_cache_size = 1GB to 1.5GB
maintenance_work_mem = 128MB
max_connections = 30 to 40
```

Use application connection limits. Do not run high-connection development tooling continuously. Enable `pg_trgm` and `unaccent` in both databases where required.

Development migrations run first. The same immutable artifact and migration set are then promoted to staging.

## 8. Redis profile

Use one non-cluster Redis instance with:

- separate prefixes and queue names;
- `HORIZON_PREFIX` per environment;
- no raw PII in job payloads;
- memory monitoring and `noeviction` policy for reliability;
- optional AOF persistence for staging queue continuity;
- no assumption that Redis is the authoritative source of truth.

## 9. Worker and scheduler profile

### Staging

Keep one constrained Horizon deployment:

```text
queues: critical, urgent, notifications, default
minimum processes: 1
maximum total processes: 2
worker memory: 128–192 MB
```

`imports`, `media`, and `reports` run through an on-demand batch worker and stop when empty. They must not compete with critical queues.

The host cron invokes the staging scheduler once per minute or runs one lightweight staging scheduler process. Overlap prevention and environment-specific locks are mandatory.

### Development

No always-on Horizon or scheduler is required. Run:

```text
php artisan queue:work --stop-when-empty
php artisan schedule:run
```

when a feature needs asynchronous behavior.

## 10. Build and deployment

The 2/4 host is a runtime target, not a build machine.

```text
Git push
-> CI: Composer install, tests, static analysis
-> CI: frontend build
-> CI: immutable application image/artifact
-> deploy development
-> smoke test
-> promote same artifact to staging
-> run safe migrations
-> graceful worker restart
-> staging smoke/UAT checks
```

Do not run routine `composer update`, frontend production build, or large dependency compilation on the host.

## 11. Files and malware scanning

- Store files in external private object storage using separate development/staging bucket or prefix.
- Do not run local MinIO permanently.
- Development may use a deterministic mock scanner for UI/domain development, with EICAR and adapter tests in CI.
- Staging release evidence requires a real scanner path: external managed scanner or an isolated temporary scanner.
- When the real scanner is unavailable, restricted files remain quarantined; staging must fail closed.
- An always-on local ClamAV daemon is excluded from the 4 GB baseline.

## 12. Backup and recovery

- Development data is disposable unless a developer explicitly creates a short-lived backup.
- Staging receives a daily encrypted logical PostgreSQL backup to remote object storage, with at least seven days of retention.
- Configuration/secrets are recovered from secret management, not database dumps.
- Staging restore is tested before initial production deployment and before high-risk migration.
- Local Docker volumes are not backups.
- The combined host does not satisfy production PITR/RTO/RPO requirements.

## 13. Observability

Minimum:

- host CPU, memory, swap, disk, load, and OOM monitoring;
- container restart and memory visibility;
- staging Horizon queue wait/failure visibility;
- structured application logs with short retention;
- external error tracking separated by environment;
- staging uptime check;
- PostgreSQL connections/locks/storage;
- Redis memory/latency/eviction;
- disk and remote-backup alerts.

Pulse is optional in development and may use reduced sampling in staging. Disable or reduce it if it materially increases memory or database load.

## 14. Workloads excluded from this host

- production traffic;
- formal full-capacity certification;
- heavy load generation from the same server;
- concurrent full 10,000-row import plus large report/media jobs during UAT;
- local S3/MinIO as permanent service;
- always-on malware signatures/scanner daemon;
- more than two continuously active Horizon processes;
- production database restore/PITR simulation at production scale.

Use a temporary isolated environment for heavy load, large migration rehearsal, or scanner/infrastructure testing when required.

## 15. Upgrade triggers

Upgrade to at least 4 vCPU/8 GB or split environments when one or more conditions recur:

- steady memory above 80%;
- sustained swap activity or any OOM kill;
- staging UAT exceeds approximately 10–20 simultaneous active users;
- critical/urgent queue cannot meet target with two workers;
- PostgreSQL connection/CPU/IO pressure;
- 10,000-row imports become routine;
- local real scanner or MinIO becomes mandatory;
- development and staging require independent availability;
- build or test jobs must run on-host;
- stakeholder UAT and developer work regularly interfere with each other.

## 16. Migration path

The host is replaceable:

1. keep application artifact immutable;
2. keep data in named volumes with remote backup;
3. keep secrets/config outside images;
4. restore PostgreSQL to new host/managed service;
5. repoint object-storage and sandbox credentials;
6. deploy same artifact;
7. verify queues, scheduler, signed URLs, and callbacks;
8. switch DNS after smoke test.


## 17. AI-agent implementation prompt

For supervised automated setup, use:

- `ai-agent-dev-stg-setup-prompt.md` — execution contract;
- `ai-agent-dev-stg-setup-variables.env.example` — non-secret inputs;
- `ai-agent-dev-stg-execution-checklist.md` — permissions, pause conditions, and required evidence.

The prompt implements this document but does not override its capacity, isolation, security, or production-separation constraints.
