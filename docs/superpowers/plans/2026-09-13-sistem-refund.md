# Rencana: sistem refund

**Status:** R0 selesai (PR #302). R1 terblokir satu keputusan desain — lihat §Tahap R1.
**Tanggal:** 13 September 2026
**Memblokir:** Tahap 4 dan Tahap 5 dari
[`2026-09-13-bayar-penuh-di-muka-online-saja.md`](2026-09-13-bayar-penuh-di-muka-online-saja.md)

## Kenapa ini mendesak

Pemilik memutuskan: pelanggan **membayar penuh di muka**, admin **mengkonfirmasi
setelahnya**, dan pembayaran **online saja, mutlak**.

Itu menciptakan satu keadaan yang tidak boleh ada tanpa jawaban: **admin
menolak pesanan yang sudah dibayar penuh.** Petaknya ternyata tidak tersedia,
datanya tidak valid, kapasitasnya penuh. Uangnya sudah pindah.

Pada layanan pemakaman, orang di ujung sana adalah keluarga yang sedang
berduka, sudah membayar, dan sedang menunggu. Kewajiban mengembalikan uang itu
bukan fitur — ia **utang**, dan utang yang tidak dicatat adalah utang yang
dilupakan.

## Keadaan hari ini, diverifikasi bukan diingat

**SumoPod tidak mendukung refund API** — dikonfirmasi pemilik 13 Sep 2026.
`PaymentCheckoutClient` punya tepat satu method: `createPayment()`.

`RecordRefund` menulis satu baris `payment_reversals` dan satu audit event, dan
**sengaja tidak** memanggil `Journal::postReversal()` — doc block-nya menyatakan
alasannya sendiri.

Kolom `payment_reversals` hari ini:

```
id, reversal_type, reference, amount_minor, reason,
recorded_by_actor_ref, recorded_at, created_at, updated_at
```

Yang **tidak ada**, dan tiap satunya adalah cara sebuah kewajiban hilang:

| Hilang | Akibatnya |
|---|---|
| `status` | Tidak bisa membedakan "terutang" dari "sudah dibayar" |
| tautan ke pesanan | Tidak bisa menjawab "pesanan ini sudah direfund belum?" |
| `executed_at` / `executed_by` | Tidak ada yang tahu apakah uangnya benar-benar berpindah |
| bukti eksekusi | Tidak ada yang bisa dibuktikan ke pelanggan yang bertanya |
| tenggat | Tidak ada yang jadi terlambat, jadi tidak ada yang mendesak |
| tujuan dana | Tidak tahu ke mana mengirimnya |

**Nol baris di dev maupun beta.** Mekanisme ini belum pernah dipakai sekali pun.

Kesimpulannya: yang ada sekarang mencatat bahwa refund **diputuskan**, bukan
bahwa refund **dibayarkan**. Itu log keputusan, bukan buku kewajiban.

## Gagasan pokok rencana ini

**Pertanyaan penyedia hanya menentukan BAGAIMANA uang berpindah — bukan apakah
kewajibannya tercatat.**

Itu memisahkan masalah jadi dua, dan yang penting bisa dibangun sekarang tanpa
menunggu penyedia mana pun:

1. **Buku kewajiban** — tidak tergantung penyedia. Dibangun sekarang.
2. **Eksekusi** — tergantung penyedia. Manual hari ini, API begitu ada penyedia
   yang mendukungnya.

## Satu invarian yang menentukan segalanya

> **Kewajiban refund dibuat oleh transaksi yang sama dengan yang menolak
> pesanan. Bukan sesudahnya, bukan oleh job, bukan oleh admin yang ingat.**

Kalau penolakan dan pembuatan kewajiban terpisah transaksi, maka satu crash di
antaranya menghapus klaim pelanggan sementara uangnya sudah diambil. Repo ini
sudah punya pola untuk ini — `Audit::wrap()` menaruh mutasi dan jejak auditnya
dalam satu transaksi, dan `RecordOrderStatusChange` melepas reservasi plot di
dalam closure mutasinya sendiri. Kewajiban refund mengikuti pola itu.

Dan: **tidak ada yang menutup kewajiban kecuali eksekusi yang tercatat beserta
buktinya.** Bukan admin yang menandai selesai. Bukan kedaluwarsa.

## Tenggat: 3 hari kerja — diputuskan pemilik 13 Sep 2026

Sebuah kewajiban refund harus **dieksekusi dalam 3 hari kerja** sejak ia lahir,
yaitu sejak transaksi yang menolak pesanan berhasil di-commit. Lewat dari itu,
kewajiban tersebut **terlambat**, dan Tahap R4 membuatnya berisik.

Angka ini bukan sekadar kolom. Ia menentukan tiga hal sekaligus:

| Yang ditentukannya | Akibatnya di kode |
|---|---|
| Kapan sebuah kewajiban jatuh tempo | `due_at` dihitung saat pembuatan, disimpan, tidak dihitung ulang |
| Kapan ia jadi "terlambat" | Satu perbandingan, bukan kebijakan yang tersebar |
| Apa yang muncul di watchdog | Tahap R4 punya sesuatu yang bisa dibandingkan |

### "Hari kerja", bukan 72 jam

Ini perbedaan yang nyata, bukan kerewelan. Kewajiban yang lahir **Jumat sore**
jatuh tempo **Rabu**, bukan Senin. Menghitungnya sebagai 72 jam akan membuat
sistem menandai operator terlambat pada pekerjaan yang tidak mungkin ia
kerjakan — dan alarm yang salah adalah alarm yang orang belajar abaikan.

Repo ini **belum punya** helper hari kerja: `grep` untuk `businessDay`,
`addWeekdays`, `isWeekend`, `holiday` di `app/` dan `config/` tidak mengembalikan
apa pun. Jadi R0 membawa satu seam baru — dan seam itu, bukan pemanggilnya, yang
dites.

### Hari libur nasional: dinyatakan, tidak dikarang

Implementasi pertama melewati **Sabtu dan Minggu saja**. Hari libur nasional
Indonesia **tidak** dilewati, karena melewatinya menuntut kalender resmi yang
repo ini tidak punya dan yang berubah tiap tahun — mengarangnya akan
memperkenalkan data yang salah ke dalam perhitungan tenggat uang orang.

Konsekuensinya dinyatakan terang-terangan: **pada pekan dengan libur nasional,
tenggat akan terasa lebih ketat dari maksud "3 hari kerja"**. Seam-nya dibuat
supaya kalender libur bisa dipasang belakangan tanpa menyentuh satu pun
pemanggil. Bila pemilik ingin libur nasional ikut dihitung, itu **satu keputusan
terpisah** yang datang dengan kewajiban menyediakan sumber kalendernya.

## Tahapan

### Tahap R0 — Buku kewajiban

Perluas `payment_reversals`, atau tabel baru bila bentuknya terlalu berbeda,
sehingga sebuah kewajiban punya: status (`terutang` → `dieksekusi` →
`terkonfirmasi`), tautan ke pesanan dan ke sesi pembayaran aslinya, jumlah,
tenggat (`due_at` = 3 hari kerja sejak kewajiban lahir), aktor yang memutuskan,
aktor yang mengeksekusi, waktu eksekusi, dan rujukan bukti.

Status ditulis append-only mengikuti disiplin yang sudah dipakai
`price_versions` dan `audit_events`, bukan satu kolom yang ditimpa — karena
"kapan status ini berubah dan oleh siapa" adalah pertanyaan yang pasti ditanya.

### Tahap R1 — Penolakan menciptakan kewajiban, otomatis

> **TERBLOKIR sejak 13 Sep 2026 — bukan oleh pekerjaan, tapi oleh satu
> keputusan desain yang bukan wewenang saya.** Rinciannya di bawah. R0 sudah
> selesai dan berdiri sendiri tanpa ini.

Rencana awal tahap ini berbunyi: `RecordOrderStatusChange` sudah melepas
reservasi plot ketika pesanan masuk status terminal, jadi transisi ke
`DITOLAK`/`DIBATALKAN` pada pesanan yang sudah dibayar cukup ikut membuat
kewajiban refund di dalam closure mutasi yang sama.

**Membangunnya membuktikan kalimat itu tidak bisa dijalankan hari ini**, dan
alasannya lebih dalam dari sekadar kode yang belum ada.

#### Tiga fakta yang saya verifikasi, bukan ingat

1. `OrderTransition::ALLOWED` berbunyi `'DIBAYAR' => ['DIPROSES']`. **Tidak ada
   satu pun status terminal yang bisa dicapai setelah `DIBAYAR`.** Jadi
   "menolak pesanan yang sudah dibayar" bukan transisi yang sedang gagal — ia
   bukan transisi yang ada.

2. Itu bukan kelalaian. `docs/domain/order-lifecycle.md` §3 memutuskannya
   dengan sengaja: *"Nothing terminal is reachable after `DIBAYAR`: once money
   is confirmed, correction happens through a compensating financial action
   (payment reversal), never a status edge."*

3. Transisi terminal yang memang bisa terjadi selagi uang mungkin sudah
   berpindah — `DIBATALKAN` dari `MENUNGGU_VERIFIKASI_PEMBAYARAN` (§3 sendiri
   menyebut alasannya: *"unverified money may already have moved"*) dan
   `KEDALUWARSA` dari `MENUNGGU_PEMBAYARAN` — keduanya milik **jalur manual**,
   yang pemilik putuskan untuk dihapus seluruhnya.

#### Yang sebenarnya sedang ditanyakan

Dokumen kanonik itu **sudah menjawab** bentuk koreksi setelah pembayaran, dan
jawabannya adalah tindakan finansial kompensasi — yaitu persis `refund_obligations`
yang R0 bangun. Maka pertanyaannya bukan "bagaimana menyambungkan hook", tapi:

> **Penolakan admin atas pesanan yang sudah dibayar itu sebuah status baru,
> atau sebuah tindakan finansial kompensasi?**

- Kalau **status**: `DIBAYAR → DITOLAK` harus ditambahkan, dan
  `order-lifecycle.md` §3 harus diubah — mencabut aturan yang ia tetapkan
  dengan sengaja.
- Kalau **tindakan kompensasi**: pesanan tetap `DIBAYAR`, dan kewajiban lahir
  dari transaksi tindakan itu sendiri, bukan dari perubahan status.

Invarian R1 **tidak berubah** oleh jawaban mana pun: kewajiban dibuat oleh
transaksi yang sama dengan yang menolak. Yang berubah hanyalah transaksi mana
itu.

#### Kenapa saya tidak memilih sendiri

Ini menyentuh aturan siklus hidup pesanan dan uang pelanggan sekaligus.
`AGENTS.md` §Infrastructure-agent execution mewajibkan review manusia sebelum
perubahan finansial, dan mengubah §3 secara sepihak adalah mencabut keputusan
domain yang sudah tertulis agar rencana saya sendiri bisa jalan — persis urutan
terbalik yang tidak boleh.

Ini juga **sudah dijadwalkan**: Tahap 2 dari
[`2026-09-13-bayar-penuh-di-muka-online-saja.md`](2026-09-13-bayar-penuh-di-muka-online-saja.md)
adalah "kosakata status". Di situlah keputusan ini seharusnya diambil, dan R1
menunggunya.

Saya **tidak** membangun hook yang tidak bisa dipicu. Penjaga yang tidak pernah
menyala terlihat seperti perlindungan, dan itu lebih buruk daripada tidak ada.

### Tahap R2 — Eksekusi manual, dengan bukti

Karena SumoPod tidak mendukung refund, eksekusi hari ini adalah transfer bank
manual oleh operator. Sistem tidak memindahkan uangnya; sistem **menagih
operator** dan menyimpan buktinya.

#### Jalur uangnya dua hop, bukan satu — dikonfirmasi pemilik 14 Sep 2026

Pemilik menyatakan mekanisme penyedianya lebih spesifik daripada "tidak
mendukung refund": **SumoPod hanya mendukung *withdraw* ke akun utama.** Uang
yang sudah terkumpul tidak bisa dikembalikan ke sumbernya, dan tidak bisa
dikirim langsung ke pelanggan dari sana.

Jadi satu refund adalah dua perpindahan, keduanya manual, keduanya di luar
sistem ini:

```
SumoPod  --withdraw-->  rekening utama Makam.co.id  --transfer-->  pelanggan
```

Tiga konsekuensi yang tidak terlihat kalau jalurnya dianggap satu hop:

1. **Tenggat 3 hari kerja harus menyerap waktu settlement withdraw**, yang
   bukan milik kita dan tidak kita kendalikan. Kalau withdraw memakan dua hari
   kerja, operator punya satu hari untuk sisanya. Tenggatnya tetap 3 hari kerja
   — yang berubah adalah berapa banyak dari angka itu yang benar-benar tersedia
   bagi manusia yang mengerjakannya.
2. **Kewajiban bisa terutang sementara uangnya belum ada di rekening yang bisa
   mentransfer.** Buku kewajiban R0 mencatat utangnya sejak penolakan; ia tidak
   tahu, dan tidak perlu tahu, di mana dananya berada saat itu. Yang perlu tahu
   adalah operator, dan R4 harus berisik sebelum tenggatnya lewat, bukan pada
   saat lewat.
3. **Ini menjadikan R2 keadaan permanen, bukan jembatan** — kecuali dan sampai
   pertanyaan penyedia di bawah dijawab dengan perpindahan. Pertanyaan itu tetap
   terbuka; yang tertutup hanyalah harapan bahwa penyedia sekarang bisa
   menutupnya.

Antarmuka admin: daftar kewajiban terutang diurut tenggat, aksi "catat eksekusi"
yang mewajibkan jumlah, tanggal, rujukan transfer, dan unggahan bukti. Aksi ini
masuk `SensitiveActions` — beralasan wajib, teraudit.

> **Konsekuensi yang tidak menyenangkan dan harus dinyatakan:** pembayaran
> masuk lewat QRIS/e-wallet, tapi refund manual keluar lewat transfer bank. Itu
> berarti **meminta nomor rekening kepada keluarga yang sedang berduka**, pada
> saat terburuk, lewat saluran yang harus aman. Ini bukan detail implementasi —
> ini pengalaman pelanggan yang diciptakan oleh kombinasi "online mutlak" dan
> "penyedia tanpa refund". Ia hilang begitu ada penyedia yang bisa refund ke
> sumber aslinya.

### Tahap R3 — Jurnal

`RecordRefund` hari ini sengaja tidak memanggil `Journal::postReversal()`.
Setelah kewajiban punya status dan eksekusi punya bukti, pembalikan jurnal
menempel pada **eksekusi**, bukan pada keputusan — uang baru bergerak di buku
ketika ia benar-benar bergerak.

### Tahap R4 — Yang terlambat harus berisik

**Tidak lagi terblokir** — tenggatnya 3 hari kerja, jadi "terlambat" sudah punya
arti yang bisa dihitung.

Kewajiban yang lewat tenggat harus muncul di tempat yang dilihat orang, bukan
hanya di tabel yang harus dibuka. Repo ini punya `spine:watchdog` dan widget
antrean tinjauan manual di panel admin; kewajiban terlambat masuk ke sana.

Kewajiban yang diam adalah kewajiban yang dilupakan, dan yang menanggung
lupanya adalah keluarga yang sudah membayar.

### Tahap R5 — Antarmuka `refund()`, tanpa implementasi palsu

Perluas `PaymentCheckoutClient` dengan `refund()`. **Antarmuka tanpa
implementasi tidak boleh dibaca sebagai kemampuan refund yang sudah ada** —
implementasi SumoPod melempar "tidak didukung", eksplisit, bukan diam-diam
gagal.

## Yang sebenarnya menyelesaikan ini

Semua di atas membuat keadaan sekarang **bisa ditanggung**. Yang
**menyelesaikannya** adalah penyedia pembayaran yang mendukung refund API untuk
QRIS dan e-wallet — dan riset di repo ini sudah mencatat bahwa Midtrans dan
Xendit keduanya mendukungnya untuk rail tersebut.

Selama itu belum ada, setiap refund adalah pekerjaan tangan manusia dengan
tenggat, dan sistem ini ada untuk memastikan pekerjaan itu tidak pernah
terlewat.

Rencana ini tidak mengusulkan pindah penyedia — itu keputusan pemilik, dengan
konsekuensi komersial yang ada di luar jangkauan dokumen ini. Ia hanya mencatat
bahwa selama penyedianya tidak bisa refund, biaya refund dibayar dengan waktu
operator dan risiko kelalaian, bukan dengan kode.

## Yang harus diputuskan pemilik

- ~~**Berapa tenggat eksekusi refund?**~~ **Terjawab 13 Sep 2026: 3 hari
  kerja.** R4 tidak lagi terblokir.
- **Penolakan pesanan terbayar: status baru, atau tindakan kompensasi?**
  Memblokir R1 sepenuhnya — lihat §Tahap R1. Ini satu-satunya hal yang
  menghentikan pembangunan malam ini.
- **Apakah hari libur nasional dilewati juga?** Implementasi pertama tidak
  melewatinya, dan alasannya ada di §Tenggat di atas. Menjawab "ya" menuntut
  sumber kalender resmi.
- **Berapa lama admin boleh menahan konfirmasi** sebelum pesanan otomatis
  ditolak dan direfund? Uang pelanggan tertahan selama itu.
- **Siapa menanggung biaya gateway?** Sebagian besar gateway tidak
  mengembalikan MDR-nya saat refund. Tiap penolakan adalah kerugian nyata.
- **Bagaimana nomor rekening pelanggan dikumpulkan dan disimpan?** Data pribadi
  baru, di alur yang sudah sensitif. Menyentuh privasi — butuh review manusia.
- **Apakah pindah ke penyedia yang mendukung refund sedang dipertimbangkan?**
  Jawabannya menentukan apakah Tahap R2 adalah jembatan pendek atau keadaan
  permanen. **Masih terbuka.** Yang sudah tertutup hanyalah setengahnya:
  pemilik mengonfirmasi 14 Sep 2026 bahwa SumoPod hanya mendukung *withdraw ke
  akun utama*, jadi penyedia sekarang **tidak akan** menutupnya (lihat §Tahap
  R2). Sampai pertanyaan ini dijawab, perlakukan R2 sebagai keadaan permanen —
  itu asumsi yang lebih aman dari dua-duanya, karena ia menuntut antarmuka
  operator yang layak dipakai berulang, bukan sekadar ditahan sebentar.

## Urutan yang mengikat

```
R0 buku kewajiban → R1 penolakan menciptakan kewajiban → R2 eksekusi + bukti
   → R3 jurnal → R4 yang terlambat berisik → R5 antarmuka
```

Dan dari rencana bayar-di-muka: **R0–R4 selesai → Tahap 4 → baru Tahap 5**
(menghapus jalur manual). Sampai R0–R4 ada, bayar-di-muka hanya boleh berjalan
berdampingan dengan jalur manual, tidak menggantikannya.
