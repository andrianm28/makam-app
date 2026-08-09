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
├── /faq
│   ├── /kategori/{categorySlug}
│   └── /{articleSlug}
├── /pesanan/{orderReference}
├── /akun
│   ├── /draft
│   ├── /pesanan
│   ├── /perpanjangan
│   └── /dokumen
└── /bantuan
```

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
