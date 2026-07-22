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
| 1 | Pilih kota/kabupaten | Salah satu dari Jakarta, Bogor, Depok, Tangerang, Bekasi |
| 2 | Pilih TPU/TPS | Detail lokasi dan availability tampil |
| 3 | Pilih jenis layanan | Makam Baru, Makam Tumpang, Urgent, Pre-Need |
| 4 | Pilih layanan | Basic dan add-on catalog |
| 5 | Ringkasan | Line item dan total |
| 6 | Data pemesan | Identitas dan contact |
| 7 | Data almarhum + dokumen | Data dan upload privat |
| 8 | Pembayaran | Online ketika gate aktif; manual fallback ketika tidak |
| 9 | Konfirmasi | Nomor pesanan, status, invoice, notification status, next step |

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

- Public specific-plot selection
- GIS plot map
- Paid Pre-Need
- Funeral protection membership
- Automated vendor settlement
- Multi-vendor partial refund automation
- Memorial/QR
- Visitation booking
- Card-on-file
