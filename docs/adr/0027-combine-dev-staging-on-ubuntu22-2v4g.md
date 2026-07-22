# ADR-0027: Combine Development and Staging on Ubuntu 22.04 2 vCPU / 4 GB

## Status

Accepted for temporary non-production use.

## Context

The project needs a low-cost development and stakeholder staging environment. Traffic is low, the team is small, and production is separately governed. Duplicating PostgreSQL, Redis, object storage, build tooling, and permanent background workers would exceed the practical memory budget of a 2 vCPU/4 GB host.

Ubuntu 22.04 cannot be allowed to dictate an older application runtime. The application baseline remains PHP 8.5/Laravel 13/PostgreSQL 18/Redis 8.2.

## Decision

Use one Ubuntu 22.04 LTS host with 2 vCPU and 4 GB RAM for combined development and staging, under these conditions:

1. Application, PostgreSQL, and Redis versions run through pinned containers.
2. One PostgreSQL instance and one Redis instance are shared, with strict logical isolation.
3. Development and staging have different credentials, keys, cookies, prefixes, queues, storage, and provider sandboxes.
4. Staging runs a maximum of two normal Horizon worker processes; development and batch workers run on demand.
5. Builds are produced by CI and promoted as immutable artifacts.
6. Object storage and provider sandboxes are external.
7. Production data/credentials, local permanent MinIO, and always-on ClamAV are prohibited.
8. The environment is not accepted as production or formal production-capacity evidence.
9. Production remains Ubuntu 24.04 LTS or managed equivalent.

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
