# Rencana: UAT end-to-end seluruh perjalanan pengguna, lewat Chrome

**Status:** Rencana. Belum satu langkah UAT pun dijalankan.
**Tanggal:** 13 September 2026
**Instrumen:** Chrome milik pemilik, lewat ekstensi (host ini tidak punya Chrome)

## Tiga temuan yang membentuk rencana ini, bukan sekadar melatarinya

Ketiganya diverifikasi malam ini terhadap lingkungan yang hidup, bukan
disimpulkan dari dokumen.

### 1. UAT TIDAK BOLEH dijalankan di makam.co.id sebelum tiga perbaikan mendarat

Ini bukan kehati-hatian umum. Menjalankan UAT pemesanan di beta **hari ini**
akan secara aktif menyebabkan kerusakan yang audit malam ini temukan:

| Yang terjadi | Kenapa |
|---|---|
| Email berisi catatan routing internal terkirim ke alamat sungguhan | `MailChannel` diikat di beta sejak 21:29 WIB, dan **18 dari 20 template masih placeholder**. Sembilan template terikat event, kesembilannya placeholder — termasuk *pemesanan dikirim* dan *penawaran diterbitkan*, yang **tidak menunggu pembayaran** |
| Sesi pembayaran sandbox yang tampak sungguhan | `G-PAY-01` terbuka sementara `PAYMENT_PROVIDER` tidak diset, jadi produksi jatuh ke `sumopod-sandbox` **karena default** |
| Pesanan uji menempel pada katalog fiktif | 9 dari 10 pemakaman terbit, nol berpenanda demo |

**Sasaran UAT adalah `dev.makam.co.id`.** Diverifikasi aman untuk ini:
`NOTIFICATION_CHANNEL` = `LogChannel`, `MAIL_MAILER` = `log`. **Tidak ada
email yang keluar dari dev.**

Beta baru boleh di-UAT setelah tiga tindakan satu-sentuhan di laporan
audit dijalankan — dan bahkan lalu, jalur pembayarannya tetap sandbox.

### 2. Lima belas dari tujuh belas feature gate TERTUTUP — dan itu bukan kerusakan

Ini temuan terpenting untuk validitas UAT-nya, dan yang paling mudah
disalahartikan.

Gate di sistem ini **tidak mematikan alur; ia mengganti MODE-nya.**
Diverifikasi di `ModeResolver`:

| Gate | Tertutup berarti |
|---|---|
| `G-LEGAL-01` | Pre-need **mendaftarkan minat**, bukan menerima pembayaran |
| `G-DATA-01` (terbuka) | Pencarian makam aktif |
| `G-OPS-01` | Kapasitas **tidak diketahui**, bukan menerima permintaan |
| `G-MEM-01` | Memorial **tidak tersedia**, bukan publik |
| `G-WA-01` | Fallback **email/in-app**, bukan WhatsApp |
| `G-PAY-01` (terbuka) | Pembayaran online aktif |

**Konsekuensi untuk UAT:** menguji sebuah perjalanan dalam mode yang
**tidak** dikonfigurasi lalu melaporkannya rusak adalah **temuan palsu.**
Oracle-nya bukan "apakah fiturnya bekerja" melainkan **"apakah ia berperilaku
sesuai mode yang benar-benar aktif, dan apakah ia mengatakannya dengan
jujur kepada pengguna."**

Maka tahap pertama UAT bukan menguji apa pun — melainkan **memetakan mode**.

### 3. Saya tidak bisa login, dan itu membelah cakupannya

Saya dilarang memasukkan kata sandi. Itu bukan preferensi; itu batas yang
tidak saya akali.

Dev sudah memuat akun UAT dari sesi sebelumnya (`uat-admin-*`,
`uat-customer-*`, `uat-vendor-*`, `e2e-admin@example.test`) tetapi **saya
tidak memegang dan tidak boleh memegang kata sandinya.**

Jadi cakupannya terbelah:

- **Jalur keluar-akun — saya jalankan sendiri, penuh.** Sekitar 35 rute GET
  publik, wizard pemesanan sampai titik autentikasi, direktori, marketplace,
  perpanjangan, FAQ, memorial, sertifikat, kwitansi.
- **Jalur masuk-akun — Anda autentikasi, saya lanjutkan.** Empat panel
  Filament (Admin, Operator, Vendor, Support) dan sembilan peran aktor.
  Anda login sekali per peran; saya menyetir dari sana.

Alternatifnya: Anda memakai pengelola kata sandi sendiri, dan saya tidak
pernah melihat nilainya.

## Inventaris perjalanan

Diturunkan dari `routes/web.php` (43 rute) dan lima panel Filament, bukan
dikarang.

### A. Pengunjung tanpa akun (saya jalankan sendiri)

1. **Beranda → direktori → detail pemakaman** — `/`, `/pemakaman`,
   `/pemakaman/{slug}`
2. **Wizard pemesanan, empat langkah** — `/pemesanan-makam`
   (Discovery → Data pemesan & jenazah → Pembayaran → Konfirmasi)
3. **Perpanjangan** — `/perpanjangan`, `/cari`, `/pembayaran`, `/konfirmasi`
4. **Marketplace** — daftar, produk, keranjang, checkout, lacak pesanan
5. **Kunjungan** — `/kunjungan`, `/kunjungan/{slug}`
6. **Pre-need** — `/preneed` (mode **minat saja**, bukan pembayaran)
7. **Memorial & QR** — `/m/{token}`, `/kenangan/{profileId}`
   (mode **tidak tersedia** — periksa apakah ia mengatakannya dengan jujur)
8. **Dokumen publik** — `/sertifikat/...`, `/kwitansi/{reference}`
9. **Statis & bantuan** — FAQ (3 rute), `/privasi`, `/syarat-ketentuan`,
   `/bantuan`
10. **Akun** — `/masuk`, `/daftar`, `/lupa-password` **sampai** titik
    kredensial, tidak melewatinya

### B. Perlu autentikasi Anda

11. **Admin** — konfirmasi/penolakan pesanan, verifikasi pembayaran,
    penerbitan sertifikat, pengaturan situs, kota peluncuran
12. **Operator TPU/TPS** — pesanan pemakamannya, peta petak
13. **Vendor** — pesanan, unggah bukti, riwayat transaksi
14. **Pelanggan masuk-akun** — draft, pesanan, perpanjangan, dokumen

## Oracle: apa yang dihitung sebagai kegagalan

Tanpa ini, UAT hanya menghasilkan tangkapan layar.

1. **HTTP 500 mana pun** adalah kegagalan. Repo ini punya pola tertulis
   *"degrade honestly instead of 500ing"* — dan temuan UXO-01 mencatat tiga
   rute yang masih melanggarnya.
2. **Mode yang dinyatakan salah.** Jika `G-MEM-01` tertutup, memorial harus
   **mengatakan** ia tidak tersedia — bukan kosong, bukan error.
3. **Sepuluh state layar wajib** dari `design-system.md` §6 — khususnya
   loading, empty, dan error. Layar yang hanya punya happy path gagal.
4. **Placeholder yang terlihat pengunjung** — hotline fiktif
   `+62 812-0000-1234`, `PT Contoh Makam Digital Indonesia`,
   `Jl. Contoh Cendana No. 88` masih terender di setiap halaman.
5. **Kontradiksi antar-layar** — FAQ beranda menyebut "sembilan langkah"
   sementara wizard satu klik jauhnya berkata "Langkah 1 dari 4".
6. **Corong yang buntu** — "Sukabumi" adalah opsi pertama di wizard dan
   tidak punya satu pun pemakaman. (Diperbaiki di #304, belum di-merge.)

## Tahapan

### Tahap 0 — Peta mode, sebelum menguji apa pun

Baca state keenam mode dari halaman yang hidup, bukan dari database.
Keluarannya: tabel mode-per-perjalanan yang jadi **oracle** untuk semua
tahap berikutnya. Tanpa ini, setiap temuan mode adalah tebakan.

### Tahap 1 — Sapuan rute keluar-akun

Ke-35 rute GET publik, lebar bukan dalam. Kode status, judul, dan bukti
halaman benar-benar terender. Menangkap 500 dan halaman kosong lebih dulu,
karena itu memblokir tahap berikutnya.

**Termasuk id yang salah ketik** pada setiap rute berparameter — itu
temuan UXO-01, dan tiga rute masih 500.

### Tahap 2 — Perjalanan mendalam, keluar-akun

Wizard pemesanan sampai titik pembayaran. Perpanjangan. Marketplace sampai
checkout. Masing-masing pada **360×740 dan 1280×900** — mobile lebih dulu,
karena audit desain menyebutnya kasus utama dan menemukan kerusakan terbesar
di sana.

**Berhenti sebelum mengirim apa pun yang membuat sesi pembayaran.**
Pembayaran dev adalah sandbox, tapi ia tetap menulis baris nyata.

### Tahap 3 — Perjalanan masuk-akun

Butuh Anda hadir untuk autentikasi. Empat panel, empat peran minimum.

Jalur terpenting: **admin mengkonfirmasi lalu menolak pesanan** — yaitu
alur yang seluruh pekerjaan malam ini bangun, dan yang **belum pernah
dijalankan seorang manusia pun.**

### Tahap 4 — Kompilasi

Setiap kegagalan dengan: rute, viewport, tangkapan layar, dan **klasifikasi
apakah ia melanggar oracle atau hanya berbeda dari harapan.** Perbedaan itu
yang mencegah UAT menghasilkan daftar keinginan.

## Yang TIDAK akan dilakukan

- **Tidak ada UAT di makam.co.id** sebelum tiga tindakan satu-sentuhan
  mendarat. Alasannya di §1 dan konkret.
- **Tidak memasukkan kata sandi.** Batas yang tidak saya akali.
- **Tidak menyelesaikan pembayaran**, bahkan di sandbox.
- **Tidak menguji panel admin di beta.** Aksi admin di sana menyentuh data
  produksi.

## Yang harus diputuskan sebelum Tahap 3 dijadwalkan

- **Bagaimana autentikasi ditangani?** Anda hadir dan login per peran, atau
  pengelola kata sandi Anda.
- **Apakah Tahap 3 dijalankan di dev dengan gate apa adanya, atau sebagian
  gate dibuka dulu?** Menguji mode yang tidak akan pernah dipakai pengguna
  adalah pemborosan; menguji hanya mode sekarang berarti alur bayar-di-muka
  tidak pernah diuji sama sekali sampai ia dikirim.

---

# Tahap 0 SUDAH DIJALANKAN — hasilnya di bawah

Dijalankan lewat Chrome terhadap `dev.makam.co.id`, 13 Sep 2026. Ini bukan
rencana; ini pengukuran.

## Mode: ketiganya menyatakan diri dengan jujur

| Perjalanan | Gate | Yang halaman katakan | Vonis |
|---|---|---|---|
| Pre-need | `G-LEGAL-01` tertutup | *"Saat ini layanan pra-pesan belum dapat diaktifkan; daftarkan minat atau minta konsultasi."* | **LULUS** |
| Memorial (uuid sah, tak ada) | `G-MEM-01` tertutup | *"Memorial tidak tersedia"* + arahan ke keluarga pengirim | **LULUS** |
| Urgent | `G-OPS-01` tertutup | *"Ketersediaan Urgent Belum Dapat Dipastikan Otomatis"*, dengan alasannya | **LULUS** |

Ketiganya mengatakan **apa yang tidak bisa mereka lakukan**, bukan kosong
dan bukan error. Itu justru yang paling sering gagal, dan di sini benar.

## Satu kegagalan hidup, dan dua yang ternyata sudah benar

| Rute, id salah ketik | Hasil | Vonis |
|---|---|---|
| `/kenangan/not-a-uuid` | **"Terjadi kesalahan pada sistem kami"** | **GAGAL** |
| `/sertifikat/order/not-a-uuid` | "Halaman tidak ditemukan" | LULUS |
| `/pemesanan-makam/draft/not-a-uuid` | Wizard terender di langkah 1 | LULUS |

Kegagalannya spesifik dan bukan sekadar kode status: **uuid yang sah tapi
tidak ada** memberi *"Memorial tidak tersedia"* — benar. **Uuid yang salah
bentuk** memberi *"Terjadi kesalahan pada sistem kami."*

Untuk keluarga yang mengikuti tautan memorial dari kerabat, itu pesan yang
salah: ia menyiratkan **kesalahan kami** dan mengundang mencoba lagi,
padahal tautannya yang tidak sah. Ini UXO-01, dan **#304 memperbaikinya**
tetapi belum di-merge.

## Dua temuan audit terkonfirmasi hidup di depan mata

- **Hotline fiktif `+62 812-0000-1234` terender DUA KALI di beranda** —
  di banner Urgent dan di bagian bantuan.
- **UXO-02 nyata**: FAQ beranda berkata pemesanan adalah *"alur booking
  online **sembilan langkah**"*, sementara wizard satu klik jauhnya berkata
  **"Langkah 1 dari 4"**.

## KOREKSI terhadap laporan audit saya sendiri

Saya melaporkan bahwa data contoh "terlihat tanpa label". **Itu terlalu
keras.** Yang sebenarnya terjadi lebih spesifik:

- Harga **berlabel**: *"Sumber: Estimasi internal (data contoh)"*
- Alamat **berlabel sendiri**: *"Jl. Contoh Flamboyan No. 6"*
- **Nama pemakaman TIDAK berlabel** — "TPU Bekasi Jatiasih" terbaca nyata
- **`operator_name` tidak berlabel** dan menamai instansi kota
- **`demo_batch_id` NULL**, sehingga `demo-data:purge` tidak menjangkaunya

Jadi separuh pelabelan **ada**. Yang hilang adalah pada nama dan operatornya
— dan penanda batch yang membuatnya bisa dibersihkan.

Dan satu ketegangan yang layak dilihat pemilik: beranda memuat bagian
berjudul **"Jujur soal keterbatasan"** yang berbunyi *"Kami tidak mengarang
data, tarif, atau ketersediaan yang belum dapat kami pastikan"* — beberapa
ratus piksel di bawah enam kartu pemakaman fiktif yang operatornya bernama
seperti instansi pemerintah kota.

Klaimnya benar tentang **tarif**. Ia tidak benar tentang **katalognya**.

---

# Tahap 1 SUDAH DIJALANKAN — 30 rute, termasuk dimensi bahasa

Terhadap `dev.makam.co.id`, 13 Sep 2026. Tiap rute berparameter diuji **dua
kali**: id yang sah-tapi-tidak-ada, dan id yang salah bentuk.

## Satu kegagalan. Satu, di tiga puluh rute.

```
500  /kenangan/not-a-uuid        "Terjadi kesalahan pada sistem kami"
```

Itu satu-satunya HTTP 500 di seluruh sapuan. Bandingkan dengan saudaranya
yang benar:

| Masukan | Hasil |
|---|---|
| `/kenangan/<uuid sah, tak ada>` | 200 — *"Memorial tidak tersedia"* |
| `/kenangan/not-a-uuid` | **500 — "Terjadi kesalahan pada sistem kami"** |
| `/m/<token tak ada>` | 200 — *"Memorial tidak tersedia… hubungi mereka untuk memastikan kode masih berlaku"* |

Perhatikan salinan `/m/` berkata **"kode"**, bukan "tautan" — tepat untuk
konteks QR. Itu disiplin salinan yang baik, dan justru membuat 500-nya lebih
menonjol: jalur yang sama, ditulis dengan hati-hati, kecuali satu cabang.

**#304 memperbaikinya.** Belum di-merge.

## Semua yang lain merosot dengan jujur

Sepuluh rute berparameter dengan id palsu mengembalikan 404 bernama
Indonesia atau state kosong di dalam halaman. Tidak ada yang bocor, tidak
ada yang kosong tanpa penjelasan:

- `/marketplace/pesanan/<tak ada>` → 200, *"Pesanan tidak ditemukan.
  Periksa kembali nomor pesanan Anda."* — state kosong, bukan 404. Tepat.
- `/kwitansi/MK-2026-C6RPDKX7` → 404. **Dan itu benar**: `order_invoices`
  punya **nol baris** di seluruh dev, dan pesanan itu berstatus `MASUK`.
  Tidak ada invoice, jadi tidak ada kwitansi.

Temuan sampingan dari situ, dan ia menguatkan prasyarat Tahap 3 rencana
bayar-di-muka: **nol invoice di dev berarti tidak satu pun pesanan pernah
menyelesaikan jalur terbayar.** Sumber jumlah refund belum pernah dilatih
sama sekali.

## Bahasa

**Navigasi dan judul konsisten berbahasa Indonesia, dan cocok satu sama
lain** — nav berbunyi "Pemesanan Makam · Layanan Pemakaman · Perpanjangan
Makam · FAQ · Masuk", dan tiap judul halaman memakai istilah yang sama.
Tidak ada kebocoran kata Inggris di badan salinan mana pun.

**Satu pengecualian: "Checkout".**

```
/marketplace/checkout   judul: "Checkout - Layanan Pemakaman"
                        H1:     "Checkout"
                        badan:  "Ringkasan pesanan · Data penerima ·
                                 Buat pesanan · Keranjang Anda kosong"
```

Semua di sekitarnya bahasa Indonesia — produk ini bahkan menolak "cart"
demi "Keranjang". Satu-satunya layar dalam perjalanan itu yang judulnya
Inggris. "FAQ" dan "Pre-Need" juga pinjaman, tapi keduanya istilah domain
yang lazim; "Checkout" punya padanan yang sudah dipakai produk ini sendiri.

## Placeholder yang terlihat pengunjung, terhitung

| Yang bocor | Di mana |
|---|---|
| `PT Contoh Makam Digital Indonesia` | **setiap halaman** (footer) |
| `+62 812-0000-1234` | beranda (**dua kali**), `/bantuan`, artikel FAQ |
| *"sembilan langkah"* vs wizard "Langkah 1 dari 4" | beranda, `/faq`, artikel FAQ |

Kontradiksi sembilan-versus-empat itu muncul di **tiga halaman**, bukan
satu — termasuk artikel FAQ yang judulnya persis *"Bagaimana cara memesan
makam?"*, yaitu halaman yang paling mungkin dibaca orang yang benar-benar
ingin memesan.

## Vonis Tahap 1

Tiga puluh rute, **satu kegagalan**. Pola *"degrade honestly instead of
500ing"* benar-benar ditegakkan di mana-mana kecuali satu cabang. Salinannya
konsisten, spesifik konteks, dan tidak mengarang.

Yang merusak kesan itu bukan cacat teknis melainkan **data**: nama badan
usaha fiktif di setiap halaman, hotline fiktif di empat tempat, dan satu
kontradiksi jumlah langkah yang terbaca oleh orang yang paling serius ingin
memesan.

---

# Tahap 2 & 3 DIJALANKAN — dan Tahap 4, kompilasinya

## Catatan instrumen, dinyatakan lebih dulu

Jendela **tidak bisa turun ke 360px** — viewport terrender **606×597**.
Chrome punya lebar minimum, dan emulasi perangkat butuh CDP yang host ini
tidak punya. **Jadi semua temuan visual di bawah adalah pada ~606px, bukan
360px.** Temuan hero mobile dari audit desain tidak bisa saya ulangi di sini;
itu butuh pengukuran dari sesi yang punya emulasi.

## Tahap 2 — perjalanan mendalam

### Wizard pemesanan

| Yang diperiksa | Hasil |
|---|---|
| Indikator langkah | "Langkah 1 dari 4" + bilah kemajuan — benar |
| Klik kota **pertama** (Sukabumi) | *"Belum ada TPU/TPS terdaftar di kota ini"* + jalan keluar |
| Klik Jakarta | Dua TPU/TPS, paket/kelas, tautan peta petak — bekerja |
| Draft id salah bentuk | Terender di langkah 1, tidak 500 |

**Harga yang dilihat pelanggan**, dan ini prasyarat keras rencana
bayar-di-muka dalam bentuk yang bisa dilihat:

```
Rp 12.000.000 - Rp 22.000.000
Sumber: Estimasi internal (data contoh) · per 08/08/2026
Kisaran indikatif, Perlu konfirmasi.
```

**Rentang tidak bisa ditagih.** Setiap kartu membawa lencana "Perlu
konfirmasi". Tahap 0 rencana A ada persis untuk ini.

### Perpanjangan — tiga langkah, dan empty state yang LEBIH BAIK

`/perpanjangan` punya **"Langkah 1 dari 3"**, dan **Sukabumi pertama di sini
juga** — jadi corong buntu itu ada di **dua alur**, bukan satu. #304
memperbaiki wizard dan direktori; **periksa apakah ia mencakup perpanjangan.**

Tapi salinan kosongnya lebih baik daripada wizard pemesanan:

> *"Belum ada TPU/TPS terdaftar di Sukabumi. Data TPU/TPS untuk kota ini
> belum lengkap di sistem kami. **Ini tidak berarti tidak ada TPU/TPS di
> Sukabumi — hanya belum terdaftar di sini.** Silakan pilih kota lain, atau
> hubungi Bantuan."*

Kalimat yang ditebalkan itu mencegah pengguna menyimpulkan sesuatu yang
salah **tentang dunia nyata** dari ketiadaan **di data kami**. Itu prinsip
"Jujur soal keterbatasan" diterapkan dengan benar, dan wizard pemesanan
tidak mengatakannya.

### Marketplace — model untuk katalog pemakaman

Sembilan produk, **semuanya berlabel**: `CV Berkah Karangan Bunga (vendor
contoh)`, `Estimasi internal (data contoh)`. Empty state keranjang lengkap
dengan aksi: *"Keranjang Anda masih kosong… Lihat katalog."*

**Marketplace melabeli data contohnya dengan benar dan katalog pemakaman
tidak.** Itu perbandingan yang bisa langsung dipakai: polanya sudah ada di
repo ini, hanya belum diterapkan ke pemakaman.

### Foto kartu — state loading yang hilang

Sembilan foto punya `loading="lazy"` dan `alt` yang benar
(*"Foto TPS Jakarta Kemang"*), tetapi **tidak punya `width`/`height`**.
Server mengirimnya dalam **60–70 ms**, jadi ini bukan masalah jaringan —
ia sembilan JPEG ~220 KB yang didekode bersamaan.

Akibatnya: **area foto kosong selama beberapa detik**, tanpa skeleton atau
placeholder. Tinggi kartu tidak melompat (CSS menahannya), jadi bukan
pergeseran tata letak — melainkan **state Loading yang design-system §6.1
wajibkan dan tidak ada.**

## Tahap 3 — dinding autentikasi

**Tidak ada kebocoran.** Semua sesuai harapan:

```
/admin     302 -> /admin/login        /akun            302 -> /masuk
/operator  302 -> /operator/login     /akun/draft      302 -> /masuk
/vendor    302 -> /vendor/login       /akun/pesanan    302 -> /masuk
                                      /akun/dokumen    302 -> /masuk
                                      /akun/perpanjangan 302 -> /masuk
```

Ketiga halaman login: 200.

**Koreksi terhadap rencana ini sendiri:** saya menulis "lima panel Filament".
Salah. `app/Filament/Support/` dan `Shared/` adalah direktori kelas bantu
(`OrderViewUrl`, `CemeteryOrderActionGate`) — bukan panel. **Ada tiga panel**:
Admin, Operator, Vendor.

**Di sinilah UAT berhenti.** Isi ketiga panel butuh kata sandi, dan itu batas
yang tidak saya akali. Jalur terpenting yang belum diuji siapa pun:
**admin mengkonfirmasi lalu menolak pesanan terbayar** — alur yang seluruh
pekerjaan malam ini bangun.

## Tahap 4 — kompilasi

### Melanggar oracle

| # | Temuan | Bukti |
|---|---|---|
| 1 | `/kenangan/<id salah bentuk>` → **HTTP 500** | Satu-satunya 500 di 30 rute. #304 memperbaikinya, belum di-merge |
| 2 | Sukabumi pertama di **dua** corong | Pemesanan **dan** perpanjangan |
| 3 | State Loading hilang pada foto kartu | 9 gambar tanpa `width`/`height`, area kosong beberapa detik |
| 4 | `PT Contoh Makam Digital Indonesia` | **setiap halaman** |
| 5 | `+62 812-0000-1234` | beranda (2×), `/bantuan`, artikel FAQ |
| 6 | "sembilan langkah" vs "Langkah 1 dari 4" | 3 halaman, termasuk FAQ *"Bagaimana cara memesan makam?"* |
| 7 | Katalog pemakaman tanpa label contoh | Sementara marketplace melabelinya dengan benar |

### Berbeda dari harapan, tapi BUKAN pelanggaran

- **"Checkout"** satu-satunya judul berbahasa Inggris. Produk ini menolak
  "cart" demi "Keranjang", jadi ia menonjol — tapi ia pinjaman yang lazim.
  Keputusan salinan, bukan cacat.
- **Harga sebagai rentang** — benar untuk mode sekarang, dan berlabel jujur.
  Ia jadi cacat hanya setelah bayar-di-muka mendarat.
- **Tiga mode gate tertutup** menyatakan diri dengan jujur. Lulus.

### Yang TIDAK bisa diuji, dinyatakan bukan didiamkan

- **Isi tiga panel admin** — butuh kata sandi.
- **360px sungguhan** — jendela tidak bisa turun ke sana; butuh emulasi CDP.
- **Jalur pembayaran sampai selesai** — sengaja dihentikan sebelum membuat
  sesi.
- **Alur bayar-di-muka** — belum ada produsernya, dan `order_invoices`
  **nol baris di seluruh dev**.

### Empat kali saya menyimpulkan terlalu cepat, dan mengoreksinya

Layak dicatat karena ini persis mode kegagalan yang UAT ada untuk
menangkapnya, dan saya melakukannya sendiri:

1. `ls | head -4` → "foto hilang". **Salah** — berkasnya ada, semuanya 200.
2. Screenshot terlalu cepat → "gambar rusak". **Salah** — ia sedang memuat.
3. `grep` tiga `<img>` pertama → "tanpa lazy-loading". **Salah** — kesembilan
   foto punya `loading` dan `alt`.
4. Klik `ref_4` mengenai tombol menu → "perpanjangan buntu tanpa pesan".
   **Salah** — empty state-nya justru yang terbaik di seluruh situs.

Keempatnya dari bertindak atas pengamatan **parsial atau terlalu dini**.
Keempatnya terkoreksi hanya karena diukur ulang.

---

## Tahap 5 — Perjalanan pengguna interaktif A1–A10 (14 Sep 2026)

Tahap 0–4 memeriksa **permukaan**: 30 rute, status, judul, penanda. Tahap 5
menjalankan **perjalanannya** di Chrome, sampai menulis baris nyata di dev —
draft, pesanan, sesi pembayaran, item keranjang.

**Yang dijalankan sampai tuntas:** A1 pemesanan makam (4 langkah, sampai
pesanan `MK-2026-2ZGKBMFI` terbit), A2 perpanjangan (3 langkah, sampai
redirect ke `pay-sandbox.sumopod.com` dan sesi Rp 4.000.000 tercatat),
A3 marketplace (filter → detail → keranjang → checkout), A4 direktori +
detail TPU, A5 kunjungan, A6 pre-need, A7 FAQ, A8 bantuan, A9 legal,
A10 memorial/sertifikat/kwitansi.

**23 temuan.** Empat High, sepuluh Medium, lima Low, dua LULUS yang layak
dicatat, satu keputusan pemilik, dan satu koreksi terhadap temuan Tahap 0.

### Empat temuan High

| # | Temuan | Bukti terkuat |
|---|---|---|
| A3-01 | Harga & vendor di daftar ≠ di detail, **kesembilan produk**, di **beta** | "Paket Bunga Tabur" Rp 175.000 → Rp 7.500.000 (42,9×) |
| A10-01 | Tiga rute publik **HTTP 500** pada ID tak berbentuk UUID, di dev **dan** beta | `SQLSTATE[22P02] invalid input syntax for type uuid` |
| A4-03 | Halaman TPU menjanjikan "tidak ada petak yang terkunci"; wizard mengunci petak dalam satu klik | `plot_tracking_mode` vs `cemetery_capability_profiles` |
| A7-01 | FAQ menjelaskan alur **sembilan langkah**; wizard punya **empat** | dua artikel terbit di beta |

A10-01 yang paling langsung bisa dikerjakan: idiom penjaganya sudah ada
di repo ini (`Str::isUuid()`, dua belas berkas), dan salah satu baris
yang benar berada **dua fungsi di atas** salah satu baris yang rusak
(`RenewalPayment.php:130` benar, `:168` tidak).

### Satu pola yang muncul dua kali, dan hanya terlihat karena dijalankan

Kedua perjalanan yang melibatkan uang meminta pengguna menekan tombol
bayar **tanpa satu angka pun di layar**:

- A1 langkah 3: `innerText.match(/Rp[\s ][\d.]+/g)` → `[]`
- A2 layar bayar: nominal **dan** nama almarhum sama-sama hilang; judulnya
  menyusut jadi "Perpanjangan masa sewa makam." — kalimat yang terbaca
  seperti terpotong di tempat nama seharusnya berada

Datanya ada dan benar — sesi pembayaran yang dikirim ke penyedia tercatat
`amount_minor = 400000000` (Rp 4.000.000), persis sesuai tarif. Ia hanya
tidak dirender pada layar tempat keputusan diambil.

Ini tidak muncul di Tahap 0–4 karena probe rute tidak pernah sampai ke
langkah 3.

### Temuan Tahap 0 yang dikoreksi

Baris 1 tabel Tahap 4 berbunyi: *"`/kenangan/<id salah bentuk>` → HTTP 500.
Satu-satunya 500 di 30 rute. #304 memperbaikinya."*

Dua hal salah di situ. **Bukan satu-satunya** — ada tiga
(`/perpanjangan/pembayaran` dan `/perpanjangan/konfirmasi` dengan
`?perpanjangan=` tak berbentuk UUID juga 500, di kedua host). Dan
**#304 tidak memperbaikinya**: ketiganya masih 500 hari ini di dev dan
beta. Lihat A10-01.

### Dua LULUS yang layak dicatat

- **Idempotensi pesanan**, diverifikasi di basis data bukan di UI: dua
  klik "Bayar Sekarang" → satu baris `orders`, dijaga
  `idempotency_key='booking:<draft-id>'`, dan nol sesi pembayaran baru.
- **Kota tanpa data tidak disembunyikan**: Sukabumi tetap muncul di filter
  `/pemakaman` dengan empty state yang menjelaskan dan dua jalan keluar —
  persis yang diwajibkan aturan "NEVER filter this" pada
  `CemeteryPublicQuery::launchCities()`.

### Yang tetap tidak bisa diuji, dinyatakan bukan didiamkan

**B (akun), C (admin), D (operator), E (vendor) — 41 perjalanan — belum
diuji.** Kelimanya butuh masuk dengan kata sandi, dan memasukkan kata
sandi adalah batas yang tidak saya akali. Gerbangnya sendiri sudah
diverifikasi: kelima rute `/akun/*` dan ketiga panel mengalihkan ke
halaman masuknya masing-masing dengan benar.

Perjalanan A1 berhenti di langkah 4 karena alasan produk, bukan alasan
alat: pembayaran baru dibuka setelah admin mengonfirmasi ketersediaan.
Menyelesaikannya butuh satu tindakan admin.
