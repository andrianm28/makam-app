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

## Production graduation — single-host decision (23 Aug 2026)

**This section reverses items 7-9 below and the "no local production-like HA/PITR" Negative consequence** — recorded here, not silently, per the user's own explicit decision this session (confirmed via two direct confirmations: first that production uses this host instead of external managed-Postgres/object-storage providers, second that this explicitly includes real document storage).

**Decision**: Production will run on this same shared `yiemvm` host — the same host as dev/staging — using self-managed PostgreSQL 18 and self-hosted object storage (`App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage`, already real, already implements the full `ObjectStorage` contract for the combined dev/staging host; this decision extends its scope to production too — no new code is needed). Not a separate production environment. Not an external managed-PostgreSQL provider (this decision supersedes `ADR-0021` — see that ADR's own new pointer note). Not an external S3-compatible storage provider.

**This is not without precedent**: `ADR-0035` item 3 already records that the live beta runs on this same shared host by the user's own prior explicit decision, accepted "as-is for the beta's scale and audience." This decision extends that same accepted risk appetite from beta to full production.

**The single most consequential fact this decision changes, stated plainly**: `ADR-0035` item 5 records that the current beta deliberately does NOT accept real KTP/KK/death-certificate document uploads, specifically to avoid the object-storage dependency and "the highest-severity slice of UU PDP exposure for this launch." Full production graduation does NOT get the same protection — production will accept real document uploads to self-hosted storage on this shared host. This is an explicit, accepted risk, not an oversight: real Indonesian citizens' national-ID-adjacent documents will live on the same host where dev/staging experiments run, under the same "development activity can affect staging" Negative consequence already named in the Consequences section below (now: production too).

**New negative consequences, added to the existing Consequences section below**:
- No point-in-time recovery for production data — a bad migration, a bug, or an accidental delete can only be recovered to the last backup snapshot, not a specific moment. Backup strategy is `docs/operations/database-backup-and-recovery.md`'s own responsibility to define for this new reality (see that document's own update, this same plan).
- No high availability — a host failure is real production downtime with manual recovery (RTO measured in hours), not automated failover. Same shape as `ADR-0035` item 3's already-accepted beta risk, now extended to production.
- Development and staging activity can now affect real production data and real customer documents, not just staging — materially larger blast radius than the existing "development activity can affect staging" consequence.
- Capacity risk: this host (8 vCPU/31 GB) now carries dev + staging + real production traffic together. The existing "Capacity exit criteria" section (below) becomes immediately load-bearing, not aspirational — any of its named triggers (memory >80%, swap/OOM, degraded queue latency, sustained database pressure) is now a real production-risk signal, not just a staging-scale one.
- Real restricted-document storage (KTP/KK/death certificates) reopens the UU-PDP exposure `ADR-0035` item 5 deliberately avoided for the current beta — accepted explicitly per this section's own framing above, not silently.

**Explicitly NOT resolved by this decision — still open**: **OQ-7** (a real, fail-closed malware scanner for production document uploads — item 7 below's "always-on ClamAV... prohibited" line is NOT reversed by this decision; production still needs either a real scanner solution compatible with that prohibition, or a separate explicit decision to reverse it too, which the user has not made). Do not assume production document uploads are safe to accept for real until OQ-7 is resolved separately.

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
7. ~~Production data/credentials, local permanent MinIO, and always-on ClamAV are prohibited.~~ **Superseded 23 Aug 2026** (see the "Production graduation — single-host decision" section above): production data/credentials and local permanent object storage (via `LocalFilesystemObjectStorage`, not MinIO specifically — this codebase never adopted MinIO) now DO run on this host, by explicit decision. The "always-on ClamAV... prohibited" clause is NOT reversed — OQ-7 (a real production malware scanner) remains a separate, still-open question.
8. ~~The environment is not accepted as production or formal production-capacity evidence.~~ **Superseded 23 Aug 2026** (see the "Production graduation — single-host decision" section above): this host IS now production, by explicit decision — not merely staging with production-like traffic. It is still not accepted as formal production-**capacity** evidence in the performance-benchmarking sense (`docs/operations/performance-and-capacity.md`'s own framing survives this change) — a host being real production infrastructure is a different claim than it having been load-tested at production scale.
9. Production remains Ubuntu 24.04 LTS or managed equivalent (the dev/staging host already runs this OS, per the correction note above — under the 23 Aug 2026 single-host decision, this is no longer a coincidence but the actual production OS).

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
- ~~no local production-like HA/PITR~~ — **superseded 23 Aug 2026** (see the "Production graduation — single-host decision" section above): this host no longer lacks production HA/PITR because it is production, not because it gained HA/PITR — see the new bullets below, which state the same underlying fact (still no HA, still no PITR) as an accepted production risk rather than a reason this host isn't production;
- heavy import/load/scanning requires a temporary environment;
- strict monitoring and environment isolation are mandatory.
- no point-in-time recovery for production data — a bad migration, a bug, or an accidental delete can only be recovered to the last backup snapshot, not a specific moment (see the "Production graduation — single-host decision" section above);
- no high availability — a host failure is real production downtime with manual recovery (RTO measured in hours), not automated failover;
- development and staging activity can now affect real production data and real customer documents, not just staging — materially larger blast radius than the existing "development activity can affect staging" consequence above;
- capacity risk: this host (8 vCPU/31 GB) now carries dev + staging + real production traffic together — the "Capacity exit criteria" section below is immediately load-bearing, not aspirational;
- real restricted-document storage (KTP/KK/death certificates) reopens the UU-PDP exposure `ADR-0035` item 5 deliberately avoided for the current beta — accepted explicitly, not silently.

## Capacity exit criteria

Split or upgrade when memory exceeds 80%, swap/OOM appears, UAT concurrency rises, queue latency fails, database pressure persists, or always-on scanner/MinIO/build workloads become necessary.
