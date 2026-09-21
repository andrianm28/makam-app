# MVP Scope — Stakeholder Acceptance Baseline

## Status

**MUST IMPLEMENT** untuk MVP, kecuali behavior dinyatakan sebagai gated fallback.

## 1. Public entry points

| ID | Menu | Route | MVP |
|---|---|---|---|
| HOME-01 | Pemesanan Makam | `/pemesanan-makam` | Required |
| HOME-02 | Layanan Pemakaman | `/marketplace` | Required |
| HOME-03 | Perpanjangan Makam | `/perpanjangan` | Required |
| HOME-04 | FAQ | `/faq` | Required |

Homepage wajib memiliki hero/intro singkat, empat service cards, customer-service CTA, dan status layanan Urgent yang jujur.

## 2. Booking MVP

| Step | Requirement | Required outcome |
|---:|---|---|
| 1 | Cari & Pilih | Salah satu dari Jakarta, Bogor, Depok, Tangerang, Bekasi; detail lokasi dan availability tampil; Makam Baru, Makam Tumpang, Urgent, Pre-Need; basic dan add-on catalog |
| 2 | Data Pemesan & Data Almarhum | Identitas dan contact; data dan upload privat; line item dan total pada kartu Ringkasan Pesanan |
| 3 | Pembayaran | Online ketika gate aktif; manual fallback ketika tidak |
| 4 | Konfirmasi | Nomor pesanan, status, invoice, notification status, next step |

(Catatan historis: sembilan langkah berasal dari RKS K23–K35; sejak keputusan owner 2 Sep 2026 yang kanonis adalah empat tahap di atas.)

## 3. Marketplace MVP

- Katalog minimum sesuai `marketplace-catalog.md`.
- Cart dan checkout.
- MVP boleh membatasi satu vendor per checkout, tetapi UI harus menjelaskan batasnya.
- Pembayaran online atau manual fallback.
- Vendor menerima order, menerima/menolak sesuai policy, memperbarui status, dan mengunggah bukti.
- Customer dapat melihat status.

## 4. Renewal MVP

- City and cemetery selection.
- Grave search dengan fuzzy name.
- Honest empty state dan manual input/assistance.
- Fee dengan source dan last update.
- Online payment atau manual fallback.
- Confirmation dan invoice.
- External counter marking untuk mencegah duplicate billing.

## 5. FAQ MVP

Enam kategori wajib tersedia dan dapat dikelola admin. FAQ publik mempunyai list, category filter, article detail, search sederhana, dan customer-service CTA.

## 6. Dashboards

### Admin

Required modules:

- TPU/TPS
- package/class/service/tariff
- vendor/product/service area
- booking/marketplace/renewal orders
- payment and transaction reference
- PIC and communication
- FAQ
- reports
- audit-sensitive actions

### Vendor

Required modules:

- login/panel
- product/variant/price
- service area
- calendar/availability
- incoming orders
- accept/reject
- status update
- work evidence
- transaction history
- payout status/reference

## 7. Gated fallback rules

| Gate | UX ketika tertutup |
|---|---|
| Online payment | Step 8 menampilkan metode manual/instruksi dan status menunggu verifikasi |
| WhatsApp | Email/in-app tetap terkirim; UI menyatakan WhatsApp belum tersedia |
| Urgent service | Opsi memberi jam/cakupan, tidak menerima order di luar capacity, menampilkan hotline |
| Paid Pre-Need | Menerima pendaftaran minat, tidak membuat payment |
| Grave registry data | Menampilkan penjelasan dan jalur input/manual assistance |
| Auto vendor payout | Finance mencatat transfer manual dan bukti |

## 8. Explicitly not required for MVP acceptance

> **Status note, 20 Aug 2026.** Three items below have since been built and shipped, and are
> `Covered` (test-backed, CI-passing) in `docs/domain/traceability-matrix.md`: **Paid Pre-Need**
> (PREN-01…PREN-04, P5a, 16 Aug 2026), **Memorial/QR** (MEM-01…MEM-06, P4, 16 Aug 2026), and
> **Visitation booking** (VISIT-01…VISIT-04, P4, 16 Aug 2026). This list is not rewritten because
> it recorded a real scope decision at the time it was written; it is no longer an accurate
> boundary of what exists, and it should not be read as one. `docs/domain/traceability-matrix.md`
> is the current source of truth for what's built.

- Public specific-plot selection
- GIS plot map
- Paid Pre-Need
- Funeral protection membership
- Automated vendor settlement
- Multi-vendor partial refund automation
- Memorial/QR
- Visitation booking
- Card-on-file
