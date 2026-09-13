# Rencana: bayar penuh di muka, online saja, konfirmasi admin setelahnya

**Status:** Rencana — belum satu baris kode pun ditulis. Butuh persetujuan pemilik.
**Tanggal:** 13 September 2026
**Riset pendahulu:** [`docs/research/booking-payment-model-2026-09.md`](../../research/booking-payment-model-2026-09.md)

## Yang diminta

Dua alur dibalik urutannya.

**Pemesanan:** pelanggan pilih petak yang tersedia → **bayar penuh di muka, online saja** →
admin menerima pesanan lalu mengkonfirmasinya.

**Perpanjangan:** cari dan pilih petak yang ingin diperpanjang → **bayar penuh perpanjangan
di muka, online saja** → admin menerima lalu mengkonfirmasinya.

Tiga keputusan pemilik yang membentuk rencana ini:

1. **Tagih penuh di muka, refund kalau ditolak** — bukan authorize-hold.
2. **Harga pasti di dua tingkat**: per paket/kelas **dan** per petak individual.
3. **Pembayaran manual dihapus** — online saja.

## Konsekuensi yang mengunci sendiri dari ketiga keputusan itu

**Rail pembayaran terpaksa menyempit ke QRIS dan e-wallet.** Ini bukan pilihan
desain yang tersisa; ini hasil aritmetika. Riset pendahulu mencatat, dengan
sumber: Xendit menyatakan transaksi virtual account yang sudah dibayar tidak
bisa di-refund; matriks Midtrans menandai Bank Transfer (Permata, Mandiri, BNI,
BCA, BRI) dan OTC (Alfamart/Indomaret) sebagai **NO**. Kalau kita berjanji
refund, kita tidak boleh menerima uang lewat rail yang tidak bisa
mengembalikannya.

Konsekuensinya, dinyatakan terang supaya tidak jadi kejutan setelah rilis:

- **Tidak ada VA, tidak ada transfer bank, tidak ada Alfamart/Indomaret.**
  Ketiganya rail yang dominan di Indonesia.
- Digabung dengan penghapusan jalur manual, pelanggan **tanpa** e-wallet atau
  QRIS tidak punya cara apa pun menyelesaikan pemesanan di situs ini. Untuk
  layanan pemakaman — sering mendesak, sering diurus keluarga lanjut usia — ini
  pembatasan jangkauan yang nyata dan harus disadari sekarang, bukan ditemukan
  setelah rilis.

Rencana ini tidak membantah keputusan itu. Ia mencatatnya sebagai risiko yang
diterima, dengan nama.

## Keadaan hari ini — apa yang belum ada

Diverifikasi langsung di kode dan di database beta, 13 Sep 2026.

### 1. Sistem belum bisa menghitung "penuh"

`ComposeQuoteLinesFromBookingDraft` menyusun baris penawaran **hanya dari
`$draft->selected_services`** — definisi layanan. **Tidak ada baris untuk petak
makamnya.**

Harga petak hanya ada sebagai rentang per-TPU (`cemeteries.price_min`/`price_max`),
berlabel *"Kisaran indikatif, Perlu konfirmasi"*. Di beta,
`cemetery_packages.price_min` dan `price_max` **NULL untuk ketujuh paket**. Dua
belas `price_versions` yang ada semuanya bertuliskan *"Placeholder dev price"*,
dicatat `dev-seed-migration`.

**Tanpa harga pasti, tidak ada yang bisa ditagih.** Ini prasyarat keras untuk
semua pekerjaan lain di rencana ini.

### 2. Refund belum ada sama sekali

`RecordRefund` menulis satu baris `payment_reversals` dan satu audit event. Doc
block-nya menyatakan sendiri bahwa `PaymentProvider::refund()` **tidak ada di
mana pun di branch ini**. Kontrak `PaymentCheckoutClient` punya tepat satu
method: `createPayment()`. Tidak ada `refund()`, tidak ada `capture()`, tidak ada
`void()`.

### 3. Siklus status melarang alur baru

`OrderTransition::ALLOWED` tidak mengizinkan `MASUK → MENUNGGU_PEMBAYARAN`.
Urutan hari ini mensyaratkan verifikasi operator lalu penawaran lebih dulu.

### 4. Guard enam-kondisi berpremis terbalik

`GuardPaymentSession` mensyaratkan `ConfirmationOrReservation` dan
`QuoteAcceptedAndUnexpired` **sebelum** sesi pembayaran boleh dibuka. Kalau
pembayaran datang duluan, kedua kondisi itu mustahil terpenuhi secara definisi.
Ini kontrol finansial paling sensitif di sistem; mengubahnya butuh review manusia
per `AGENTS.md` §Infrastructure-agent execution.

### 5. Tahan-plot 15 menit terlalu pendek

TTL default `config/plot-reservation.php` adalah 15 menit. Alur bayar-di-muka
mengharuskan pelanggan berpindah ke aplikasi e-wallet dan kembali; 15 menit akan
melepas petaknya di tengah pembayaran.

## Urutan kerja

Setiap tahap satu PR, di worktree terisolasi, sesuai `AGENTS.md` §Development
methodology. Tahap yang menyentuh uang atau otorisasi **wajib review manusia
sebelum merge**.

### Tahap 0 — Harga (prasyarat keras, bukan kode)

Tidak ada tahap lain yang bisa jalan sebelum ini.

- Tambah harga pasti pada `cemetery_packages` (tingkat 1) dan pada `grave_plots`
  (tingkat 2), dengan harga petak menimpa harga paket bila terisi.
- Lewat `price_versions` yang sudah ada agar tiap perubahan harga terversi dan
  terlacak — bukan kolom telanjang yang bisa ditimpa tanpa jejak.
- UI admin untuk mengisinya.
- **Operator mengisi harga sungguhan.** Dua belas harga layanan yang ada saat ini
  adalah placeholder dev dan harus diganti juga.

Keluaran tahap ini: setiap petak yang bisa dipesan punya harga total yang bisa
dihitung sistem tanpa campur tangan manusia.

### Tahap 1 — Baris penawaran untuk petak

`ComposeQuoteLinesFromBookingDraft` menambahkan baris untuk petak yang dipilih,
bersumber dari harga Tahap 0 dengan mekanisme `price_version` yang sama seperti
layanan. Total penawaran = petak + layanan. Inilah angka yang ditagih.

### Tahap 2 — Kosakata status baru

Status hari ini mengurut pembayaran **setelah** persetujuan. Yang dibutuhkan:

```
MASUK → DIBAYAR_MENUNGGU_KONFIRMASI → DIKONFIRMASI → DIPROSES → SELESAI
                                    ↘ DITOLAK → (refund)
```

- `MENUNGGU_VERIFIKASI_PEMBAYARAN` menjadi tidak terpakai bersama jalur manual.
- `PENAWARAN_TERKIRIM` / `DISETUJUI_PEMESAN` tidak lagi ada di jalur utama —
  penawaran kini dihitung sistem, bukan diterbitkan operator.
- Migrasi status untuk pesanan yang sudah ada wajib dirancang; **jangan** menulis
  ulang riwayat pesanan yang berjalan di alur lama.

### Tahap 3 — Balik guard pembayaran

`GuardPaymentSession` dirancang ulang untuk premis baru. Kondisi yang hilang
(`QuoteAcceptedAndUnexpired`) diganti kondisi yang bermakna di alur baru: petaknya
masih ditahan oleh pelanggan ini, harganya cocok dengan yang dihitung sistem, dan
petaknya belum terjual ke orang lain.

**Kondisi anti-oversell adalah yang paling penting di seluruh rencana ini.** Alur
lama aman dari penjualan ganda karena operator mengkonfirmasi ketersediaan
sebelum uang diminta. Alur baru menghapus penjaga itu. Tanpa penggantinya, dua
pelanggan bisa membayar penuh untuk petak yang sama.

### Tahap 4 — Refund sungguhan

- Perluas `PaymentCheckoutClient` dengan `refund()`.
- Implementasikan di `SumoPodPaymentClient`. **Belum diverifikasi apakah SumoPod
  mendukung refund API sama sekali** — ini harus dipastikan sebelum tahap ini
  dijadwalkan, karena kalau tidak mendukung, seluruh model "tagih penuh, refund
  kalau ditolak" tidak bisa ditepati.
- Refund tidak instan. Butuh penanganan status asinkron, dan jurnal pembalik
  lewat `RecordRefund` yang sudah ada.
- Batasi kanal pembayaran ke yang bisa di-refund, ditegakkan **di kode**, bukan
  diandalkan pada konfigurasi dashboard gateway.

### Tahap 5 — Hapus jalur manual

Dikerjakan **terakhir**, bukan pertama. Selama Tahap 0–4 belum selesai, jalur
manual adalah satu-satunya cara pelanggan menyelesaikan pemesanan. Mencabutnya
lebih awal membuat situs tidak bisa menerima pesanan sama sekali.

### Tahap 6 — Perpanjangan, alur yang sama

Perpanjangan lebih sederhana: statusnya hanya `MENUNGGU_PEMBAYARAN` → `DIBAYAR`,
dan `GuardRenewalPaymentOpening` adalah guard-nya sendiri. Perubahan yang sama
diterapkan setelah pola pemesanan terbukti di produksi.

### Tahap 7 — TTL tahan-plot

Naikkan dari 15 menit ke jendela yang muat untuk alur pembayaran e-wallet, dan
sambungkan pelepasannya ke kegagalan/kedaluwarsa pembayaran, bukan hanya ke
lewatnya waktu.

## Yang harus diputuskan sebelum Tahap 4 dijadwalkan

- **Apakah SumoPod mendukung refund API?** Kalau tidak, model ini tidak bisa
  ditepati dan keputusan 1 harus ditinjau ulang.
- **Berapa lama admin boleh menahan konfirmasi?** Uang pelanggan sudah ditarik
  selama itu. Butuh batas waktu, dan refund otomatis kalau terlewat.
- **Siapa menanggung biaya gateway saat refund?** Sebagian besar gateway tidak
  mengembalikan MDR-nya. Setiap penolakan admin berarti kerugian nyata per
  transaksi.

## Risiko yang diterima, dicatat dengan nama

1. **Jangkauan menyempit ke pengguna QRIS/e-wallet.** Konsekuensi terkunci dari
   keputusan 1 + 3.
2. **Penjualan ganda** kalau kondisi anti-oversell Tahap 3 tidak sempurna — dan
   kini akibatnya dua pelanggan yang sudah membayar, bukan dua pesanan yang
   menunggu.
3. **Biaya gateway hangus** pada setiap penolakan admin.
4. **Refund tidak instan**; pelanggan yang ditolak menunggu uangnya.

## Yang tidak berubah

Guard enam-kondisi tetap ada sebagai konsep — ia dibalik, bukan dibuang. Jejak
audit, `payment_intents`, jurnal, dan disiplin fail-closed semuanya tetap.
Perubahan ini soal *urutan* uang dan konfirmasi, bukan soal melonggarkan kontrol.
