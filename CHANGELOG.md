# Changelog

## v0.7 — 14 September 2026

- Menghubungkan ritme vertikal §4.4 ke tokennya sendiri: `@utility py-section` / `py-section-lg`, dipakai 27 view publik. Sebelumnya setiap section di setiap halaman menulis `py-5 lg:py-8` — 20/32 px, tepat separuh dari yang §4.4 wajibkan — dan tidak ada gate yang keberatan, karena `py-5` adalah utilitas Tailwind yang sah, bukan hex hardcoded yang GATE 2 dan GATE 3 cari.
- Menambahkan `--mk-surface-quiet`, pita halaman ketiga, beserta `@utility surface-quiet` / `surface-warm`, sehingga batas antar-section terbaca tanpa garis pembatas yang §4.4 larang — ADR-0040 D1/D2.
- Memperluas bidang warna merek: `<x-mk.icon-medallion>` mendapat tone `brand` (isian `primary-600` sungguhan, bukan tint), dan footer mengikuti ritme section — ADR-0040 D3/D6. Survei beranda hidup menemukan warna merek mengisi **tepat satu** elemen di seluruh halaman sementara muncul 46 kali sebagai warna teks.
- Memberi `<x-mk.card>` sumbu penekanan, memperbesar medallion pada kartu layanan, dan judul dua nada `primary-600` + `neutral-900` dalam satu `<h3>` (A9/U8) — delapan belas kartu beranda sebelumnya identik, sehingga hierarki sepenuhnya bergantung pada ukuran kotak.
- Menaikkan bobot visual nomor hotline pada banner ketersediaan menjadi tombol sekunder, **tanpa mengubah satu kata pun salinannya** — `G-OPS-01` masih tertutup dan tidak ada klaim layanan baru yang ditambahkan.
- Menegaskan kembali bahwa banner `G-OPS-01` **tidak dapat ditutup**: §6.9 memberi dismissibility "only for informational modes", dan banner ini satu-satunya yang ber-intent `urgent`, bukan `info` — ADR-0040 D4 mencatat argumen sebaliknya yang sempat dibuat lalu ditarik.
- Menunda A10/U9 ("kartu menumpang tepi bawah hero") keluar dari Tahap 4 dengan alasan tercatat — ADR-0040 D7.

## v0.6 — 23 Juli 2026

- Mengadopsi identitas brand resmi Makam.co.id: palet Earth/Leaf menggantikan Petrol/Sandstone, font display Poppins (self-hosted, latin 600), logo raster nyata pada header/footer, favicon set, serta sinkronisasi ulang palet Filament — ADR-0034 (OQ-01/OQ-02 resolved). Seluruh nilai warna brand dan aset raster bersifat PROVISIONAL menunggu OQ-12 (nilai hex resmi, sumber vektor, dan horizontal lockup).
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
