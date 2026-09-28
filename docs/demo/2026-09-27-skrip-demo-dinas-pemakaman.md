---
verification: unverified
verification-note: >-
  Draft prepared by an agent from measured routes and enum values, never walked end to end
  against a deployed build. Every claim here is unconfirmed until someone rehearses it.
---

# Skrip Demo — Dinas Pemakaman, 27 September 2026

> **Ini draf untuk Anda sunting, bukan skrip final.**
>
> Rencana tujuh hari (`docs/superpowers/specs/2026-09-18-demo-dinas-pemakaman-design.md`)
> menugaskan penulisan skrip ini kepada owner di Hari 1, dan skrip itu menjadi
> satu-satunya rujukan untuk setiap keputusan prioritas di hari-hari sesudahnya.
> Draf ini ada supaya tugas itu berubah dari **menulis dari nol** menjadi
> **menyunting**. Yang tahu apa yang ingin dilihat Dinas adalah Anda, bukan agen.
>
> Sunting bebas: buang babak, ubah urutan, ganti kalimatnya.

## Prasyarat — demo ini belum bisa dilatih

Diukur 19 Sep 2026: dev tertinggal **128 commit**, beta **126 commit**, 0 runner
terdaftar, `MAKAM_DEPLOY_RUNNER_ACTIVE` belum diset. **Tidak ada satu pun dari
128 commit itu yang hidup**, termasuk seluruh brand rebase.

Artinya: yang akan Anda lihat saat gladi **tidak sama** dengan yang ada di beta
hari ini. Skrip ini ditulis untuk keadaan **sesudah** deploy. Sebelum runbook
runner dijalankan, skrip ini tidak bisa diuji.

## Yang tidak boleh dipotong

Rencana menyebut dua hal yang tidak boleh hilang dari demo, karena keduanya
urusan Dinas sendiri: **pemesanan makam sampai order terbentuk**, dan
**perpanjangan**. Kalau salah satunya tidak selamat sampai Hari 5, itu bukan lagi
soal cakupan — itu sinyal tanggalnya perlu dirundingkan ulang.

---

## Babak 1 — Beranda (2 menit)

**Buka:** `https://makam.co.id/`

**Katakan:** "Ini wajah layanan pemakaman untuk warga. Satu pintu, dari mencari
makam sampai memperpanjang."

**Tunjukkan:** navigasi utamanya saja. Jangan menggulir sampai habis.

> ⚠️ Data pemakaman di beta adalah **contoh**, dan namanya sudah diberi
> penanda "(pemakaman contoh)" (PR #330). Sebut ini di awal, sekali, dengan
> tenang: *"Data pemakaman di sini contoh, bukan data Dinas."* Menyebutnya
> lebih awal jauh lebih baik daripada ditanya di tengah.

## Babak 2 — Pemesanan makam, ujung ke ujung (8 menit) — **INTI**

**Buka:** `https://makam.co.id/pemesanan-makam`

Wizardnya **empat layar** — ini perjalanan lengkap, bukan potongan (PR #218,
31 Agu 2026):

| # | Layar |
| --- | --- |
| 1 | Cari & Pilih |
| 2 | Data Pemesan & Data Almarhum |
| 3 | Pembayaran |
| 4 | Konfirmasi |

**Layar 1 — Cari & Pilih.** Pilih pemakaman, lalu paketnya.

> Pemilih **petak** muncul hanya untuk pemakaman ber-`plot_tracking_mode`
> granular. Di beta saat ini **hanya satu** pemakaman yang granular — pilih yang
> itu, atau pemilih petak tidak akan muncul sama sekali. Pastikan di gladi yang
> mana.

**Layar 2 — Data Pemesan & Data Almarhum.** Isi seadanya.

> 🔒 Gunakan data karangan. Jangan pernah memasukkan NIK, alamat lengkap, atau
> data pribadi sungguhan ke lingkungan beta di depan penonton.

**Layar 3 — Pembayaran.** Lihat kotak keputusan di bawah.

**Layar 4 — Konfirmasi.** **Di sinilah demo ini dinilai berhasil.** Tunjukkan
nomor ordernya. "Pesanan sudah masuk sistem."

> **Keputusan Hari 3, bukan Hari 6.** `G-PAY-01` terbuka, tapi ADR-0033 mencatat
> panggilan sandbox SumoPod sebagai **NOT TESTED** — belum pernah sekali pun
> dipanggil dari sistem ini. Kalau uji asap Hari 3 gagal dan tidak bisa
> diperbaiki hari itu juga, **tutup `G-PAY-01`**: `PaymentMode::fromGateOpen()`
> akan mengalihkan seluruh alur ke koordinasi manual, yang sudah dibangun dan
> tercakup tes. Demo tetap utuh sampai "pesanan diterima" — hanya kalimat di
> Layar 3 yang berubah menjadi *"pembayaran dikoordinasikan petugas."*

## Babak 3 — Perpanjangan (4 menit) — **INTI**

**Buka:** `https://makam.co.id/perpanjangan`

Halaman ini menampilkan pencarian "Cari Makam" yang sungguhan, bukan halaman
tergembok — `G-DATA-01` terbuka.

Alurnya: **Mulai → Konfirmasi → Pembayaran**.

**Katakan:** "Ahli waris tidak perlu datang untuk memperpanjang."

## Babak 4 — Panel operator (5 menit)

**Buka:** `https://makam.co.id/operator`

**Katakan:** "Ini sisi Dinas, bukan sisi warga."

Yang ada di panel operator hari ini, terukur:

| Permukaan | Catatan |
| --- | --- |
| Dashboard | |
| **PlotFloorMap** | Paling visual, paling relevan bagi Dinas — **buka ini lebih dulu** |
| CemeteryOrders | Pesanan dari Babak 2 harus muncul di sini |
| InAppNotifications | |

> ⚠️ **Kantong ketidakpastian terbesar dalam rencana ini.** `/operator` belum
> pernah ditelusuri sebagai satu perjalanan oleh siapa pun, dan tidak ada tes
> browser yang menutupinya. Kalau Hari 4-5 menunjukkan panel ini lebih rusak
> dari dugaan, rencananya sudah memutuskan: **tunjukkan satu alur yang jalan —
> kemungkinan besar PlotFloorMap — dan jangan buka yang lain.**

**Penutup yang kuat:** kembali ke CemeteryOrders, tunjuk pesanan dari Babak 2.
"Yang tadi dipesan warga, sekarang ada di meja petugas." Lingkaran tertutup.

## Babak 5 — Marketplace (3 menit, **paling dulu dipotong**)

**Buka:** `https://makam.co.id/marketplace`

Rencana menempatkan ini di urutan potong nomor 3: dari empat perjalanan publik,
ini yang paling jauh dari urusan sebuah dinas pemakaman. **Kalau waktu mepet,
lewati tanpa menyebutnya.**

---

## Kalau ada yang patah di tengah demo

1. **Jangan perbaiki di depan penonton.** Catat, lanjut ke babak berikutnya.
2. **Jangan buka DevTools.** Halaman error yang tenang jauh lebih baik daripada
   stack trace.
3. Kalau Babak 2 patah sebelum Layar 4, **langsung ke Babak 4** dan tunjukkan
   pesanan yang sudah ada sebelumnya di CemeteryOrders. Selalu siapkan satu
   pesanan lama sebagai cadangan.

---

## Daftar periksa UAT

Turunan langsung dari babak-babak di atas — inilah yang dijalankan dua kali
berturut-turut tanpa intervensi pada Hari 7 (24 Sep). Definisi "selesai" dalam
rencana ini adalah **daftar ini lulus dua kali**, bukan "semuanya bekerja".

| # | Langkah | Lulus bila | Babak |
| --- | --- | --- | --- |
| 1 | Buka `/` | HTTP 200, navigasi tampil | 1 |
| 2 | Buka `/pemesanan-makam` | Wizard tampil di Layar 1 | 2 |
| 3 | Pilih pemakaman **granular** | Daftar paket muncul | 2 |
| 4 | Pilih paket | Pemilih petak muncul | 2 |
| 5 | Pilih petak | Petak tertahan (hold), lanjut aktif | 2 |
| 6 | Isi Layar 2 dengan data karangan | Validasi lolos | 2 |
| 7 | Lewati Layar 3 sesuai keputusan Hari 3 | Sesuai mode yang berlaku | 2 |
| 8 | Sampai Layar 4 | **Nomor order tampil** | 2 |
| 9 | Buka `/perpanjangan` | "Cari Makam" sungguhan, bukan fallback | 3 |
| 10 | Telusuri Mulai → Konfirmasi | Tidak ada error | 3 |
| 11 | Masuk `/operator` | Dashboard tampil | 4 |
| 12 | Buka PlotFloorMap | Peta tergambar | 4 |
| 13 | Buka CemeteryOrders | **Pesanan dari langkah 8 ada di daftar** | 4 |
| 14 | Buka `/marketplace` | HTTP 200 | 5 |

**Langkah 8 dan 13 adalah demo ini.** Sisanya konteks. Kalau harus memilih apa
yang diselamatkan pada Hari 5, selamatkan dua baris itu.

## Yang belum bisa diisi tanpa Anda

- Berapa lama slot demonya, dan siapa yang bicara.
- Apakah Dinas pernah dibawakan versi lama — kalau ya, **tampilannya akan
  berbeda jauh** setelah 128 commit brand rebase masuk. Rencana menandai ini
  sebagai risiko yang harus Anda ketahui **sebelum** Hari 2.
- Pemakaman granular mana yang dipakai di Babak 2.
- Otoritas merge 18-25 Sep, yang rencana minta diputuskan pada Hari 1.
