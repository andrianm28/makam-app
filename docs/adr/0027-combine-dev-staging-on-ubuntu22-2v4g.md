# ADR-0027: Combine Development and Staging on a Single Non-Production Host

## Status

Accepted for temporary non-production use.

## Host specification correction (23 Aug 2026)

This ADR's title, Decision line, and item 9 state the host is "Ubuntu 22.04 LTS... 2 vCPU and 4
GB RAM." That was true of the retired `adrivm` host. The 17 Aug 2026 migration
(`docs/operations/2026-08-17-makam-migration-to-yiemvm.md`) moved the combined dev/staging stack
to `yiemvm`: **Ubuntu 24.04.4, 8 vCPU, 31 GB RAM** — a real, materially different spec, not a
rounding difference. Item 9's own statement that "Production remains Ubuntu 24.04 LTS or managed
equivalent" is now, ironically, the SAME OS the dev/staging host itself runs — worth noting
explicitly rather than leaving as an unremarked coincidence.

This correction updates the numbers this ADR's own body states; it does not reopen or reverse any
of the 9 numbered decision conditions below, all of which remain in force regardless of the exact
vCPU/RAM figures (the "combine dev+staging on one modest host" decision itself is unaffected by
the host being bigger than originally planned). The stale filename
(`0027-combine-dev-staging-on-ubuntu22-2v4g.md`) is left unchanged — only 2 other files reference
this ADR by its exact path (`docs/adr/0031-make-dev-environment-public.md`,
`docs/adr/0035-beta-launch-accepted-risks.md`), and renaming would need updating both without any
real benefit; the filename is a historical label at this point, not a live claim.

## Context

The project needs a low-cost development and stakeholder staging environment. Traffic is low, the team is small, and production is separately governed. Duplicating PostgreSQL, Redis, object storage, build tooling, and permanent background workers would exceed the practical memory budget of a 2 vCPU/4 GB host.

Ubuntu 22.04 cannot be allowed to dictate an older application runtime. The application baseline remains PHP 8.5/Laravel 13/PostgreSQL 18/Redis 8.2.

## Decision

Use one combined non-production host for development and staging (originally Ubuntu 22.04 LTS,
2 vCPU/4 GB; now `yiemvm`, Ubuntu 24.04.4, 8 vCPU/31 GB — see this ADR's own correction note
above), under these conditions:

1. Application, PostgreSQL, and Redis versions run through pinned containers.
2. One PostgreSQL instance and one Redis instance are shared, with strict logical isolation.
3. Development and staging have different credentials, keys, cookies, prefixes, queues, storage, and provider sandboxes.
4. Staging runs a maximum of two normal Horizon worker processes; development and batch workers run on demand.
5. Builds are produced by CI and promoted as immutable artifacts.
6. Object storage and provider sandboxes are external.
7. Production data/credentials, local permanent MinIO, and always-on ClamAV are prohibited.
8. The environment is not accepted as production or formal production-capacity evidence.
9. Production remains Ubuntu 24.04 LTS or managed equivalent (the dev/staging host now happens
   to run the same OS version as this target, per the correction note above — that is a
   coincidence of the 17 Aug 2026 migration, not evidence this host is production-equivalent;
   item 8 still governs).

## Consequences

### Positive

- minimum infrastructure cost;
- production-compatible application versions;
- shared services reduce memory use;
- staging remains available for UAT;
- simple migration to a larger host or managed services.

### Negative

- development activity can affect staging;
- limited worker and batch capacity;
- no local production-like HA/PITR;
- heavy import/load/scanning requires a temporary environment;
- strict monitoring and environment isolation are mandatory.

## Capacity exit criteria

Split or upgrade when memory exceeds 80%, swap/OOM appears, UAT concurrency rises, queue latency fails, database pressure persists, or always-on scanner/MinIO/build workloads become necessary.
