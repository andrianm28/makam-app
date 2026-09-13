# Information Architecture and Route Contract

## 1. Public routes

```text
/
├── /pemesanan-makam
│   └── /draft/{draftId}
├── /marketplace
│   ├── /produk/{productCode}
│   ├── /keranjang
│   ├── /checkout
│   └── /pesanan/{orderNumber}
├── /pemakaman
│   └── /{cemeterySlug}
├── /kunjungan
│   └── /{cemeterySlug}
├── /perpanjangan
│   ├── /cari
│   ├── /konfirmasi
│   └── /pembayaran
├── /preneed
├── /sertifikat/{subjectType}/{subjectId}
├── /kwitansi/{reference}
├── /langganan/{subscriptionReference}
├── /riwayat-perawatan/{customerId}
├── /kenangan/{profileId}
├── /m/{token}
├── /faq
│   ├── /kategori/{categorySlug}
│   └── /{articleSlug}
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
├── /privasi
├── /syarat-ketentuan
└── /bantuan
```

**Regenerated 07 Sep 2026 against a real `php artisan route:list` run
(API-04 audit finding) — this tree drifted from the shipped route table in
BOTH directions.** Removed because they never existed as routes:
`/pemesanan-makam/baru` as a real page (it is only a legacy `RedirectController`
alias, kept out of the documented tree the same way `/cemeteries` below is),
`/pemesanan-makam/konfirmasi/{orderReference}`,
`/marketplace/kategori/{categorySlug}`,
`/perpanjangan/permohonan/{renewalReference}`, and a top-level
`/pesanan/{orderReference}` — none of these route names are registered.
Corrected: the renewal confirmation/payment routes carry no reference
parameter (`/perpanjangan/konfirmasi`, `/perpanjangan/pembayaran` are
session-scoped, not `{renewalReference}`-scoped), and the marketplace order
route's parameter is `{orderNumber}`, not `{orderReference}`. Added because
they were shipped but never documented: `/pemakaman` + `/pemakaman/
{cemeterySlug}` (`cemeteries.index`/`cemeteries.show` — the public cemetery
directory), `/kunjungan` + `/kunjungan/{cemeterySlug}`
(`kunjungan.index`/`kunjungan.cemetery` — visitation booking, P4), `/kwitansi/
{reference}` (`invoice.show`), `/langganan/{subscriptionReference}`
(`langganan.status`, care-subscription status), `/riwayat-perawatan/
{customerId}` (`riwayat-perawatan.index`, care history), `/kenangan/
{profileId}` and `/m/{token}` (`memorial.family`/`memorial.show`, P4
memorial), `/privasi` and `/syarat-ketentuan` (`legal.privacy`/`legal.terms`).
Legacy redirect-only aliases exist and are deliberately NOT listed as first-
class routes above (they carry no page of their own): `/cemeteries` and
`/cemeteries/{cemeterySlug}` redirect to their `/pemakaman` equivalents, and
`/memorial/{profileId}` redirects to its `/kenangan`/`/m` equivalent.

`/preneed` dan `/sertifikat/{subjectType}/{subjectId}` (ditambahkan 16 Agu 2026, P5a — `docs/superpowers/specs/2026-08-16-p5a-certificates-preneed-design.md`; dirujuk oleh komentar rute di `routes/web.php`). `/preneed` adalah permukaan Pra-Pesan publik: registrasi minat + permintaan konsultasi, **tidak pernah di-gate** oleh `G-LEGAL-01` — saat gate tertutup halaman merender banner info `PreNeedMode::InterestOnly` yang tidak bisa ditutup ("registers interest; no payment created"), dan alur minat/konsultasi tetap berjalan. `/sertifikat/{subjectType}/{subjectId}` adalah tampilan status sertifikat pelanggan (AC6, state-only): `{subjectType}` adalah nama kelas penuh subjek yang di-URL-encode (konvensi yang sama dengan kolom `certificates.subject_type`), diselesaikan terhadap allowlist tertutup — tipe tak dikenal dan id tak dikenal 404 yang tidak bisa dibedakan (tanpa enumerasi); referensi vault dokumen dan nomor dokumen tidak pernah meninggalkan server.

`/pembayaran/kembali` dan `/pembayaran/batal` (ditambahkan 10 Agu 2026, `platform-payment-adapter` AC4) adalah tujuan redirect BROWSER dari penyedia pembayaran — `success_return_url`/`cancel_return_url` pada ADR-0033. Keduanya hanya merender halaman: tidak ada transisi status, tidak ada jurnal, tidak ada klaim "sudah dibayar". Callback penyedia yang sesungguhnya adalah `POST /api/payments/webhook/{merchant}` (`docs/contracts/payment-webhook.md`), bukan kedua rute ini. Lihat `AGENTS.md` §Domain and financial invariants: "Never mark paid from browser return URL."

`/masuk`, `/daftar`, `/keluar` (`POST`), `/lupa-password`, dan `/reset-password/{token}` (ditambahkan 20 Agu 2026, `/akun` account area PR 1 — `.superpowers/sdd/2026-08-20-akun-auth-foundation/task-1-brief.md` s.d. `task-3-brief.md`) adalah permukaan same-origin session auth via guard `web` (AGENTS.md §Authentication). `/masuk`, `/daftar`, `/lupa-password`, dan `/reset-password/{token}` dibatasi middleware `guest`; `/keluar` (`POST`) dibatasi `auth`. Rute `/lupa-password` dan `/reset-password/{token}` selalu merender konfirmasi generik yang identik baik email terdaftar maupun tidak (tanpa enumerasi), dan reset kata sandi yang berhasil TIDAK melakukan auto-login. `<x-mk.header>`'s `akunHref` kini selalu resolve ke `route('akun.index')` untuk pengunjung yang sudah login (ditambahkan 20 Agu 2026, `/akun` account area PR 2 — `.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-2-brief.md` dan `task-3-brief.md`). Rute `/akun`, `/akun/draft`, `/akun/pesanan`, `/akun/perpanjangan`, dan `/akun/dokumen` kini terdaftar, semuanya di bawah middleware `auth` (pengunjung tamu diarahkan ke `route('login')`, dengan `redirectIntended(...)` mengembalikannya ke rute yang dituju setelah login). `/akun` adalah shell akun dengan empat ubin: draft pemesanan (`/akun/draft`), pesanan (`/akun/pesanan`, ditambahkan 20 Agu 2026, `/akun` account area PR 3 — `.superpowers/sdd/2026-08-20-akun-pesanan/task-2-brief.md`), perpanjangan, dan dokumen. `/akun/pesanan` merender daftar pesanan milik pengguna yang sedang login sendiri (`Order::forUser()`), terurut terbaru lebih dulu; `/akun/perpanjangan` dan `/akun/dokumen` merender `<x-mk.gate-closed-page>` "belum tersedia" karena keduanya belum memiliki infrastruktur kepemilikan pelanggan/unggah dokumen.

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
├── /pemakaman
├── /petak-makam
├── /peta-plot
├── /definisi-layanan
├── /paket-layanan
├── /vendors
├── /pesanan-pemakaman
├── /pesanan-marketplace
├── /pesanan-perpanjangan
├── /pemesanan-kunjungan
├── /kebijakan-kunjungan-pemakaman
├── /kasus-preneed
├── /persetujuan
├── /sertifikat
├── /rencana-perawatan
├── /order-kerja
├── /langganan
├── /profil-kenangan
├── /kasus-moderasi
├── /keluhan-layanan
├── /pembayaran
├── /verifikasi-pembayaran
├── /rekonsiliasi
├── /laporan
├── /laporan-keuangan
├── /artikel-faq
├── /log-audit
├── /kota-peluncuran
├── /pengaturan-situs
├── /notifikasi-aplikasi
├── /feature-gates
└── /verifikasi-ulang-kata-sandi

/vendor
├── /produk
├── /pesanan
├── /kalender
├── /order-kerja
├── /area-layanan
├── /bukti
├── /transaksi
├── /pencairan
└── /profil
```

**Regenerated 07 Sep 2026 against a real `php artisan route:list` run
(API-04 audit finding).** The previous tree was aspirational: `/services`,
`/orders`, `/renewals`, `/payments`, `/transactions`, `/reports`, and
`/audit` were never implemented under those English slugs — the shipped
Filament admin panel uses Indonesian slugs consistent with the rest of the
app (e.g. `pesanan-pemakaman` for orders, `pesanan-perpanjangan` for
renewals, `pembayaran`/`rekonsiliasi` for payments/reconciliation, `laporan`
for reports, `log-audit` for audit), and the previous tree also omitted more
than half of the real admin resources entirely (plot inventory, service
packages, preneed cases, agreements/certificates, care plans, work orders,
moderation, complaints, launch cities, site settings, feature gates, the
password-re-authentication challenge page). The `/vendor` tree gained
`/order-kerja` (work orders, `filament.vendor.resources.order-kerja.*`) and
`/area-layanan` (service areas) and `/bukti` (evidence list), which the
previous version did not list; the rest of `/vendor` was already accurate.
