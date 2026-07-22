# Changelog

## v0.6 — 23 Juli 2026

- Menambahkan master prompt siap-eksekusi untuk AI agent yang melakukan setup development, staging, project runtime, CI/CD, dan developer tooling.
- Menambahkan discovery, planning, execution, validation, rollback, dan required final-report contract untuk agent.
- Menambahkan template variabel non-secret; secret tetap wajib melalui protected environment atau secret manager.
- Menambahkan human authorization checklist dan required pause conditions untuk perubahan SSH, firewall, DNS, database, volume, dan credential.
- Memetakan prompt ke ADR-0027, combined dev/staging baseline, immutable build, queue limits, backup/restore, observability, dan security constraints.
- Memperbarui README dan AGENTS.md agar infrastructure agents mengikuti source of truth serta tidak mengklaim validasi yang belum dijalankan.

## v0.5 — 23 Juli 2026

- Menetapkan combined development+staging host pada Ubuntu 22.04 LTS, 2 vCPU, dan 4 GB RAM sebagai baseline non-production sementara.
- Mempertahankan production baseline Ubuntu 24.04 LTS atau managed equivalent.
- Mewajibkan containerized PHP 8.5/Laravel 13 runtime agar versi aplikasi tidak bergantung pada paket default host.
- Menetapkan satu PostgreSQL 18 dan satu Redis 8.2 bersama dengan isolasi database, user, prefix, queue, Horizon, cookie, storage, dan provider credential per environment.
- Menetapkan staging worker ringan dan scheduler; development serta batch workers berjalan on demand.
- Mengecualikan build berat, load test penuh, MinIO lokal, dan malware scanner always-on dari host 2/4.
- Menambahkan CI build-off-host, remote staging backup, resource budget, low-memory runbook, dan capacity upgrade triggers.
- Menambahkan contoh Docker Compose serta reverse-proxy configuration.
- Menambahkan ADR-0027 dan validasi dokumentasi v0.5.

## v0.4 — 23 Juli 2026

- Mengunci baseline PHP 8.5, Laravel 13, Livewire 4, Filament 5, Tailwind 4.1, Node 24 LTS, PostgreSQL 18, Redis 8.2, dan Ubuntu 24.04 LTS.
- Menambahkan compatibility, lockfile, dependency, dan upgrade policy.
- Menambahkan Laravel Horizon, queue priorities, non-cluster Redis topology, dan long-wait thresholds.
- Menambahkan transactional outbox dan versioned event envelope.
- Memperjelas balanced ledger, merchant/entity binding, refund, chargeback, vendor payable/payout, reconciliation, dan activation decisions.
- Menetapkan managed PostgreSQL, automated backup, PITR, restore tests, serta provisional RPO/RTO.
- Menambahkan CI/CD immutable build, expand/contract migrations, deployment, smoke test, dan rollback procedure.
- Menambahkan production observability: structured logs, error tracking, Horizon, Pulse, uptime, DB/Redis metrics, correlation IDs.
- Menambahkan private malware-quarantine pipeline dan fail-closed scanning.
- Menetapkan session authentication, privileged TOTP MFA, re-authentication, dan panel access controls.
- Menambahkan performance/capacity profiles dan production-readiness release gates.
- Menambahkan ADR-0017 sampai ADR-0026.


## v0.3 — 23 Juli 2026

- Menetapkan Workflow MVP stakeholder sebagai acceptance baseline eksplisit.
- Menambahkan exact homepage, empat menu utama, information architecture, route, dan screen inventory.
- Menambahkan exact sembilan langkah Pemesanan Makam beserta field, validasi, autosave, branching Urgent/Pre-Need, dan payment fallback.
- Menetapkan cakupan awal Jakarta, Bogor, Depok, Tangerang, dan Bekasi.
- Menambahkan canonical service catalog dan marketplace catalog sesuai daftar stakeholder.
- Menambahkan public FAQ dengan enam topik wajib dan customer-service CTA.
- Memasukkan Funeral Marketplace, Perpanjangan, FAQ, Dashboard Admin, dan Dashboard Vendor ke MVP acceptance scope.
- Menambahkan notification recipient matrix termasuk admin/pengelola TPU/TPS.
- Memperbarui Kiro Specs, OpenAPI v0.3, test strategy, release gates, traceability, AGENTS.md, dan architecture overview.
- Menambahkan compliance review v0.3 dan automated documentation validation.

## v0.2 — 23 Juli 2026

- Menambahkan validasi benchmark Indonesia: Al Azhar Memorial Garden, Pemakaman.co.id, Kamboja.co.id, dan Makamia.
- Mengubah model availability dari satu pola global menjadi heterogeneous cemetery capability.
- Menambahkan optional plot inventory/reservation untuk source otoritatif.
- Memisahkan At-Need, Pre-Need plot purchase, funeral protection, dan care subscription.
- Menambahkan Funeral Case Management, case manager, task/checklist, deadline, dan escalation.
- Menambahkan service package/bundle, agreement, receipt, certificate, visitation, memorial, dan QR domain.
- Menetapkan single-vendor-first sebelum multi-vendor cart/settlement.
- Menambahkan ADR 0009–0016, contracts, release gates, threat controls, serta Kiro Specs baru.
- Menandai ADR-0002 sebagai superseded.

## v0.1 — 22 Juli 2026

- Initial engineering documentation derived from RKS K23–K35.
