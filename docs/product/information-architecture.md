# Information Architecture and Route Contract

## 1. Public routes

```text
/
├── /pemesanan-makam
│   ├── /baru
│   ├── /draft/{draftId}
│   └── /konfirmasi/{orderReference}
├── /marketplace
│   ├── /kategori/{categorySlug}
│   ├── /produk/{productCode}
│   ├── /keranjang
│   ├── /checkout
│   └── /pesanan/{orderReference}
├── /perpanjangan
│   ├── /cari
│   ├── /permohonan/{renewalReference}
│   └── /konfirmasi/{renewalReference}
├── /preneed
├── /sertifikat/{subjectType}/{subjectId}
├── /faq
│   ├── /kategori/{categorySlug}
│   └── /{articleSlug}
├── /pesanan/{orderReference}
├── /pembayaran
│   ├── /kembali
│   └── /batal
├── /akun
│   ├── /draft
│   ├── /pesanan
│   ├── /perpanjangan
│   └── /dokumen
├── /masuk
├── /daftar
├── /keluar
├── /lupa-password
├── /reset-password/{token}
└── /bantuan
```

`/preneed` dan `/sertifikat/{subjectType}/{subjectId}` (ditambahkan 16 Agu 2026, P5a — `docs/superpowers/specs/2026-08-16-p5a-certificates-preneed-design.md`; dirujuk oleh komentar rute di `routes/web.php`). `/preneed` adalah permukaan Pra-Pesan publik: registrasi minat + permintaan konsultasi, **tidak pernah di-gate** oleh `G-LEGAL-01` — saat gate tertutup halaman merender banner info `PreNeedMode::InterestOnly` yang tidak bisa ditutup ("registers interest; no payment created"), dan alur minat/konsultasi tetap berjalan. `/sertifikat/{subjectType}/{subjectId}` adalah tampilan status sertifikat pelanggan (AC6, state-only): `{subjectType}` adalah nama kelas penuh subjek yang di-URL-encode (konvensi yang sama dengan kolom `certificates.subject_type`), diselesaikan terhadap allowlist tertutup — tipe tak dikenal dan id tak dikenal 404 yang tidak bisa dibedakan (tanpa enumerasi); referensi vault dokumen dan nomor dokumen tidak pernah meninggalkan server.

`/pembayaran/kembali` dan `/pembayaran/batal` (ditambahkan 10 Agu 2026, `platform-payment-adapter` AC4) adalah tujuan redirect BROWSER dari penyedia pembayaran — `success_return_url`/`cancel_return_url` pada ADR-0033. Keduanya hanya merender halaman: tidak ada transisi status, tidak ada jurnal, tidak ada klaim "sudah dibayar". Callback penyedia yang sesungguhnya adalah `POST /api/payments/webhook/{merchant}` (`docs/contracts/payment-webhook.md`), bukan kedua rute ini. Lihat `AGENTS.md` §Domain and financial invariants: "Never mark paid from browser return URL."

`/masuk`, `/daftar`, `/keluar` (`POST`), `/lupa-password`, dan `/reset-password/{token}` (ditambahkan 20 Agu 2026, `/akun` account area PR 1 — `.superpowers/sdd/2026-08-20-akun-auth-foundation/task-1-brief.md` s.d. `task-3-brief.md`) adalah permukaan same-origin session auth via guard `web` (AGENTS.md §Authentication). `/masuk`, `/daftar`, `/lupa-password`, dan `/reset-password/{token}` dibatasi middleware `guest`; `/keluar` (`POST`) dibatasi `auth`. Rute `/lupa-password` dan `/reset-password/{token}` selalu merender konfirmasi generik yang identik baik email terdaftar maupun tidak (tanpa enumerasi), dan reset kata sandi yang berhasil TIDAK melakukan auto-login. Belum ada perubahan `<x-mk.header>` dan belum ada rute `/akun/*` — keduanya PR berikutnya; kelima rute ini hanya dapat diakses lewat URL langsung untuk saat ini.

## 2. Global header

Desktop:

```text
Logo | Pemesanan Makam | Layanan Pemakaman | Perpanjangan Makam | FAQ | Masuk/Akun | Bantuan
```

Mobile:

- logo;
- hamburger navigation;
- persistent “Bantuan” or customer-service action;
- menu labels tetap sama dengan desktop.

## 3. Homepage hierarchy

1. Header/navigation.
2. Hero dengan value proposition dan CTA `Pesan Makam`.
3. Empat service cards sesuai urutan stakeholder.
4. Cara kerja singkat.
5. TPU/TPS unggulan/tersedia bila data ada.
6. Trust/safety information.
7. FAQ highlights.
8. Customer-service CTA.
9. Footer dengan privacy, terms, contact.

## 4. Navigation invariants

- Empat menu utama tidak boleh disembunyikan di balik login.
- `Pemesanan Makam` menjadi primary CTA.
- `Urgent` memiliki visual priority tetapi tidak menggunakan klaim layanan ketika gate tertutup.
- Back button wizard tidak menghapus data.
- Deep links yang membutuhkan login mengembalikan pengguna ke lokasi semula setelah autentikasi.
- Route unavailable karena gate harus memberi explanatory page, bukan 404 generik.

## 5. Dashboard routes

```text
/admin
├── /cemeteries
├── /services
├── /vendors
├── /orders
├── /marketplace-orders
├── /renewals
├── /payments
├── /transactions
├── /faq
├── /reports
└── /audit

/vendor
├── /products
├── /orders
├── /calendar
├── /transactions
├── /payouts
└── /profile
```
