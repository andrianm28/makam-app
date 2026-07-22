# Makam.co.id — Engineering Documentation Package

**Status:** Accepted documentation baseline v0.6  
**Source authority:** RKS Makam.co.id — Lingkup Penuh, 18 Juli 2026  
**Stakeholder MVP authority:** Workflow MVP — Indonesia End-of-Life Services Platform  
**Benchmark validation:** Al Azhar Memorial Garden, Pemakaman.co.id, Kamboja.co.id, dan Makamia  
**Purpose:** Living documentation untuk desain, implementasi, pengujian, operasi, dan AI-assisted development.

## Kedudukan paket

Paket ini memisahkan empat lapisan:

1. **RKS source requirements** — K23–K35.
2. **Stakeholder MVP expectation** — homepage, sembilan langkah pemesanan, funeral marketplace, perpanjangan, FAQ, dashboard admin, dan dashboard vendor.
3. **Design baseline** — keputusan arsitektur untuk membangun requirement dengan aman.
4. **Benchmark-derived extensions** — kemampuan opsional atau gated yang tidak otomatis menjadi scope MVP.

Mulai v0.3, workflow MVP stakeholder merupakan acceptance baseline eksplisit. Semua itemnya harus dapat ditelusuri ke screen, feature spec, contract, dan test.

## MVP yang wajib tersedia

```text
HOME
├── Pemesanan Makam
├── Layanan Pemakaman / Funeral Marketplace
├── Perpanjangan Makam
└── FAQ

Back office
├── Dashboard Admin
└── Dashboard Vendor
```

Pemesanan Makam mempertahankan sembilan langkah yang terlihat oleh pengguna:

```text
1 Lokasi
2 TPU/TPS
3 Jenis Layanan
4 Pilih Layanan
5 Ringkasan
6 Data Pemesan
7 Data Almarhum + Dokumen
8 Pembayaran
9 Konfirmasi
```

Urgent dan Pre-Need boleh bercabang secara internal setelah Step 3, tetapi entry point, progress, dan hasilnya harus tetap konsisten dengan ekspektasi pengguna. Pembayaran daring mengikuti gate K3/K4/K5; ketika gate belum aktif, Step 8 menggunakan koordinasi pembayaran manual tanpa menghilangkan langkah pembayaran dari UX.

## Product positioning

> **Platform B2B2C untuk discovery, funeral/cemetery service orchestration, transaksi, administrasi makam, dan layanan pascapemakaman lintas pengelola serta vendor.**

## Prinsip arsitektur

1. Orchestration-first dan mobile-first.
2. Exact MVP taxonomy, menu, route, langkah, kategori, dan FAQ adalah canonical product data.
3. At-Need/Urgent dan Pre-Need dapat memakai workflow internal berbeda, tetapi pengalaman masuk tetap mengikuti flow stakeholder.
4. Default availability adalah paket/kelas indikatif; specific plot hanya aktif dengan inventory otoritatif.
5. Pembayaran hanya dibuka setelah confirmation/reservation dan quote acceptance.
6. Browser redirect tidak pernah menjadi bukti pembayaran.
7. Dashboard operator/vendor tidak boleh menjadi single point of failure.
8. Semua akses sensitif memakai record-level authorization dan append-only audit.
9. Online payment, WhatsApp, auto-payout, tokenization, dan paid Pre-Need tetap gated dengan fallback yang jujur.
10. Semua acceptance criteria MVP harus memiliki automated test atau manual release check yang tercatat.

## Struktur

```text
.
├── AGENTS.md
├── CHANGELOG.md
├── .kiro/
│   ├── steering/project.md
│   └── specs/<feature>/{requirements,design,tasks}.md
├── docs/
│   ├── product/
│   ├── architecture/
│   ├── domain/
│   ├── contracts/
│   ├── governance/
│   ├── security/
│   ├── testing/
│   ├── operations/
│   ├── research/
│   └── review/
└── README.md
```

## Urutan baca

1. `docs/product/product-brief.md`
2. `docs/product/mvp-scope.md`
3. `docs/product/information-architecture.md`
4. `docs/product/screen-inventory.md`
5. `docs/domain/traceability-matrix.md`
6. `docs/architecture/overview.md`
7. `docs/architecture/technology-baseline.md`
8. `docs/architecture/queue-and-outbox.md`
9. `docs/domain/financial-model.md`
10. `docs/operations/dev-staging-environment.md`
11. `docs/operations/ai-agent-dev-stg-setup-prompt.md`
12. `docs/operations/ci-cd-and-release.md`
13. Spec fitur di `.kiro/specs/`
14. Contracts, security, testing, dan operations.

## Source of truth

| Informasi | Source of truth |
|---|---|
| Arah produk | `docs/product/product-brief.md` |
| Scope MVP stakeholder | `docs/product/mvp-scope.md` |
| Navigasi dan route | `docs/product/information-architecture.md` |
| Screen dan state UI | `docs/product/screen-inventory.md` |
| Field wizard | `docs/product/booking-wizard-fields.md` |
| Layanan pemakaman | `docs/product/service-catalog.md` |
| Kategori marketplace | `docs/product/marketplace-catalog.md` |
| Konten awal FAQ | `docs/product/faq-catalog.md` |
| Requirement fitur | `.kiro/specs/*/requirements.md` |
| Kontrak API | `docs/contracts/openapi.yaml` |
| Penerima notifikasi | `docs/contracts/notification-matrix.md` |
| Traceability | `docs/domain/traceability-matrix.md` |
| Arsitektur | `docs/architecture/overview.md` + ADR |
| Versi tech stack | `docs/architecture/technology-baseline.md` |
| Queue dan outbox | `docs/architecture/queue-and-outbox.md` |
| Financial contract | `docs/domain/financial-model.md` |
| Dev+staging environment | `docs/operations/dev-staging-environment.md` |
| AI-agent setup prompt | `docs/operations/ai-agent-dev-stg-setup-prompt.md` |
| CI/CD dan migration | `docs/operations/ci-cd-and-release.md` |
| Backup/PITR | `docs/operations/database-backup-and-recovery.md` |
| Performance | `docs/operations/performance-and-capacity.md` |
| Aturan coding agent | `AGENTS.md` |
| Release acceptance | `docs/testing/release-gates.md` |

## Gate yang tidak mengurangi cakupan UX

- **Payment gate tertutup:** Step 8 tetap ada, memakai instruksi/konfirmasi manual.
- **WhatsApp gate tertutup:** email dan in-app/admin notification tetap berjalan; UI tidak mengklaim WhatsApp terkirim.
- **Pre-Need legal gate tertutup:** jenis layanan tetap terlihat sebagai pendaftaran minat, tanpa pembayaran.
- **Urgent operational gate tertutup:** opsi menampilkan jam/cakupan dan jalur customer service, tidak menerima janji yang tidak dapat dipenuhi.
- **Grave data gate tertutup:** halaman perpanjangan menjelaskan keterbatasan dan menawarkan input/manual assistance.
- **Auto vendor payout tertutup:** finance mencatat transfer manual dan bukti.


## Technical production baseline v0.6

```text
PHP 8.5 + Laravel 13
Blade/Livewire 4 + Tailwind 4.1
Filament 5 panels
Managed PostgreSQL 18 + pg_trgm
Managed Redis 8.2 + Horizon
Transactional outbox
Private S3-compatible quarantine/storage
Session auth + privileged TOTP MFA
Pulse + error tracking + uptime + DB/Redis metrics
Immutable CI build + expand/contract migrations
Backup/PITR + restore tests
```

Online payment and automated settlement remain activation-gated until the financial decision register and shared K3–K5 acceptance tests are complete.


## Non-production host baseline v0.6

Development dan staging awal berbagi satu host non-production:

```text
Ubuntu 22.04 LTS — 2 vCPU / 4 GB RAM
├── host reverse proxy + TLS
├── containerized PHP 8.5 application runtime
├── development web — worker on demand
├── staging web — one constrained Horizon pool
├── shared PostgreSQL 18 — separate databases/users
├── shared Redis 8.2 — separate prefixes/queues/Horizon names
└── external object storage and provider sandboxes
```

Ketentuan penting:

- host Ubuntu 22.04 bukan production baseline;
- production tetap Ubuntu 24.04 LTS atau managed equivalent;
- dev dan staging memakai `APP_KEY`, database user, Redis prefix, queue, cookie, storage prefix/bucket, dan provider credential yang berbeda;
- production data dan production credentials dilarang;
- Composer/npm build, load test berat, MinIO, dan scanner malware always-on tidak dijalankan pada host 2/4;
- staging menyimpan satu worker ringan; development worker dan batch worker dijalankan hanya saat diperlukan;
- swap 2–4 GB hanya menjadi emergency buffer, bukan kapasitas normal;
- upgrade ke minimal 4 vCPU/8 GB ketika memory pressure, UAT concurrency, queue, import, atau scanner mulai melebihi baseline.

Detail operasional berada di `docs/operations/dev-staging-environment.md` dan ADR-0027.


## AI-agent setup workflow v0.6

Gunakan `docs/operations/ai-agent-dev-stg-setup-prompt.md` ketika agent mempunyai akses repository dan/atau SSH untuk menyiapkan environment secara langsung. Isi variabel non-secret menggunakan `ai-agent-dev-stg-setup-variables.env.example`, lalu gunakan `ai-agent-dev-stg-execution-checklist.md` untuk menentukan permission dan bukti yang wajib dikembalikan.

Agent wajib:

- melakukan discovery sebelum perubahan;
- menjaga Git diff dan rollback;
- tidak menerima atau mencetak secret;
- berhenti pada destructive action atau external blocker yang nyata;
- membuktikan database, Redis, queue, cookie, storage, dan provider isolation;
- mengembalikan status `READY`, `READY WITH BLOCKERS`, atau `NOT READY` berdasarkan validasi aktual.
