# Bahasa Desain kamboja.co.id untuk Makam.co.id — Rencana

> **Untuk agen pelaksana:** SUB-SKILL WAJIB: `superpowers:subagent-driven-development` atau
> `superpowers:executing-plans`. Setiap tahap memakai sintaks checkbox (`- [ ]`).
>
> **Dokumen ini adalah RENCANA, bukan implementasi.** PR yang memuatnya tidak boleh menyentuh
> satu pun file Blade, CSS, atau token.

**Tujuan:** Menjawab masukan pemilik produk — *"utk design makam nya nnti dibuat ky kamboja ajaa..
mgkn itu kelihatan msh plain krn aku blm ksh brand guideline sehingga blm ada corak2 warna nya
yaa"*, diperluas menjadi *"termasuk visual ui/ux, seperti layouting, images, dan semuanya"* —
dengan memisahkan dua hal yang berbeda: **bahasa desain** (ritme tata letak, grid, strategi citra,
suara tipografi, kepadatan, state layar, gerak, pola interaksi, cara kehangatan dibentuk) yang
boleh diambil dari kamboja.co.id, dan **identitas merek** (palet, logo, nama, nilai token) yang
tetap milik Makam.co.id dan sudah dikunci
[ADR-0034](../../adr/0034-adopt-makam-brand-identity.md).

**Cakupan:** seluruh permukaan visual dan interaksi publik — tata letak, grid, citra, komponen,
sepuluh state layar wajib, mobile, gerak, ikonografi, dan pola UX — bukan hanya palet dan tipografi.
Halaman yang diperiksa langsung: beranda, direktori TPU/TPS (`/pemakaman`), wizard pemesanan
(`/pemesanan-makam`), perpanjangan (`/perpanjangan`), marketplace (`/marketplace`).

**Temuan utama yang membentuk seluruh rencana ini:** keluhan "plain" **bukan** masalah palet.
Palet merek sudah ada, sudah disampling dari logo asli, dan sudah lulus WCAG AA untuk 49 pasang
warna. Keplainan berasal dari tiga mekanisme lain yang bisa dibuktikan baris demi baris — ritme
vertikal yang dipakai setengah dari yang diwajibkan design system sendiri, warna merek yang nyaris
tidak pernah menjadi *bidang* (hanya satu elemen di seluruh beranda), dan stok citra yang tipis
serta didaur ulang. Ketiganya dapat diperbaiki tanpa satu pun nilai warna berubah.

**Spec induk:** [`2026-08-21-brand-visual-refresh-design.md`](../specs/2026-08-21-brand-visual-refresh-design.md)
§5 Fase 3 ("Per-journey rollout") — fase itu belum punya dokumen rencana. Dokumen ini adalah
pendahulunya: perbaikan bahasa desain pada beranda lebih dulu, sebelum rollout per-journey.

---

## 0. Provenance bukti — baca sebelum mempercayai angka mana pun

`AGENTS.md` mewajibkan setiap klaim berasal dari sesuatu yang benar-benar dimuat. Berikut asal
setiap bukti di dokumen ini, termasuk yang cacat.

### 0.1 kamboja.co.id tidak dapat dijangkau dari host ini

`kamboja.co.id` **diblokir di tingkat DNS** dari host pengembangan. Bukti, semua jalur dicoba
13 Sep 2026:

| Jalur | Hasil |
|---|---|
| `getent hosts kamboja.co.id` | rc=2, tanpa jawaban (diulang 3×) |
| Resolver host (systemd-resolved → 202.152.0.2 / 202.152.5.36, resolver ISP Indonesia) | nama di-*drop*; resolver yang sama menjawab `makam.co.id` → 103.92.214.243 |
| `nslookup kamboja.co.id 1.1.1.1` dan `@8.8.8.8` | timeout — UDP/53 keluar ke resolver publik diblokir |
| Tailscale MagicDNS (100.100.100.100) | SERVFAIL |
| Chrome (browser-use) | `chrome-error://chromewebdata/`, teks `DNS_PROBE_FINISHED_NXDOMAIN` |
| WebFetch | `getaddrinfo ETIMEOUT kamboja.co.id` |
| DoH (cloudflare-dns.com dan dns.google) | **berhasil** — 104.21.25.42 / 172.67.222.172, di belakang Cloudflare |

Jadi domainnya hidup; host inilah yang tidak bisa meresolusinya. Konteks: `kamboja.id` meresolusi
ke `lamanlabuh.aduankonten.id` (182.23.79.195), yaitu halaman blokir Kominfo. Kata "kamboja" adalah
kata kunci yang diblokir luas di Indonesia karena SEO judi, jadi blokir ini tampak sebagai
kerusakan sampingan berbasis kata kunci, bukan penilaian atas situs ini.

**Tidak ada upaya melewati blokir DNS tersebut.** Permintaan `curl --resolve` ditolak oleh
classifier izin dan tidak diulangi dengan cara lain.

### 0.2 Dari mana bukti kamboja benar-benar berasal

Internet Archive. Dua tangkapan berbeda, dan keduanya diperlukan:

- **DOM halaman** — snapshot **10 Mei 2026** (`web.archive.org/web/20260510143025/`).
- **Stylesheet global situs** — `wp-content/uploads/oxygen/css/universal.css`, snapshot
  **30 Mei 2026**, 135.609 karakter setelah dekompresi.

Halaman arsip **tidak merender dengan benar sendirian**: seluruh CSS per-halaman Oxygen
(`2366.css`, `3466.css`, `7266.css`, `6711.css`, `6709.css`, `universal.css`) tertangkap dengan
`decodedBodySize = 0` dan `cssRules = 0`, sehingga halaman tampil nyaris tanpa gaya (tinggi dokumen
146.056px, heading pada ukuran default UA). Rekonstruksi yang dipakai: `universal.css` yang
terarsip dipasang ulang ke DOM yang terarsip — **944 aturan** kemudian berlaku, tinggi dokumen
turun ke 24.802px, dan `<h1>` meresolusi ke Nunito 45px/700.

**Batas bukti ini, dinyatakan terbuka:**

- Bukti berumur **±4 bulan**. Situs bisa saja sudah berubah.
- **CSS per-halaman hilang.** Karena itu *padding* tingkat-section, gambar latar hero (jika ada),
  dan warna final beberapa tombol **NOT VERIFIED**. Tombol yang terbaca `#1e73be` pada rekonstruksi
  adalah biru bawaan Oxygen (`.ct-link-button` default di `universal.css`), yang muncul justru
  *karena* CSS per-halaman hilang — hampir pasti bukan warna aslinya di situs hidup.
- Tidak ada tangkapan layar situs hidup, jadi tidak ada klaim "rasanya begini" yang berdiri di atas
  pengamatan langsung.

**Celah ini sebagian sudah ditutup** — lihat §0.3: tangkapan hidup desktop diperoleh 13 Sep 2026
melalui peramban pemilik. Bagian **mobile** masih terbuka (OQ-K1).

### 0.3 Tangkapan langsung situs hidup — 13 Sep 2026, **menutup sebagian celah §0.2**

Setelah bagian di atas ditulis, kamboja.co.id **berhasil ditangkap hidup** melalui Chrome milik
pemilik produk di jaringannya sendiri (ekstensi claude-in-chrome). Blokir DNS di §0.1 bersifat
lokal pada host pengembangan ini saja; peramban pemilik menjangkaunya normal.

Berkas tangkapan, diperiksa langsung untuk dokumen ini:
`/tmp/user/1000/claude-chrome-screenshots-NzS80z/screenshot-1789314614128-1.jpg` (hero, 1568×764),
`…-2.jpg` (baris kartu), `…-1789314643099-5.jpg` (testimoni).

**Apa yang ini ubah:** setiap klaim yang sebelumnya bertanda "rekonstruksi arsip" untuk hero,
tombol, kartu, dan testimoni kini **terverifikasi langsung** dan ditandai demikian di bawah. Yang
paling penting, ini **menyelesaikan ketidakcocokan §13.1 — dan menyelesaikannya berlawanan dengan
arsip, mendukung dokumen repo yang sudah ada.**

**Apa yang ini TIDAK ubah:** tangkapan hanya **desktop**. Upaya mengecilkan jendela ke 390×844
dilaporkan berhasil tetapi viewport terender tetap 1200px — Chrome menahan lebar jendela minimum,
sehingga hasilnya tata letak desktop pada jendela sempit, bukan viewport mobile. **Perilaku mobile
kamboja tetap NOT VERIFIED** (§1.4b), dan tidak disimpulkan dari tangkapan desktop. Separuh-mobile
OQ-K1 tetap terbuka.

### 0.4 Bukti makam.co.id

Langsung dari situs hidup `https://makam.co.id/` (meresolusi normal), dimuat di Chrome pada
viewport 1440×900, semua nilai dibaca dari `getComputedStyle` dan `getBoundingClientRect`, bukan
dari kode sumber. Status HTTP aset diperiksa terpisah. Kode yang dirujuk dibaca dari
`docs/design-system-and-planning` pada commit `7d3bcb81`.

---

## 1. Bahasa desain kamboja.co.id — apa adanya, dengan nilai tersampel

Situs: *"Kamboja.co.id - Proteksi dan pelayanan pemakaman | Proteksi dan pelayanan pemakaman
terintegrasi pertama di Indonesia"*. WordPress + page builder Oxygen. Produknya proteksi kedukaan
berjangka (premi mulai IDR 35.000/bulan, pertanggungan hingga IDR 100 juta) dan jasa pengurusan
kedukaan *on demand* (mulai IDR 15.000.000). Jadi ini memang pembanding langsung yang dimaksud.

### 1.1 Palet — magenta/ungu, dan itu seluruh identitasnya

Dihitung dari `universal.css` (angka = jumlah deklarasi):

| Hex | Jumlah | Peran terbaca |
|---|---|---|
| `#D23574` | 83 | magenta merek, dominan mutlak |
| `#BD3B9D` | 17 | stop gradien |
| `#96317D` | 17 | stop gradien tergelap |
| `#AB368F` | 16 | stop gradien |
| `#B44198` | 9 | stop gradien |
| `#8B116D` | 7 | stop gradien |
| `#263238` | 20 | teks (blue-grey gelap) |
| `#C4C4C4` | 18 | garis/pembatas |
| `#FFE7F1`, `#FFECF4` | 5, 2 | permukaan merah muda pucat |
| `#F9F7F7`, `#F8F8F8`, `#F6F8FA`, `#F4F7F9` | 9, 8, 5, 5 | permukaan netral |
| `#41B78A`, `#07BA28` | 5, 4 | aksen hijau |
| `#F70000` | 3 | aksen merah |
| `#1E73BE` | 8 | **bawaan Oxygen**, bukan warna merek |

Tanda tangan visualnya adalah **gradien empat-stop pada sudut miring**, berulang dalam beberapa
varian:

```
linear-gradient(336.59deg, #96317D -19.28%, #AB368F 3.34%, #BD3B9D 40.88%, #D23574 95.03%)
linear-gradient(318deg,    #96317D 0%,     #AB368F 19.79%, #BD3B9D 60.94%, #BA3E70 93.75%)
linear-gradient(314deg,    #D23574 4%,     #C93980 63%,    #B44198 97%)
linear-gradient(-90deg,    rgba(184,65,155,1) 0%,          rgba(186,62,112,1) 100%)
```

Gradien ini dipakai pada wordmark, tombol primer, dan bidang penuh. **Warna adalah cara utama situs
ini tidak terasa plain.**

### 1.2 Tipografi — Nunito, bulat dan ramah

`font-family: 'Nunito'` muncul 38 kali; `'Inter'` 4 kali; `Source Sans Pro` dan `Open Sans`
masing-masing sekali (sisa tema). Bobot: `700` mendominasi (26 deklarasi), lalu 400 (9), 500, 600.
`<h1>` terukur **Nunito 45px/700, `#263238`**. Skala ukuran yang muncul di stylesheet: 12, 14, 15,
16, 18, 20, 21, 24, 25, 28, 30, 40, 43.3, 45, 48, 80px. Body 16–18px.

Nunito adalah humanist sans dengan terminal membulat — pilihan yang secara sengaja melunakkan topik
berat. Ini setara peran `--font-display` Poppins di Makam.co.id, bukan lawannya.

### 1.3 Geometri — pil, blob, dan cahaya berwarna

| Properti | Nilai tersampel | Jumlah |
|---|---|---|
| Radius tombol | `border-radius: 60px` (terukur pada CTA hero) | — |
| Radius dominan | `border-radius: 10px` | 27 |
| Radius pil lain | `100px`, `30px`, `20px`, `15px` | 2, 2, 2, 2 |
| **Masker foto organik** | `border-radius: 54% 46% 63% 37% / 44% 58% 42% 56%` | 8 |
| **Masker foto organik ke-2** | `border-radius: 34% 63% 37% 66% / 45% 55% 46% 54%` | 8 |
| Bayangan CTA | `box-shadow: 0 4px 20px 0 rgb(210 53 116 / 30%)` | 9 |
| Bayangan kartu | `box-shadow: 4px 4px 15px 0 rgb(0 0 0 / 5%)` | 3 |
| Transisi | `transition: .3s` | 21 |

Dua hal pantas diberi nama. Pertama, **foto tidak pernah kotak** — ia dimasker ke bentuk *blob*
organik. Kedua, **bayangan CTA berwarna**, bukan netral: cahaya magenta 30% di bawah tombol. Itu
device kehangatan/energi, bukan elevasi.

CTA hero terukur:

```
"Proteksi Kedukaan Berjangka"  367×60  bg=linear-gradient(-90deg, #B8419B 0%, #BA3E70 100%)
                                       radius=60px  fg=#FFFFFF  18px/700
"Jasa Kedukaan"                252×56  bg=#F9F9F9  radius=60px  fg=#D23574  18px/700
```

### 1.4 Struktur halaman dan ritme

Urutan section dari DOM terarsip (tinggi = hasil render rekonstruksi, indikatif saja):

| # | Section | Tinggi | Gambar |
|---|---|---|---|
| 0 | Hero — *"Untuk Mereka, Jika Hari itu Tiba"* | 696 | 2 (logo Google Review + Trustpilot) |
| 1 | Dua kartu produk — Proteksi Berjangka & Jasa On Demand | 1.524 | 2 |
| 2 | Testimonials (judul + ajakan ulasan) | 284 | 0 |
| 3 | Layanan kedukaan lainnya | 10.165 | 10 |
| 4 | Didukung oleh — logo mitra asuransi | 750 | 4 |
| 6 | **Aktivitas Layanan Kamboja** — dokumentasi nyata | 370 | — |
| 7 | **Disclaimer foto** | 201 | 0 |
| 8 | Butuh pelayanan kedukaan segera? / Jasa Pengurusan | 1.824 | 4 |
| 9 | Proteksi Pemakaman Berjangka dari Kamboja | 2.073 | 7 |
| 10 | Kenapa kami? | 1.166 | 2 |
| 11 | FAQ | 2.240 | 0 |
| 12 | **Kamboja telah diliput oleh:** — logo pers | 1.215 | 41 |
| 13 | Testimoni + Rated Excellent 5.0/5.0 | 969 | 60 |
| 14 | Footer — CS + hotline 24/7 | 1.307 | 6 |

Breakpoint: `max-width: 479px` (33 aturan), `767px` (22), `991px` (17), `1120px` (7).

Yang perlu dicatat: **halaman ini panjang dan longgar.** Satu section bisa 2.000–10.000px. Tidak ada
usaha memadatkan.

### 1.4a Sistem kolom

`universal.css` memakai sistem flex sederhana, bukan grid 12-kolom:

| Deklarasi | Jumlah |
|---|---|
| `width: 100%` | 86 |
| `width: 50%` | 11 |
| `width: 33.33%` | 5 |
| `flex-direction: row` | 25 |
| `flex-direction: column` | 20 |

Jadi progresi kolomnya efektif **1 / 2 / 3** — sebanding dengan progresi `--container-content`
Makam di §4.3 design system (1 → 2 → 3 → 4 tergantung jenis konten). Tidak ada sesuatu yang perlu
diadopsi di sini; Makam sudah setara atau lebih rapi.

### 1.4b Perilaku mobile kamboja — **NOT VERIFIED**

Ini celah penting dan harus dinyatakan, karena brief menempatkan mobile sebagai kasus utama.

Di dalam `universal.css`, blok `@media (max-width: 479px)` hanya mengubah ±50 deklarasi, didominasi
`display` (8), `width` (4), `margin-top` (4), `background-color` (4), `font-size` (3). Itu terlalu
sedikit untuk menjelaskan bagaimana halaman benar-benar tersusun ulang di ponsel. Pekerjaan tata
letak mobile yang sesungguhnya hampir pasti berada di CSS per-halaman yang **tidak terarsip**
(§0.2).

**Karena itu tidak ada satu pun klaim tentang tata letak mobile kamboja di dokumen ini.** Tidak ada
navigasi bawah, pola sticky CTA, atau urutan section mobile yang bisa dibuktikan. Menebaknya akan
lebih berbahaya daripada mengosongkannya, karena pemilik akan bertindak atas dasar itu. Lihat
OQ-K1.

### 1.5 Strategi citra — dua jalur, dan salah satunya milik sendiri

Inventaris gambar dari DOM terarsip:

**Jalur A — ikon SVG datar, penuh warna merek:** `shield-star.svg`, `grave-stone.svg`,
`icon-surat-wasiat.svg` (131×130), `icon-wishlist.svg` (95×118),
`icon-pengurusan-pemakaman-white.svg`, `icon-uang-santunan-white.svg`. Besar, rata, magenta.

**Jalur B — fotografi dokumenter resolusi tinggi, milik sendiri:** `pengurusan-non-muslim.jpg`
(1440×1079), `cargo-jenazah.jpg` (1439×825), `tulisan-karangan-bunga-duka-cita.jpg` (1439×958),
`peti-mobile.jpg` (1300×751), `repatriasi.jpeg` (1059×750), `akte.jpg` (1086×692),
`sandiego-tps.jpg` (1439×825), `IMG-20230701-WA0047-copy.jpg` (sewa tenda kursi), `pemindahan.jpg`.

Dan yang paling penting, section 7 memuat **disclaimer di halaman itu sendiri**:

> *"Materi foto diatas adalah properti tim dokumentasi Kamboja dan telah mendapat persetujuan dari
> keluarga dan pihak terkait."*

Artinya: foto-foto itu **bukan stok**. Diambil tim mereka, dari layanan nyata, dengan izin
keluarga — dan mereka mengatakannya. Inilah sumber kehangatan dan kredibilitas yang paling sulit
ditiru, dan yang paling tidak bisa dibeli dengan token.

**Ikonografi lintas-agama:** `star-crescent.svg`, `cross.svg`, `covered-jar-pink.svg` (37×37
masing-masing) — Islam, Kristen, dan guci/Tionghoa, berdampingan, ukuran sama.

**Motif merek:** `bunga-kamboja-asuransi-banner.webp` (485×485) — bunga kamboja itu sendiri.

### 1.5a Hero, terverifikasi langsung — 13 Sep 2026

Dibaca dari tangkapan hidup (§0.3), bukan rekonstruksi.

| Unsur | Yang terlihat |
|---|---|
| **Banner darurat di atas nav** | Bidang magenta selebar halaman: *"Perlu layanan kedukaan sekarang?"*, tombol pil **putih** berikon telepon `0822 1111 1415`, *"Silahkan hubungi Hotline kami (24 jam)"*, dan cetak kecil *"Hanya dikhususkan untuk laporan meninggal member Kamboja dan Layanan Kedukaan (On Demand)."* Di kanan: *"Kami siap dalam melayani segala keperluan kedukaan anda."* + tombol tutup (×) — **banner ini bisa ditutup** |
| Navigasi | Wordmark gradien magenta huruf kecil; `Proteksi Kedukaan` · `Layanan Kedukaan ▾` · `Tentang Kami ▾` · `F.A.Q` · `Blog`; `Login Member` sebagai pil magenta |
| **Foto hero** | *Full-bleed*, ±55% kanan, mengalir ke belakang salinan. Keluarga tertawa di luar ruang, cahaya siang, kandid |
| **Judul hero** | *"Untuk Mereka, Jika Hari itu Tiba"* — **gelap `#263238`, bukan magenta, bukan putih** |
| CTA ganda | Pil gradien terisi berikon perisai + chevron (`Proteksi Kedukaan Berjangka`); pil outline berikon dokumen + chevron, label magenta (`Jasa Kedukaan`) |
| Bukti sosial | **Di bawah kedua CTA**, kecil, rata kiri: Google Reviews (5 bintang), Trustpilot (5 bintang hijau), *"Rated Excellent 5.0/5.0"* |
| Bantuan mengambang | Tombol WhatsApp hijau kanan-bawah dengan gelembung *"Perlu Bantuan? WhatsApp Kami"* |

**Urutan itu sendiri adalah temuan.** Emosi lebih dulu (foto + judul), lalu aksi (dua CTA), baru
bukti institusional (badge) — badge berada **di bawah** gambar emosional, bukan menggantikannya.
Urutan ini dapat dipindahkan ke Makam.co.id tanpa satu pun device terlarang, karena yang dipinjam
adalah susunannya, bukan warnanya. Lihat U1.

Perhatikan juga cetak kecil pada banner darurat: kamboja membatasi janji hotline 24 jamnya pada
member dan layanan On Demand. Itu **pembatasan klaim yang jujur**, sejenis dengan banner
ketersediaan Makam.co.id hari ini — bukan device pemasaran. Lihat U7.

### 1.5b Kartu produk, terverifikasi langsung

| Unsur | Yang terlihat |
|---|---|
| Penempatan | Kedua kartu **menumpang di atas tepi bawah foto hero**, ditarik naik — bukan dimulai setelah foto selesai |
| Permukaan | Putih, radius besar, bayangan lembut, padding longgar |
| Ikon | Ikon putih di dalam kotak membulat magenta (perisai; nisan) |
| **Judul dua nada** | Baris pertama magenta, baris kedua nyaris hitam — *"**Proteksi Kedukaan** / Berjangka"*, *"**Jasa Pengurusan** / Kedukaan On Demand"* |
| **Angka uang** | *"Proteksi hingga **IDR 100 juta**"*, *"Mulai dari **IDR 15.000.000**"* — dirender **hijau dan dalam muka huruf yang berbeda dari teks isi** |
| Butir | Lingkaran magenta terisi dengan centang putih |
| CTA ganda per kartu | Tautan teks + chevron (`Baca Selengkapnya ›`) **dan** pil magenta terisi (`Daftar Sekarang` / `Estimasi Biaya`) |
| Salinan | *"Muslim & Non-muslim"* muncul di badan kartu On Demand |

**Dua koreksi terhadap bagian arsip:**

1. Tombol `#1E73BE` yang muncul pada rekonstruksi memang **biru bawaan Oxygen**, seperti yang §0.2
   duga. Di situs hidup tombol-tombol itu **magenta**. Dugaan itu kini terkonfirmasi.
2. **Angka uang memakai aksen tersendiri — hijau — yang berbeda dari magenta merek.** Ini
   mengkonfirmasi ADR-0037 rekomendasi 1 (*"exactly one, different, accent colour for money/price
   figures"*), yang sampai tangkapan ini hanya bisa dicatat sebagai tidak dikonfirmasi dan tidak
   dibantah. Makam.co.id sudah memiliki padanannya, `--mk-text-price` (ADR-0037), dan token itu
   **belum dipakai di beranda**. Lihat U3 dan Tahap 6.

### 1.5c Testimoni, terverifikasi langsung

Bukan kutipan yang ditulis sendiri. Setiap kartu memuat foto avatar, nama asli
(*"muhammad wildan athar"*, *"Endah Darwati"*, *"fadly fadil"*), stempel waktu relatif (*"5 bulan
yang lalu"*), lima bintang emas, **lencana terverifikasi biru**, dan **tanda "G" Google** di pojok.
Disusun sebagai korsel dengan panah lanjut, dan di bawahnya CTA `Berikan ulasan`.

Ini penting untuk N7: yang membuat blok ini bekerja bukan tata letaknya, melainkan **kenyataan
bahwa ulasannya nyata dan dapat ditelusuri ke Google**. Menyalin tata letaknya tanpa ulasan nyata
akan menghasilkan persis "testimonials-as-decoration" yang §2.3 larang.

### 1.6 Aparatus kepercayaan

Berlapis dan padat: logo mitra asuransi (`LippoLife-Logo-rbl.png`, `allians.png`,
`logo-astra-life-300x79.png`), strip metode pembayaran, logo pers (`kompas.png`, `detik.png`,
`viva.png`, `tribun.png`, `suara.png`, `jpnn.png`, `tabloidbintang.png`), Google Reviews +
Trustpilot "Rated Excellent 5.0/5.0" — dan dua yang terakhir berada **di dalam hero**, bukan di
bawah.

### 1.7 Suara salinan

Navigasi: `Proteksi Kedukaan` · `Layanan Kedukaan` · `Tentang Kami` · `F.A.Q` · `Blog` ·
`Login Member`.

Hero, verbatim:

> **Untuk Mereka, Jika Hari itu Tiba**
> Kehilangan seseorang yang dicinta tidak hanya meninggalkan kesedihan semata. Namun banyak hal
> lainnya. Kamboja siap membantu anda dalam merencanakan dan mengurus segala hal mengenai kedukaan.
> Untuk ketenangan anda dan mereka dalam melaluinya jika "hari itu" tiba.

Judul lain: *"Rencanakan 'Hari itu' Untuk Mereka"*, *"Dapatkan kemudahan dalam proses pemakaman,
Kamboja akan mengurusnya."*, *"Beragam Fitur Inovatif untuk Kemudahan Anda"*.

Ciri suaranya: **orang kedua, emosional, dan menghindari kata mati.** Tidak sekali pun judul
menyebut kematian secara langsung — selalu *"hari itu"*, *"kedukaan"*. Kalimat dibuka dari sisi
orang yang ditinggalkan, bukan dari sisi proses.

Bandingkan hero Makam.co.id: *"Urus Pemakaman dengan Tenang, dalam Satu Platform"* — dibuka dari
sisi proses dan produk.

---

## 2. Desain Makam.co.id hari ini — mekanisme keplainannya

Semua angka di bawah dibaca dari `https://makam.co.id/` hidup pada 1440×900, 13 Sep 2026.

### 2.1 Mekanisme 1 — ritme vertikal dipakai setengah dari yang diwajibkan

`design-system.md` §4.4 mewajibkan: *"`--mk-section-gap` 40 px mobile / `--mk-section-gap-lg` 64 px
desktop between page sections."* §4.1 menggambar hal yang sama di diagram page shell.

Yang benar-benar dipakai `resources/views/livewire/public/home-page.blade.php`:

```
7× py-5        (20px)
7× lg:py-8     (32px)
1× py-8
```

Jadi desktop mendapat **32px, bukan 64px** — tepat setengah. Dan ini bukan salah tafsir: kedua token
itu **kode mati**. Pencarian di seluruh `resources/` dan `app/` menemukan `--mk-section-gap` dan
`--mk-section-gap-lg` **hanya pada baris definisinya sendiri** di `tokens.css:362-363`, tanpa satu
pun konsumen. Hal yang sama berlaku untuk `--mk-gutter*` (359–361) dan `--mk-stack-gap` (364).

Akibat terukur: sembilan section muat dalam dokumen setinggi **4.452px** pada layar 1440px. Setiap
section bernapas dengan jarak yang sama, sehingga tidak ada satu pun yang terbaca lebih penting
dari yang lain. **Ini penyumbang keplainan terbesar, dan ia adalah pelanggaran design system
sendiri — bukan perkara selera.**

### 2.2 Mekanisme 2 — warna merek hampir tidak pernah menjadi bidang

Setiap elemen dengan luas > 2×2px disurvei; `backgroundColor` dan `color` dihitung:

| Latar yang benar-benar tergambar | Elemen |
|---|---|
| `#FFFFFF` (kartu) | **23** |
| `#E1F1E4` (secondary-100) | 7 |
| `#EEF0F0` (neutral-100) | 6 |
| `#F0E6DE` (primary-100) | 5 |
| `#F9F4F0` (primary-50) | 3 |
| `#F7F8F8` (neutral-50, halaman) | 1 |
| `#FDF6EB` (warning-50) | 1 |
| **`#563B26` (primary-600)** | **1** |
| `#F2F9F3` (secondary-50) | 1 |
| `#2A1D13` (primary-900, footer) | 1 |

**Warna merek mengisi tepat satu elemen di seluruh beranda**: tombol `Pesan Makam`, 160×52px.
Sebagai warna *teks* ia muncul 46 kali — jadi merek hadir sebagai tinta, hampir tidak pernah sebagai
bidang. Sebuah halaman yang 23 permukaannya putih di atas abu-abu nyaris-putih akan terbaca plain
berapa pun bagusnya paletnya.

Bandingkan kamboja: gradien magenta mengisi wordmark, CTA, kartu ikon, dan blok hotline.

### 2.3 Mekanisme 3 — kartu tunggal untuk segalanya

Delapan belas kartu di beranda, dan semuanya identik:

```
bg=#FFFFFF  radius=12px  border=1px #DCE0E0  shadow=sm
```

Kartu layanan (286×242), kartu TPU/TPS (389×402), dan baris FAQ (600×130) memakai perlakuan yang
persis sama. Tidak ada bahasa visual yang membedakan "pintu masuk ke sebuah journey" dari "satu
baris jawaban". Hierarki sepenuhnya diserahkan pada ukuran kotak.

### 2.4 Mekanisme 4 — hero adalah dua balok yang tidak pernah bersentuhan

Struktur hero terukur:

```
<div class="relative overflow-hidden rounded-lg">   tinggi 636
  <picture>  top=0    tinggi=384        ← foto
  <div class="… bg-primary-50 p-6 md:p-8">  top=384  tinggi=252   ← teks
```

`h1` mulai di y=416 — **di bawah** batas bawah foto (384). Tidak ada tumpang tindih, tidak ada
gradien overlay (`heroGradients` kosong). Foto adalah pita dekoratif di atas pesan, bukan pembawa
pesan. `<h1>` sendiri sudah benar: `font-display text-4xl … lg:text-5xl` merender Poppins 48px/600
di desktop, sesuai §1.4.

Fotonya sendiri: `cemetery-garden-daylight-1440.avif`, 1216×791 natural, dipaksa ke kotak
1425×384 — jadi terpotong menjadi pita 3,7:1. Isinya foto drone udara pemakaman padat berwarna-warni
yang terbaca lebih seperti pola abstrak / citra satelit daripada tempat yang akan dikunjungi
seseorang.

### 2.5 Mekanisme 5 — stok citra tipis dan didaur ulang

Enam kartu TPU/TPS di beranda, tetapi hanya **tiga file foto**, masing-masing dipakai dua kali:

| File | Dipakai untuk |
|---|---|
| `photo-02-grid-colorful.jpg` | TPU Bekasi Jatiasih **dan** TPS Bogor Cimanggu |
| `photo-03-river-divide.jpg` | TPU Bogor Bantarjati **dan** TPS Depok Cinere |
| `photo-04-red-green-grid.jpg` | TPU Depok Sawangan **dan** TPS Jakarta Kemang |

Ketiganya foto drone udara — sudut yang sama, rasa yang sama. Satu-satunya foto manusia,
`public/images/home/family-warmth.jpg`, adalah stok generik (mobil dengan tulisan "BALI" terlihat di
latar) dan tidak berhubungan dengan pemakaman.

Bobot aset, diperiksa di repo:

| Aset | Ukuran | Turunan AVIF/WebP |
|---|---|---|
| `public/images/hero/cemetery-garden-daylight.*` | 81–119KB per turunan | **ada** (6 turunan, 640/960/1440) |
| `public/images/cemeteries/photo-01…04.jpg` | 215–246KB | **tidak ada** |
| `public/images/home/family-warmth.jpg` | 248KB | **tidak ada** |

Hanya hero yang punya pipeline responsif. Enam kartu TPU/TPS + foto keluarga mengirim ±955KB JPEG
mentah di bawah lipatan. Semua masih di bawah ambang GATE 14 (300KB umum / 120KB AVIF-WebP), jadi
**bukan kegagalan gate** — tetapi celah kualitas yang nyata.

Catatan: `public/images/cemeteries/` sudah memuat empat ilustrasi SVG
(`illustration-01-gate.svg`, `-02-grove`, `-03-path`, `-04-garden`, masing-masing < 2,1KB) yang
**tidak dipakai di beranda**. Aset ini sudah ada dan gratis untuk dipakai.

### 2.6 Halaman demi halaman — bukan hanya beranda

Semua diukur langsung dari situs hidup, 1440×900.

| Halaman | Tinggi dokumen | Temuan |
|---|---|---|
| `/` beranda | 4.452px | 9 section, 18 kartu identik, satu bidang merek (§2.1–2.5) |
| `/pemakaman` direktori | 2.249px | 9 lokasi, **4 foto** dipakai bergilir; tidak ada `<select>`/`<input>` — filter kota & jenis berupa chip 44px; setiap kartu memuat `Perlu konfirmasi`, kisaran harga, dan atribusi `Sumber: Estimasi internal (data contoh) · per 08/08/2026` |
| `/pemesanan-makam` wizard | 926px (mobile 360) | Stepper 4 langkah; Langkah 1 **nol `<input>`** — pemilihan kota berupa chip 44px, progressive reveal; ada bar sticky `md:hidden sticky top-[var(--mk-header-h)] z-sticky-cta` |
| `/perpanjangan` | 900px | Stepper 3 langkah; pola chip kota yang sama; halaman sangat pendek |
| `/marketplace` | 2.007px | 9 produk, grid `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4`, atribusi harga `Estimasi internal (data contoh)` |

**Temuan lintas-halaman yang paling penting: dua register citra hidup berdampingan tanpa aturan.**
Direktori dan beranda memakai **fotografi** (`photo-01…04.jpg`); marketplace memakai
**ilustrasi SVG** untuk seluruh sembilan produknya (`flower-board.svg`,
`gravestone-granite.svg`, `grave-care-monthly.svg`, …). Tidak ada satu pun baris di
`design-system.md` yang menyatakan kapan register yang mana dipakai. Ini bukan sekadar
ketidakkonsistenan estetika — ia adalah akar masalah §9.

**Temuan kedua: kekuatan Makam justru ada di halaman transaksional, bukan di beranda.** Wizard
empat langkah dengan progressive reveal, chip 44px alih-alih dropdown, sticky CTA mobile, dan
atribusi sumber pada setiap angka adalah pola yang **kamboja tidak punya** — situs kamboja
mengarahkan ke formulir dan WhatsApp. Rencana ini melindunginya (§11).

### 2.7 Mobile — kasus utama, dan di sinilah kerusakan terbesar

Diukur pada 360×740, `deviceScaleFactor: 2`, `mobile: true` — viewport Android yang
`--breakpoint-xs` sendiri targetkan.

| Ukuran | Nilai | Catatan |
|---|---|---|
| Horizontal scroll | tidak ada | benar |
| Tinggi header | 56px | sesuai `--mk-header-h` |
| `h1` | 36px/40px | sesuai `text-4xl` |
| Tinggi hero | 708px | foto 256px + panel teks |
| Padding section | **20px** di ketujuh section | §4.4 mewajibkan 40px — separuh, sama seperti desktop |
| Navigasi bawah | tidak ada | benar — §3.11 menandainya PROPOSED, NOT APPROVED (OQ-04) |
| **Posisi CTA `Pesan Makam`** | **y = 1.048px** | ukuran 160×52 |

**Angka terakhir itu adalah temuan UX terpenting dari seluruh pemeriksaan ini.** Pada viewport
setinggi 740px, aksi utama produk berada **1,4 layar di bawah lipatan**. Pengguna harus menggulir
melewati banner ketersediaan (±136px), pita foto 256px, judul dua baris, dan satu paragraf sebelum
melihat tombol yang menjadi alasan halaman ini ada.

Untuk produk yang, menurut brief, sering dibuka dalam keadaan darurat, satu tangan, oleh orang yang
sedang terguncang, ini bukan persoalan estetika. Ini kegagalan fungsional — dan ia **tidak**
disebabkan oleh palet.

Catatan tambahan, **belum dipastikan**: beberapa elemen interaktif terukur < 44px tinggi kotaknya —
`Lihat semua TPU & TPS` (16px), tautan footer (20px), `+62 812-0000-1234` dan `hubungi Bantuan`
(36px). §7.3 mengizinkan ukuran visual lebih kecil **asalkan area sentuhnya 44px**, dan yang diukur
di sini adalah kotak elemen, bukan area sentuh. Jadi ini **daftar periksa**, bukan tuduhan
pelanggaran. Verifikasi area sentuh masuk Tahap 1.

### 2.8 Yang sudah benar dan tidak perlu disentuh

Agar rencana ini jujur: banyak hal sudah beres. Tipografi meresolusi tepat (Poppins 600 display,
Inter var body, keduanya `document.fonts.check()` → true, self-hosted, nol permintaan pihak ketiga).
Transisi memakai token (`color/background-color/border-color/box-shadow 0.12s
cubic-bezier(0.2,0,0,1)`). Tombol primer 160×52 lolos ambang sentuh 44px. Banner ketersediaan
`warning` di atas halaman menyatakan keterbatasan secara terbuka — persis yang §2.2 minta di baris
"Trust signals". Footer memakai `primary-900`. Tidak ada nilai *hardcoded*.

---

## 3. Apa yang boleh diadopsi, apa yang tidak, dan alasannya

### 3.1 BOLEH diadopsi

| # | Device kamboja | Bentuknya di Makam.co.id | Alasan boleh |
|---|---|---|---|
| A1 | Halaman panjang dan longgar; section besar bernapas | Pakai `--mk-section-gap` 40/64px yang sudah diwajibkan §4.4 | Bukan mengambil apa pun dari kamboja — ini menegakkan aturan Makam sendiri yang sedang dilanggar |
| A2 | Alternasi permukaan bertint sebagai penanda struktur | Selang-seling `--mk-surface-warm` (primary-50) dan tint `secondary-50` antar section | §2.3 DO sudah menganjurkan `--mk-surface-warm` untuk "trust/reassurance"; §1.2b mengizinkan `secondary` 50–200 sebagai tint permukaan |
| A3 | Fotografi dokumenter milik sendiri, resolusi tinggi, dengan izin dan disclaimer | Foto TPU/TPS nyata, satu foto per lokasi, plus catatan sumber | §2.2 "Imagery: Real cemeteries/gardens, daylight" + baris "Trust signals: named source" |
| A4 | Ikonografi lintas-agama berukuran setara | Ikon layanan yang tidak mengasumsikan satu agama | Sesuai pasar Jabodetabek; tidak bertabrakan dengan aturan mana pun |
| A5 | Bukti/kepercayaan diletakkan tinggi, dekat hero | **Hanya jika ada konten nyata** — lihat §3.2 N7 | §3.3d sudah memesan slotnya; ADR-0037 rekomendasi 3 sudah menahan pembangunannya |
| A6 | **Hero terpadu: foto *full-bleed*, salinan di atasnya, dua CTA, lalu bukti kecil di bawah** — DIPERBARUI setelah tangkapan hidup (§1.5a) | Satukan foto dan pesan alih-alih dua balok terpisah (§2.4). **Tanpa scrim** — kontras dari komposisi sisi terang, lihat §4.3 | Terverifikasi langsung. Syaratnya fotografi yang punya sisi terang; belum kita punya (OQ-K3/K4), jadi tetap di luar tahap (OQ-K5) |
| A9 | **Judul dua nada** — baris pertama warna merek, baris kedua netral gelap | `primary-600` + `neutral-900` dalam satu `<h3>` | Hierarki tanpa ukuran atau device baru; pasang kontras `primary heading on surface-raised` sudah ada |
| A10 | **Kartu menumpang tepi bawah media hero** | Tarik baris kartu layanan naik ke atas tepi bawah hero | Murni tata letak, nol token baru; mengikat hero ke section berikutnya |
| A11 | **Angka uang memakai aksen tersendiri** | Pakai `--mk-text-price` yang sudah ada (ADR-0037) dan belum terpakai di beranda | Terverifikasi langsung (§1.5b) — ini persis tujuan token itu dibuat |
| A7 | Suara orang-kedua yang hangat dan berpihak pada keluarga | "Untuk keluarga Anda" alih-alih "dalam satu platform" | §2.2 "Copy voice: Plain Indonesian, direct" tidak melarang kehangatan |
| A8 | Kartu produk besar dengan ikon besar dan daftar manfaat | Bedakan kartu layanan dari kartu daftar (§2.3) | §3.3 dan §3.3b sudah punya primitifnya |

### 3.2 TIDAK boleh diadopsi

Ini bagian yang harus dikatakan terus terang. **Sebagian besar dari yang membuat kamboja terasa
tidak plain adalah persis hal-hal yang dilarang Makam.co.id** — dan larangan itu benar untuk
platform yang melayani orang dalam duka.

| # | Device kamboja | Larangan yang berlaku | Mengapa larangan itu benar |
|---|---|---|---|
| N1 | Palet magenta `#D23574` dan keluarga gradien ungu | [ADR-0034](../../adr/0034-adopt-makam-brand-identity.md) D1 mengunci Earth brown sebagai `primary`; `tokens.css` §1.1 | Identitas Makam berasal dari *Filosofi Logo* — earth, tenang, stabil, hangat, "not too tech". Mengambil magenta berarti membuang identitas, bukan bahasa desain |
| N2 | Gradien empat-stop pada bidang | `design-system.md` §2.3: *"no gradient on any interactive surface"*; §2.2 anti-target: *"Bright, saturated, gradient-heavy"* | Gradien membaca sebagai pemasaran; §2.1 meminta "seperti kantor pelayanan publik yang dikelola baik" |
| N3 | Tombol pil `border-radius: 60px` | `tokens.css` §1.7 verbatim: *"No pill-shaped buttons: playful geometry reads wrong on a bereavement service"*; §2.3 DON'T | Geometri bermain-main salah nada di layanan kedukaan |
| N4 | Bayangan CTA berwarna `0 4px 20px rgb(210 53 116 / 30%)` | `tokens.css` §1.8: bayangan *"Tinted with the neutral hue rather than pure black"*, rendah dan lembut; §2.3 melarang di atas `--shadow-xl` | Cahaya berwarna adalah device menarik-perhatian |
| N5 | Masker foto blob organik (`54% 46% 63% 37% / …`) | Semangat §1.7 yang sama dengan N3; tidak ada token radius yang bisa menyatakannya dan menambahkannya akan melanggar rasionalnya sendiri | Sama dengan N3 |
| N6 | Nunito sebagai muka utama | ADR-0034 D7: Poppins `--font-display`, Inter body | Poppins berasal dari filosofi logo resmi |
| N7 | Trustpilot, Google Reviews, logo pers, logo mitra asuransi | ADR-0037 Context 3 + `design-system.md` §3.3d: **RESERVED, NOT BUILT**; §2.3 melarang "testimonials-as-decoration"; `AGENTS.md` melarang klaim yang tidak bisa dibuktikan | Makam.co.id **belum punya** kemitraan, listing ulasan, atau liputan pers yang nyata. Membangun komponennya sekarang berarti mengisinya dengan konten fabrikasi |
| N8 | Suara eufemistik — *"jika 'hari itu' tiba"*, tidak pernah menyebut kematian | §2.2 anti-target: *"Copy voice … no euphemism-dodging"* | Ini justru poin di mana Makam sengaja berbeda. Kehangatan (A7) bisa diambil; penghindaran kata tidak |
| N9 | `transition: .3s` di semua tempat | §2.2 "Motion: barely noticeable"; `--mk-duration-fast` = 120ms | 300ms pada hover terasa lamban dan "beranimasi" |
| N10 | Hotline besar berwarna penuh di puncak halaman | `AGENTS.md` + §2.3 melarang menyiratkan klaim layanan selagi `G-OPS-01` tertutup; banner `warning` yang ada hari ini sudah jujur | Menjanjikan respons 24/7 yang belum bisa dijamin adalah klaim palsu |

**Kesimpulan yang tidak nyaman tetapi perlu dicatat:** jika masukan "dibuat seperti kamboja"
dibaca secara harfiah sebagai warna dan bentuk, maka permintaan itu bertabrakan langsung dengan
ADR-0034 dan §2.3, dan tidak bisa dipenuhi tanpa ADR baru yang membatalkan keduanya. Jika dibaca
sebagai *"buat terasa sehangat dan sepenuh itu"*, permintaan itu bisa dipenuhi seluruhnya — dan
itulah yang direncanakan dokumen ini. **Bacaan ini dikonfirmasi pemilik pada 13 Sep 2026** — lihat §14 OQ-K2.

---

## 4. Perubahan `resources/css/tokens.css` — token demi token

**Tidak ada satu pun nilai warna yang berubah.** Ini keputusan, bukan kelalaian: §2.2 keplainan
tidak berasal dari palet, dan ADR-0034 §9.4 mewajibkan ADR untuk setiap perubahan token. Mengubah
warna di sini berarti mengubah identitas yang justru harus dipertahankan.

### 4.1 Dampak GATE 1

`ci/verify-docs.sh` GATE 1 menjalankan `docs/design/verify-contrast.py`, yang menegaskan **49
pasang**. Baseline diverifikasi pada worktree ini sebelum rencana ditulis:

```
RESULT: PASS — all 49 pairs meet WCAG 2.1 AA
```

| Usulan | Pasang terdampak | Pasang baru diperlukan |
|---|---|---|
| Tahap 1 (ritme + area sentuh) | tidak ada | tidak |
| Tahap 2 (`--mk-surface-quiet`, bidang merek) | `text-default on secondary-50`, `text-strong on secondary-50` (sudah ada, baris 93–94) | **tidak** |
| Tahap 3 (mobile, posisi CTA) | tidak ada | tidak |
| Tahap 4 (hierarki komponen) | tidak ada | tidak |
| Tahap 5 (register citra) | tidak ada | tidak |
| Tahap 6 (dua jalur + harga mulai) | `--mk-text-price` = `primary-800` di atas putih — sudah terbukti AA lewat ADR-0037 (lebih gelap dari pasang `primary-700 on white`, 12,16:1) | **tidak** |
| Tahap 7 (salinan) | tidak ada | tidak |

**Hitungan pasang tetap 49 di seluruh rencana ini** — termasuk setelah perluasan cakupan ke
layout, citra, komponen, state layar, dan mobile. Satu-satunya usulan yang bisa mengubahnya adalah
scrim hero opsional di §4.3, yang sengaja tidak masuk tahap mana pun.

### 4.2 Perubahan yang diusulkan

**(a) Token semantik baru — satu buah**

```css
/* 2.1 SURFACES */
  --mk-surface-quiet: var(--color-secondary-50);   /* BARU */
```

| | |
|---|---|
| Sebelum | tidak ada; beranda memakai utilitas mentah `bg-secondary-50` langsung di dua section |
| Sesudah | `var(--color-secondary-50)` = `#F2F9F3` — **nilai identik**, hanya diberi nama niat |
| Alasan | §1.2b mengizinkan `secondary` 50–200 sebagai tint permukaan, tetapi lapisan semantik tidak pernah menamainya, sehingga komponen memanggil primitif langsung — persis yang dilarang header `tokens.css` §2 (*"Always reference the SEMANTIC token in component CSS, not the primitive"*) |
| Cek kontras | Tidak ada pasang baru. `verify-contrast.py` sudah menegaskan `text-default on secondary-50` dan `text-strong on secondary-50` |
| Butuh ADR? | Ya — §9.4 mewajibkannya untuk token baru, meskipun nilainya alias murni |

**(b) Dua utilitas baru di `resources/css/app.css` — menghidupkan token yang mati**

```css
@utility section-y    { padding-block: var(--mk-section-gap); }
@utility section-y-lg { padding-block: var(--mk-section-gap-lg); }
```

| | |
|---|---|
| Sebelum | `--mk-section-gap` / `--mk-section-gap-lg` didefinisikan di `tokens.css:362-363` dan **tidak dirujuk di mana pun** |
| Sesudah | dipakai sebagai `class="section-y lg:section-y-lg"` |
| Preseden | Pola `@utility` ini sudah dipakai `app.css:31-47` untuk seluruh keluarga `--mk-z-*` dan `--mk-duration-*` |
| Nilai token | **tidak berubah** (40px / 64px) |
| Alternatif | `py-10 lg:py-16` menghasilkan piksel identik tanpa utilitas baru (`--spacing` 0.25rem × 10 dan × 16) dan sepenuhnya lolos GATE 2/3. Jika reviewer menolak utilitas baru, pakai ini — tetapi token tetap mati |
| Butuh ADR? | Tidak — tidak ada token ditambah atau diubah nilainya |

**(c) Yang sengaja TIDAK diusulkan**

- Tidak ada perubahan nilai pada `--color-primary-*`, `--color-secondary-*`, atau keluarga semantik.
- Tidak ada token radius baru — N3/N5 melarangnya.
- Tidak ada token bayangan berwarna — N4.
- Tidak ada token gradien — N2.
- Tidak ada perubahan `--mk-duration-*` — N9.

### 4.3 Teks di atas foto — **DIKOREKSI 13 Sep 2026 setelah tangkapan hidup**

Versi pertama bagian ini menyatakan bahwa hero kamboja tidak menaruh teks di atas foto, sehingga
mengusulkan scrim berarti mengarang. **Itu keliru, dan penyebabnya arsip yang tidak lengkap
(§13.1).** Tangkapan hidup menunjukkan hero kamboja justru *full-bleed* dengan salinan di atas
gambar.

**Tetapi caranya bukan scrim** — dan ini justru bagian yang penting. Tidak ada lapisan gelap di atas
foto. Kontras diperoleh dari **komposisi**: sisi kiri gambar secara alami terang (latar
interior/jendela yang terbakar cahaya), subjek berada di kanan, dan salinan diletakkan di atas
bagian terang itu dalam warna gelap `#263238`. Judul hero **tidak** putih; ia gelap di atas terang.

Konsekuensinya untuk Makam.co.id ada dua, dan keduanya mengubah usulan:

1. **Token scrim kemungkinan besar tidak diperlukan.** Tidak ada `--mk-scrim-hero` yang diusulkan.
   Ini menghapus satu token, satu ADR, dan satu metode verifikasi baru dari rencana.
2. **Bebannya berpindah dari token ke seleksi gambar.** Teknik ini hanya bekerja bila foto memang
   punya sisi terang yang cukup luas dan konsisten di setiap breakpoint. Itu disiplin arah seni,
   bukan sesuatu yang bisa dijamin CSS.

**Yang tidak berubah:** `verify-contrast.py` tetap **tidak bisa** menegaskan kontras teks di atas
foto. Ia membandingkan dua token warna; sebuah foto bukan token. Jadi seandainya pola ini diadopsi,
gerbangnya tetap harus berupa pengukuran piksel terender pada gambar terburuk — bukan entri baru di
`PAIRS`, yang akan lulus tanpa membuktikan apa pun.

Karena itu pola ini **tetap di luar tahap mana pun** dan tetap menjadi OQ-K5, tetapi alasannya kini
berbeda: bukan lagi "tidak ada buktinya", melainkan "buktinya ada, dan syaratnya adalah fotografi
yang belum kita punya" (§9, OQ-K3/OQ-K4). Foto udara drone yang dipakai hari ini tidak punya sisi
terang seperti itu.

---

## 5. Perubahan per komponen — file nyata

Semua path diverifikasi ada pada `7d3bcb81`. Kolom "identitas" menjawab pertanyaan brief: apakah
komponen ini membawa identitas Makam, atau generik?

| Komponen / file | Identitas? | Temuan | Usulan | Tahap |
|---|---|---|---|---|
| `components/mk/card.blade.php` | **generik** | Satu perlakuan (putih / 12px / 1px `#DCE0E0` / `shadow-sm`) untuk 18 kartu: layanan, TPU/TPS, FAQ | Tambah varian penekanan; kartu pintu-masuk journey ≠ baris FAQ. Varian baru, default tidak berubah | 4 |
| `components/mk/button.blade.php` | **membawa** | Primer `#563B26` 160×52, radius 8px, sekunder outline — satu-satunya bidang merek di halaman | Jangan sentuh bentuknya. Tambah pemakaian, bukan gaya baru | — |
| `components/mk/field.blade.php` | membawa (fokus + border) | `--mk-border-interactive` 3,67:1 sudah benar | Tidak ada | — |
| `components/mk/badge.blade.php` | **membawa** (lewat intent) | Membaca `--mk-intent-*`; resolusi status lewat `StatusIntent` | **Tidak ada perubahan warna status.** Lihat §5.1 | — |
| `components/mk/filter-chip.blade.php` | generik | Chip kota/jenis 44px di direktori, wizard, perpanjangan — pola mobile yang bagus | Lindungi; jadikan pola kanonik untuk pilihan pendek alih-alih `<select>` | — |
| `components/mk/table.blade.php` | generik | Tidak muncul di lima halaman publik yang diperiksa | Tidak ada usulan — tidak ada bukti masalah | — |
| `components/mk/hero.blade.php` | membawa | Dua balok yang tidak bersentuhan (§2.4) | Beri bobot panel teks; **tanpa** overlay/scrim (§4.3) | 4 |
| `components/mk/icon-medallion.blade.php` | **membawa** (tone earth/leaf) | Sudah dipakai di tiga section beranda | Perbesar pada kartu layanan — device "ikon besar" kamboja (§1.5 jalur A) yang aman diadopsi | 4 |
| `components/mk/stepper.blade.php` | membawa | 4 langkah pemesanan, 3 langkah perpanjangan; label Indonesia + status "belum tersedia" | Lindungi. Ini keunggulan Makam (§10) | — |
| `components/mk/alert.blade.php` | membawa | Banner ketersediaan `warning` di puncak beranda | Lindungi — ini kejujuran yang §2.2 minta | — |
| `layouts/app.blade.php:155` `<footer>` | **membawa** | `bg-primary-900 px-4 py-8` — satu dari dua bidang merek di halaman | Perluas: footer adalah tempat paling aman untuk bidang merek besar | 2 |
| `components/mk/header.blade.php` | membawa | 56px mobile / sticky; hamburger 44×44 | Tidak ada. §3.11 melarang navigasi bawah tanpa persetujuan produk | — |
| `filament/shared/plot-floor-map.blade.php` (+ `aggregate`, `granular`) | generik | Peta petak — **panel Filament**, bukan permukaan publik | **Di luar cakupan.** §8.3 design system menandai panel Filament sebagai keputusan pemilik yang terpisah | — |
| `livewire/public/home/plot-availability-preview.blade.php` | membawa | Pratinjau petak di beranda, read-only, merender nol saat tidak ada data nyata | Lindungi — pola empty-state yang benar (§6) | — |

### 5.1 Warna status — tidak disentuh, dan alasannya

`app/Support/Design/StatusIntent.php` adalah **satu-satunya** tempat status domain diterjemahkan ke
(intent, ikon, label Indonesia). Doc block-nya menyatakan aturannya: komponen tidak boleh
`match` enum sendiri (§3.7 normatif, §9.2 MUST #5), dan kelas itu **tidak pernah** mengembalikan
hex — resolusi warna tetap di lapisan Blade lewat token `--mk-intent-*`.

**Rencana ini tidak mengubah satu pun warna status, pemetaan intent, atau ikon.** Alasannya bukan
kehati-hatian umum: §7.5 mewajibkan setiap status berpasangan dengan ikon **dan** label teks
Indonesia, sehingga warna status bukan lagi variabel estetika — ia terikat pada makna domain yang
kanonik di `order-lifecycle.md` dan `marketplace-catalog.md`. Menggeser hue "supaya lebih hangat"
akan memutus ikatan itu.

Satu-satunya hal yang boleh berubah adalah **di mana** intent muncul, bukan warnanya.

---

## 6. Sepuluh state layar — bahasa desain yang hanya menggambarkan happy path belum selesai

`design-system.md` §6 menyatakan sepuluh state **wajib** dan menegaskan: *"All ten are required. A
screen missing one is incomplete, not 'shipped'."* Tabel di bawah menyatakan bagaimana tiap state
terasa dalam bahasa desain yang diusulkan. **Tidak ada state yang mendapat warna baru** — yang
berubah hanya ruang, ritme, dan register citra.

| # | State | §  | Bagaimana bahasa desain baru menyentuhnya |
|---|---|---|---|
| 1 | loading | 6.1 | Skeleton struktural sudah benar (`--mk-skeleton-base`, CLS < 0,1). **Satu perubahan:** skeleton harus meniru ritme section yang baru (40/64px), bukan yang lama — kalau tidak, halaman "melompat" saat konten masuk |
| 2 | empty | 6.2 | Tiga bagian (apa kosong · mengapa · langkah berikutnya) tidak berubah. Di sinilah **ilustrasi** (§9) mendapat perannya yang sah: ikon `size-12 text-neutral-400` boleh naik menjadi ilustrasi SVG yang sudah ada di repo. Ini menambah kehangatan tanpa mengarang konten |
| 3 | validation error | 6.3 | Tidak berubah. Inline per field + alert ringkasan |
| 4 | authorization failure | 6.4 | Tidak berubah — halaman penjelas, bukan 403 mentah |
| 5 | provider unavailable | 6.5 | Tidak berubah |
| 6 | duplicate / retry-safe | 6.6 | Tidak berubah |
| 7 | pending | 6.7 | Tidak berubah. `--mk-intent-pending-*` tetap |
| 8 | success | 6.8 | Tidak berubah, dan **harus tetap tenang** — §2.3 melarang perayaan. Ini titik di mana godaan "seperti kamboja" paling berbahaya |
| 9 | support escape hatch | 6.10 | Tetap di setiap layar transaksional. Ritme baru tidak boleh mendorongnya turun — lihat §7 |
| 10 | responsive mobile | — | **Di sinilah pekerjaan nyata.** §2.7 menunjukkan padding mobile separuh dari yang diwajibkan dan CTA utama di y=1.048. Setiap state di atas harus dirancang pada 320px lebih dulu |

**Konsekuensi yang harus dinyatakan:** menambah ruang (Tahap 1) membuat setiap halaman **lebih
panjang**. Itu memperbaiki desktop dan memperburuk mobile bila dilakukan tanpa Tahap 3. Karena itu
Tahap 1 dan Tahap 3 tidak boleh dipisah lebih dari satu rilis.

---

## 7. Mobile sebagai kasus utama

Brief menyatakan produk ini sering dibuka dalam keadaan darurat, satu tangan, oleh orang yang
terguncang. Bahasa desain harus dinilai dari kondisi itu, bukan dari desktop.

**Masalah:** CTA utama di y = 1.048px pada viewport 740px (§2.7).

**Tiga cara memperbaikinya, dengan konsekuensi masing-masing:**

| Opsi | Cara | Konsekuensi |
|---|---|---|
| M1 | Perpendek pita foto hero di mobile (256px → lebih kecil), CTA naik | Paling murah; foto makin menjadi hiasan tipis. Perubahan token: **nihil** (`h-64` → nilai lebih kecil, tetap turunan `--spacing`) |
| M2 | Balik urutan di dalam hero pada mobile: panel teks + CTA **di atas** foto | CTA naik ±256px tanpa mengecilkan foto. Butuh perubahan `hero.blade.php`; §4.5 tidak terpengaruh karena urutan *section* tidak berubah — hanya urutan internal satu komponen |
| M3 | Sticky CTA mobile di beranda, meniru bar yang **sudah ada** di wizard (`md:hidden sticky top-[var(--mk-header-h)] z-sticky-cta`) | Pola sudah terbukti di repo ini; token `--mk-z-sticky-cta` sudah ada. Risiko: menambah elemen persisten di halaman pemasaran |

**Rekomendasi: M2, lalu ukur ulang.** M2 memperbaiki penyebabnya (urutan), bukan gejalanya
(ukuran), tidak menambah elemen persisten, dan tidak mengecilkan satu-satunya foto besar di
halaman. M1 dan M3 tetap tersedia bila pengukuran ulang menunjukkan CTA masih di bawah lipatan.

Yang **tidak** diusulkan: navigasi bawah. §3.11 menandainya PROPOSED, NOT APPROVED, terikat OQ-04,
dan `AGENTS.md` melarang menciptakan navigasi alternatif tanpa persetujuan produk.

Pemeriksaan mobile wajib di setiap tahap: nol scroll horizontal pada 320px; area sentuh 44px (§7.3,
termasuk daftar periksa §2.7); `--mk-safe-bottom` dihormati; setiap state §6 diperiksa pada 320px.

---

## 8. Gerak dan ikonografi

### 8.1 Gerak — hampir tidak ada yang berubah, dan itu disengaja

Situs hidup sudah memakai token: `color/background-color/border-color/box-shadow 0.12s
cubic-bezier(0.2,0,0,1)` — yaitu `--mk-duration-fast` + `--ease-standard`. kamboja memakai
`transition: .3s` di 21 tempat.

**Tidak diadopsi** (N9): §2.2 menetapkan "Motion: barely noticeable" sebagai target dan
"Animated, celebratory, attention-seeking" sebagai anti-target.

Tiga tempat di mana gerak boleh ditambah, semuanya memakai token yang sudah ada:

1. Perpindahan langkah wizard → `--mk-duration-slow` (260ms) + `--ease-emphasized`. Token ini sudah
   didefinisikan untuk tujuan itu (`tokens.css` §1.11 komentar "stepper advance") tetapi belum
   terpakai.
2. Bottom sheet / modal → `--mk-duration-slow`, sudah sesuai §3.4.
3. Skeleton → `--mk-duration-slower` (400ms), sudah sesuai §6.1.

`prefers-reduced-motion` sudah ditangani `tokens.css` §3. Tidak ada usulan perubahan.

### 8.2 Ikonografi

kamboja memakai dua hal yang berbeda: ikon SVG datar berukuran besar (89×89, 131×130) dalam warna
merek penuh, dan **ikon lintas-agama berukuran setara** (`star-crescent.svg`, `cross.svg`,
`covered-jar-pink.svg`, masing-masing 37×37).

| Device | Adopsi? | Catatan |
|---|---|---|
| Ikon besar pada kartu layanan | **ya** | `<x-mk.icon-medallion>` sudah ada dan sudah punya `tone="earth"`/`tone="leaf"`. Perbesar, jangan bikin baru |
| Ikon dalam warna merek penuh | **ya, terbatas** | Medallion sudah melakukannya. Ini salah satu cara termurah menambah bidang merek (§2.2) |
| Ikonografi lintas-agama setara | **ya, tetapi bukan keputusan desain** | Pasar Jabodetabek jelas multi-agama dan produk sudah membedakan "Muslim & Non-muslim". Tetapi menambahkan simbol agama ke antarmuka adalah keputusan produk, bukan estetika. Lihat OQ-K9 |
| Icon font | **tidak** | §4.6 verbatim: "Inline SVG sprite, tree-shaken. **No icon font.**" |

---

## 9. Citra — masalah nyata, dan pendapat saya

Brief meminta pendapat yang jelas di sini. Ini pendapat itu.

### 9.1 Keadaan faktual

- `app/Support/ExampleData/CemeteryExampleData.php:208-213` mendefinisikan `EXAMPLE_PHOTOS`, **empat
  file**, dan doc block-nya menyatakan foto itu "cycled by index".
- `database/migrations/2026_08_24_100000_backfill_photo_and_maps_url_for_real_cemeteries.php:73-93`
  memakai pola yang sama (`PHOTOS[$index % count(PHOTOS)]`) untuk empat TPU nyata bernama — dengan
  **empat ilustrasi SVG**, bukan foto.
- Pada direktori publik hari ini, sembilan lokasi tampil dan seluruhnya memakai **empat foto** yang
  sama secara bergilir. Tidak ada SVG yang muncul. Seluruh sembilan baris itu fiktif — alamat
  "Jl. Contoh …", harga "Estimasi internal (data contoh)".
- Doc block yang sama menyatakan perubahan 8 Sep 2026 ini dilakukan atas **arahan eksplisit pemilik
  produk** (via WhatsApp), menggantikan empat ilustrasi SVG dengan empat foto stok, dan mencatat
  sendiri bahwa ini "a deliberate reversal of the 'illustrations, not photographs' stance".

### 9.2 Pendapat

**Empat foto yang diputar untuk seluruh katalog tidak layak dibawa ke peluncuran publik.** Bukan
karena pengulangannya terlihat murah — meski iya — melainkan karena efek gabungannya membuat klaim
yang tidak bisa dibuktikan.

Tiap bagian, sendiri-sendiri, dapat dipertahankan. Baris fiktif diberi nama supaya terbaca fiktif.
Pemakaian ulang foto diungkap. Harga diatribusi. Tetapi **yang dibaca pengunjung bukan bagian-bagian
itu** — yang dibaca adalah sebuah kartu berjudul "TPU Bogor Bantarjati" dengan sebuah foto
pemakaman di atasnya, dan kartu itu berkata: *beginilah rupa TPU Bogor Bantarjati.* Foto itu
sesungguhnya pemakaman lain yang tidak berhubungan. Pengungkapannya ada di komentar PHP;
klaimnya ada di halaman.

Ini persis pola yang `design-system.md` §2.2 tolak pada baris "Trust signals: **named source**" dan
yang `AGENTS.md` larang sebagai klaim tanpa bukti. Repositori ini sudah menerapkan disiplin itu
dengan ketat di tempat lain — koordinat dibiarkan `null` daripada mengarang presisi, harga selalu
membawa atribusi sumber dan tanggal, ketersediaan selalu "Perlu konfirmasi". Foto adalah satu-satunya
bidang yang lolos dari disiplin itu, dan ia kebetulan bidang yang paling besar dan paling dipercaya
mata.

**Dan kamboja sendiri menunjukkan jalan keluarnya.** Situs itu memasang disclaimer fotonya **di
halaman**, bukan di komentar kode: *"Materi foto diatas adalah properti tim dokumentasi Kamboja dan
telah mendapat persetujuan dari keluarga dan pihak terkait."* Itu bukan basa-basi hukum — itu
device desain. Ia mengubah foto dari klaim menjadi kesaksian.

### 9.3 Yang saya usulkan

Aturan register citra, ditulis ke `design-system.md` §2.2 sebagai aturan eksplisit yang hari ini
belum ada:

| Kondisi | Register | Alasan |
|---|---|---|
| Ada foto terverifikasi **dari lokasi itu sendiri** | Fotografi, dengan kapsion sumber terlihat di kartu | Foto adalah klaim; kapsion membuatnya klaim yang bisa dipertanggungjawabkan |
| Tidak ada foto lokasi itu | **Ilustrasi** — empat SVG yang sudah ada di `public/images/cemeteries/` | Ilustrasi jujur berkata "kami belum punya foto tempat ini". Foto stok berbohong berkata "inilah tempat ini" |
| Produk marketplace | Ilustrasi | Sudah begitu hari ini — sembilan produk, sembilan SVG. Register ini sudah konsisten dan terbukti |
| Foto manusia | Hanya bila nyata dan berizin | §2.2 "candid warmth", dan §9.2 di atas |

Perhatikan bahwa ini **bukan** usulan baru: ini mengembalikan posisi yang sudah dipegang
`2026_08_24_100000_…` untuk empat TPU nyata, dan menyelaraskan direktori dengan marketplace yang
sudah memakai ilustrasi. Ilustrasinya sudah ada di repo (< 2,1KB masing-masing) dan **tidak dipakai
di mana pun** saat ini.

**Tetapi arahan 8 Sep 2026 adalah arahan eksplisit pemilik.** Jadi ini rekomendasi untuk ditinjau
ulang, bukan pembalikan sepihak. OQ-K3 dan OQ-K10 memberi pemilik tiga pilihan yang jelas.

### 9.4 Perlakuan dan potongan

Bila fotografi tetap dipakai, empat aturan, semuanya turunan token:

1. **Rasio konsisten.** Hari ini kartu TPU/TPS memakai `h-40` (160px) pada kotak 387px lebar =
   2,4:1, sementara hero dipaksa ke 3,7:1 dari sumber 1216×791. Satu rasio untuk media kartu, satu
   untuk hero.
2. **Turunan responsif untuk semua, bukan hanya hero.** Hari ini hanya hero punya AVIF/WebP
   (§2.5). Enam kartu direktori mengirim ±1,4MB JPEG mentah. GATE 14 tidak gagal, tetapi §4.6
   "Largest hero image ≤ 120 KB" jelas mengandaikan pipeline yang sama untuk media lain.
3. **Jangan pernah memotong wajah.** Tidak berlaku hari ini (tidak ada wajah di foto pemakaman),
   tetapi berlaku saat OQ-K4 dijawab ya.
3b. **Bila hero terpadu (A6) diinginkan, sisi terang adalah kriteria seleksi, bukan efek CSS.**
   Tangkapan hidup menunjukkan kamboja meletakkan salinan gelap di atas bagian gambar yang memang
   terang, tanpa scrim (§4.3). Foto udara drone yang dipakai hari ini tidak memenuhi syarat itu —
   permukaannya padat dan berwarna penuh dari tepi ke tepi. Jadi A6 bukan pekerjaan CSS; ia
   pekerjaan pengadaan foto.
4. **`alt` yang jujur.** Hari ini `alt="Foto TPU Bekasi Jatiasih"` pada foto pemakaman lain. Bila
   register foto dipertahankan, `alt` harus menyebut apa foto itu sebenarnya.

---

## 10. Pola UX — dua arah, bukan satu

Brief meminta kejujuran dua arah. Ini dia.

### 10.1 Di mana kamboja lebih baik

| # | Pola | Bukti | Bisa diadopsi? |
|---|---|---|---|
| U1 | **Urutan hero: emosi → aksi → bukti.** Foto keluarga + judul, lalu dua CTA, baru badge ulasan kecil di bawahnya | **Tangkapan hidup** §1.5a | **Urutannya ya, isinya tidak.** Susunan ini tidak memakai satu pun device terlarang. Tetapi badge-nya butuh ulasan nyata (N7, OQ-K6) |
| U2 | **Dua jalur produk dinyatakan di layar pertama** — "Proteksi Berjangka" vs "Jasa On Demand", masing-masing dengan harga mulai | **Tangkapan hidup** §1.5a/§1.5b: CTA ganda di hero, dua kartu menumpang di atas foto | **Ya.** Makam punya At-Need vs Pre-Need (`benchmark-validation-2026-07.md` menyimpulkannya sebagai dua journey berbeda) tetapi beranda tidak menyatakannya di layar pertama |
| U3 | **Harga mulai ditonjolkan dengan aksen tersendiri** — hijau, muka huruf berbeda, terpisah dari warna merek | **Tangkapan hidup** §1.5b: "Proteksi hingga IDR 100 juta", "Mulai dari IDR 15.000.000" | **Ya** — dan Makam sudah punya `--mk-text-price` (ADR-0037) yang dibuat persis untuk ini dan **belum dipakai di beranda** |
| U4 | **Halaman panjang dan longgar** | §1.4 | **Ya** — Tahap 1 |
| U5 | **Disclaimer foto di halaman** | §1.5 | **Ya** — §9.2 |
| U6 | **Kanal bantuan mengambang persisten** (WhatsApp, dengan gelembung label) | **Tangkapan hidup** §1.5a | **Sebagian.** §6.10 sudah mewajibkan support escape hatch; yang kurang adalah persistensinya di halaman pemasaran |
| U7 | **Banner darurat yang membatasi janjinya sendiri** — hotline 24 jam, tetapi cetak kecil membatasinya pada member dan layanan On Demand; banner dapat ditutup | **Tangkapan hidup** §1.5a | **Ya, dan Makam sudah hampir melakukannya.** Banner `warning` Makam sudah jujur soal keterbatasan; yang bisa dipinjam adalah **memberi nomor bantuan bobot visual tombol**, bukan hanya tautan teks, dan membuat banner dapat ditutup. Tidak boleh menambah janji layanan apa pun selama `G-OPS-01` tertutup (N10) |
| U8 | **Judul dua nada** pada kartu — baris pertama warna merek, baris kedua nyaris hitam | **Tangkapan hidup** §1.5b | **Ya.** Hierarki dalam satu judul tanpa menambah ukuran atau device baru; `primary-600` di atas putih sudah terverifikasi AA sebagai teks besar (`primary heading on surface-raised`, pasang yang sudah ada) |
| U9 | **Kartu menumpang tepi bawah foto hero** | **Tangkapan hidup** §1.5b | **Ya** — mengikat hero ke section berikutnya, kebalikan dari dua balok terpisah Makam (§2.4). Murni tata letak, nol token baru |

### 10.2 Di mana Makam lebih baik — dan harus dilindungi

Ini bagian yang paling mudah dirusak oleh permintaan "dibuat seperti kamboja", karena kamboja
adalah situs pemasaran dan Makam adalah aplikasi transaksional. Semua di bawah **tidak dimiliki
kamboja**.

| # | Pola Makam | Bukti | Status |
|---|---|---|---|
| P1 | **Wizard 4 langkah dengan stepper bernama** ("Cari & Pilih / Detail Pemesanan / Pembayaran / Konfirmasi"), lengkap dengan status "belum tersedia" pada langkah yang belum dibuka | `/pemesanan-makam`, diukur langsung | **Lindungi.** Jangan pernah diganti formulir satu halaman |
| P2 | **Progressive reveal** — Langkah 1 merender **nol `<input>`**; pengguna memilih kota lewat chip 44px lebih dulu | `fields: 0` pada pengukuran; chip 113×44, 90×44, … | **Lindungi.** Ini persis yang dibutuhkan pengguna terguncang: satu keputusan per layar (§2.2 "one decision per screen") |
| P3 | **Chip alih-alih `<select>`** untuk pilihan pendek | Direktori, wizard, perpanjangan — ketiganya | **Lindungi dan jadikan kanonik.** Dropdown pada ponsel satu tangan lebih buruk |
| P4 | **Pratinjau ketersediaan petak** di beranda, read-only, merender nol saat tidak ada data nyata | `plot-availability-preview.blade.php` | **Lindungi.** Tidak ada padanannya di kamboja |
| P5 | **Atribusi sumber pada setiap angka** — "Sumber: Estimasi internal (data contoh) · per 08/08/2026" | Beranda, direktori, marketplace | **Lindungi.** Ini kebalikan dari badge Trustpilot: klaim yang bisa diperiksa |
| P6 | **Ketersediaan dinyatakan jujur** — "Perlu konfirmasi" di setiap kartu, banner `warning` di puncak beranda | Diukur langsung | **Lindungi.** §2.2 "honest availability" |
| P7 | **Perpanjangan sebagai journey tersendiri** dengan stepper 3 langkah | `/perpanjangan` | **Lindungi** |
| P8 | **Nol permintaan pihak ketiga**, font self-hosted | §4.6; kamboja memuat `fonts.googleapis.com` | **Lindungi.** Ini juga keputusan privasi, bukan hanya performa |

**Kesimpulan §10:** kamboja lebih baik dalam *menjual*; Makam lebih baik dalam *mengurus*. Bahasa
desain yang diadopsi harus menaikkan kemampuan menjual di beranda **tanpa** menyentuh mesin
pengurusan di wizard. Itulah sebabnya Tahap 1–4 hanya menyentuh permukaan pemasaran dan Tahap 5–6
sengaja ditempatkan paling akhir.

---

## 11. Yang TIDAK boleh berubah

1. **Palet ADR-0034.** Earth brown `primary`, Leaf green `secondary` dalam kurungannya, hue
   `danger` 352°, `--mk-surface-warm` menunjuk `primary-50`. Semuanya disampling dari logo asli
   (OQ-12 tertutup).
2. **Poppins display / Inter body / Source Serif dokumen** — ADR-0034 D7.
3. **Kurungan `secondary`** — 50–200 tint, 300–400 dekoratif, 700–900 teks di atas tint. **Tidak
   pernah** fill, badge, tombol, atau alert (§1.2b, §9.2 MUST NOT 7).
4. **Urutan sembilan section beranda** — §4.5 menyebutnya *"a product contract, not a design
   preference"*.
5. **Pemetaan status → intent → ikon → label** di `StatusIntent.php` (§5.1). Tidak ada hue status
   yang bergeser.
6. **Satu aksi primer per tampilan** — §2.3 DO.
7. **`--mk-text-price` hanya untuk angka uang final** — ADR-0037.
8. **Larangan pil / gradien / bayangan berwarna / mode gelap** — §2.3, §1.7, OQ-07.
9. **Success tetap tenang** — §2.3 melarang konfeti, animasi centang, "Selamat!".
10. **Banner ketersediaan yang jujur** tetap di atas halaman selama `G-OPS-01` tertutup.
11. **Nol permintaan pihak ketiga di halaman publik** — §4.6.
12. **§3.3d `<x-mk.trust-badge-strip>` tetap RESERVED, NOT BUILT** sampai ada konten nyata.
13. **Navigasi bawah tidak dibangun** — §3.11 PROPOSED, NOT APPROVED, OQ-04.
14. **Sepuluh state layar tetap wajib** — §6. Tidak ada tahap boleh mengirim layar yang kehilangan
    satu state demi tampilan.
15. **Panel Filament di luar cakupan** — §8.3 menandainya keputusan pemilik yang terpisah.

---

## 12. Urutan bertahap — satu PR per tahap

Diurutkan berdasarkan dampak tertinggi dengan risiko terendah lebih dulu. Tiap tahap berdiri
sendiri, bisa dikirim dan bisa dibalik sendiri. Tiap tahap wajib lulus `bash ci/verify-docs.sh`,
`php artisan design:verify-filament-palette`, `php artisan blade:verify-content-survival`, dan
suite `tests/browser/*.spec.ts`, plus pemeriksaan 320px untuk setiap state §6 yang tersentuh.

- [ ] **Tahap 1 — Ritme vertikal + audit area sentuh.** Dua `@utility` di `app.css`; ganti
  `py-5 lg:py-8` menjadi `section-y lg:section-y-lg` di beranda, direktori, dan marketplace.
  Verifikasi area sentuh 44px pada daftar §2.7. Nol perubahan warna, salinan, atau urutan.
  **Dampak visual terbesar per baris kode; risiko terendah** — ini perbaikan kepatuhan §4.4, bukan
  perkara selera.
- [ ] **Tahap 2 — Alternasi permukaan + bidang merek.** Tambah `--mk-surface-quiet` (butuh ADR);
  selang-seling tint antar section sehingga batas section terbaca tanpa garis pembatas (§4.4:
  *"Proximity carries the grouping — do not reach for divider lines"*); perluas bidang merek
  (footer, medallion) sehingga `primary` tidak lagi hanya mengisi satu elemen (§2.2). Termasuk U7:
  beri nomor bantuan pada banner ketersediaan bobot visual tombol, **tanpa** menambah janji layanan
  apa pun (N10).
- [ ] **Tahap 3 — Mobile: naikkan CTA.** Terapkan M2 (§7), ukur ulang posisi CTA pada 360×740,
  laporkan angkanya. **Tidak boleh terpisah lebih dari satu rilis dari Tahap 1**, karena Tahap 1
  memanjangkan halaman.
- [ ] **Tahap 4 — Hierarki komponen.** Varian penekanan `<x-mk.card>`; perbesar
  `<x-mk.icon-medallion>` pada kartu layanan; judul dua nada (A9/U8); tarik baris kartu naik ke
  tepi bawah media hero (A10/U9); beri bobot panel teks `<x-mk.hero>`. Tanpa scrim, tanpa overlay —
  hero terpadu A6 menunggu fotografi (OQ-K5).
- [ ] **Tahap 5 — Register citra.** Terapkan aturan §9.3 setelah OQ-K3/OQ-K10 dijawab; bangun
  turunan AVIF/WebP untuk media kartu; perbaiki `alt`. **Terblokir pada input pemilik.**
- [ ] **Tahap 6 — Dua jalur di layar pertama (U2/U3/A11).** Nyatakan At-Need vs Pre-Need pada
  beranda dengan harga mulai memakai `--mk-text-price` — token yang ADR-0037 buat persis untuk ini
  dan yang hari ini belum dipakai di beranda. **Perubahan §4.5 — butuh review kontrak produk, bukan
  keputusan desain** (OQ-K11).
- [ ] **Tahap 7 — Suara salinan.** Hero dan judul section bergeser ke orang kedua yang hangat,
  **tanpa** eufemisme (A7 + N8). Butuh persetujuan pemilik atas teksnya.
- [ ] **Tahap 8 — Sinkronisasi dokumen.** `design-system.md` §2.1 SURFACES, §2.2 (aturan register
  citra §9.3), §4.4 (utilitas baru), §6 (skeleton mengikuti ritme baru) + CHANGELOG.

Tahap 1–2 tidak bergantung pada input siapa pun dan bisa jalan sekarang. Tahap 3 dan 4 bisa jalan
paralel. Tahap 5–7 menunggu pemilik. Tahap 6 menunggu review kontrak produk.

---

## 13. Hubungan dengan dokumen yang sudah ada — bukan dokumen saingan

`AGENTS.md` §Documentation melarang menduplikasi data kanonik. Benchmark kamboja **sudah pernah
dikerjakan di repo ini**, dan rencana ini melanjutkannya, tidak menggantikannya:

| Dokumen | Yang sudah diputuskan di sana | Posisi rencana ini |
|---|---|---|
| [ADR-0037](../../adr/0037-price-emphasis-and-one-accent-one-purpose.md) (26 Agu 2026) | Tiga rekomendasi dari review benchmark kamboja. Rek. 1 → `--mk-text-price`. Rek. 2 → pelengkap positif §2.2. Rek. 3 (sinyal kepercayaan) → **ditahan** | Menghormati ketiganya. N7 menegaskan ulang penahanan rek. 3; U3/Tahap 6 akhirnya memakai `--mk-text-price` untuk tujuan aslinya |
| `design-system.md` §2.2 (26 Agu 2026) | Pelengkap citra: *"prefer candid warmth and connection"* saat ada orang dalam bingkai | §1.5 menambah bukti tersampel; §9.3 menambah aturan register yang §2.2 belum punya |
| `design-system.md` §3.3d (26 Agu 2026) | `<x-mk.trust-badge-strip>` RESERVED, NOT BUILT | Tidak dibangun. Tidak ada perubahan status |
| `design-system.md` §3.11 | Navigasi bawah PROPOSED, NOT APPROVED (OQ-04) | Tidak dibangun (§7) |
| `app/Support/Design/StatusIntent.php` | Satu tempat resolusi status → intent | Tidak disentuh (§5.1) |
| [Spec brand refresh](../specs/2026-08-21-brand-visual-refresh-design.md) (21 Agu 2026) | Arah "lighter, younger, warmer"; fase 1–3 | Ini adalah pendahulu Fase 3 yang belum punya rencana |
| [Fase 1](2026-08-21-brand-visual-refresh-phase1-foundation.md), [Fase 2](2026-08-25-brand-visual-refresh-phase2-homepage.md) | Palet di-anchor ulang; `<x-mk.hero>` dipasang | Beranda yang dinilai "plain" oleh pemilik **adalah hasil Fase 2**. §2 mendiagnosis mengapa |
| [`benchmark-validation-2026-07.md`](../../research/benchmark-validation-2026-07.md) | kamboja dikutip untuk At-Need/Pre-Need, bukan desain | Dipakai sebagai dasar U2 (dua journey), tidak diubah |
| `CemeteryExampleData.php` doc block | Kerangka kejujuran + pembalikan foto 8 Sep 2026 | §9 membangun di atasnya; tidak ada pembalikan sepihak |

### 13.1 Satu ketidakcocokan — diangkat, lalu diuji, lalu **diselesaikan**

Urutannya dibiarkan terlihat karena itulah nilainya: ditandai belum terverifikasi, diuji, lalu
dikoreksi. Bukan dihapus dan diganti kesimpulan akhir.

**Apa yang dinyatakan dokumen repo.** `design-system.md` §2.2 dan ADR-0037 Context 2 sama-sama
menyatakan:

> *"kamboja.co.id's hero photography is deliberately warm, joyful family photography — grandparents
> with grandchildren, a father with his kids"*

**Apa yang arsip tunjukkan (dan mengapa itu menyesatkan).** DOM terarsip 10 Mei 2026 **tidak memuat
foto apa pun di hero** — section 0 hanya berisi `google-review.png` dan `trustpilot-logo.png`. Atas
dasar itu dokumen ini semula menandai pernyataan §2.2 sebagai **NOT VERIFIED**, mendaftar tiga
kemungkinan penjelasan, dan sengaja **tidak** menyatakan §2.2 salah.

**Apa yang tangkapan hidup tunjukkan — 13 Sep 2026 (§0.3).** Hero **memang** memakai fotografi
keluarga yang hangat. Foto *full-bleed* menempati kira-kira 55% kanan hero dan mengalir ke belakang
salinan di kiri: seorang ayah menggendong anak di punggung, satu anak lagi, dan seorang perempuan,
semuanya tertawa, di luar ruang, cahaya siang, kedalaman ruang dangkal. Kandid dan dokumenter —
bukan pose stok.

**Putusan: §2.2 dan ADR-0037 BENAR. Arsipnya yang tidak lengkap.** Penjelasan (a) yang didaftar
semula terbukti: foto hero adalah gambar latar CSS yang hidup di CSS per-halaman Oxygen, dan CSS itu
tertangkap 0 byte (§0.2). Tidak ada dokumen repo yang perlu diperbaiki. Yang perlu diperbaiki adalah
kepercayaan terhadap arsip sebagai bukti tata letak — dan itu sudah tercermin di §15.

**Konsekuensi untuk rencana ini.** §3.1 A6 semula dirumuskan hati-hati sebagai "beri bobot pada
panel teks" justru supaya benar di kedua kemungkinan. Kini kemungkinannya tertutup, jadi A6 boleh
dinyatakan lebih kuat — lihat §4.3, yang **dikoreksi**: kamboja memang menaruh teks di atas foto,
tetapi tanpa scrim gelap. Itu mengubah rekomendasi teknis, bukan hanya catatan kaki.

---

## 14. Pertanyaan terbuka untuk pemilik produk

Pemilik menulis bahwa brand guideline **belum** ada. Rencana ini **tidak** mengarang satu pun dan
tidak menyajikan apa pun sebagai sudah disepakati.

| ID | Pertanyaan | Mengapa menghalangi |
|---|---|---|
| **OQ-K1** | ~~Desktop~~ **TERJAWAB 13 Sep 2026** (§0.3) — menyelesaikan §13.1. **Yang masih dibutuhkan: tangkapan MOBILE nyata** (perangkat asli atau device-mode DevTools, bukan jendela sempit) | Chrome menahan lebar jendela minimum, sehingga percobaan 390×844 menghasilkan tata letak desktop pada 1200px. **Seluruh perilaku mobile kamboja tetap NOT VERIFIED** (§1.4b, §15) — dan mobile adalah kasus utama produk ini (§7) |
| ~~**OQ-K2**~~ **TERJAWAB 13 Sep 2026** | Pemilik: *"lebih ke layouting, images dan lainnya, pertahankan identitas brand makam"* — yaitu **(b)**. | **Asumsi rencana ini dikonfirmasi.** ADR-0034 dan §2.3 tetap berdiri; tidak ada ADR pembatal yang dibutuhkan. Magenta, gradien, tombol pil, dan mask blob tetap di luar cakupan — bukan karena rencana ini menolaknya, tapi karena pemilik meminta identitas makam dipertahankan. Setiap tahap boleh berjalan apa adanya. |
| **OQ-K3** | Apakah ada foto TPU/TPS nyata — milik sendiri atau berlisensi — untuk lokasi yang tampil di direktori? Satu per lokasi | **Memblokir Tahap 5.** Hari ini empat foto dipakai bergilir untuk sembilan lokasi fiktif (§9.1) |
| **OQ-K10** | Jika jawaban OQ-K3 tidak, pilih satu: **(i)** kembali ke ilustrasi untuk lokasi tanpa foto sendiri (§9.3, menyelaraskan dengan marketplace); **(ii)** pertahankan foto stok tetapi tambahkan kapsion sumber terlihat di setiap kartu, seperti disclaimer kamboja; **(iii)** pertahankan apa adanya | Arahan 8 Sep 2026 memilih foto stok secara eksplisit. Rekomendasi saya **(i)**, dengan **(ii)** sebagai kompromi yang dapat diterima. **(iii)** membuat klaim yang tidak bisa dipertanggungjawabkan (§9.2) |
| **OQ-K4** | Apakah Makam.co.id boleh memotret layanan nyata, dengan izin keluarga, seperti yang dilakukan kamboja? | Ini sumber kehangatan terbesar kamboja (§1.5) dan tidak bisa digantikan token. Butuh keputusan operasional + persetujuan, bukan keputusan desain |
| **OQ-K5** | Apakah teks hero harus berada **di atas** foto? | Butuh token scrim + ADR + metode verifikasi baru (§4.3). Sengaja di luar tahap mana pun |
| **OQ-K6** | Apakah sudah ada mitra, sertifikasi, listing ulasan, atau liputan pers yang **nyata**? | Menentukan kapan §3.3d boleh dibangun dan apakah U1 bisa diadopsi. Selama jawabannya tidak, strip kepercayaan tetap tidak dibangun (N7) |
| **OQ-K7** | Bolehkah salinan bergeser ke orang kedua yang hangat ("Untuk keluarga Anda"), dengan tetap menyebut hal apa adanya tanpa eufemisme? | **Memblokir Tahap 7.** N8 menolak eufemisme kamboja; A7 menerima kehangatannya. Batas ini milik pemilik |
| **OQ-K8** | Kalau nanti brand guideline terbit dan bertabrakan dengan ADR-0034, mana yang menang? | ADR-0034 menutup OQ-01 dan OQ-12 dengan nilai dari logo asli. Guideline baru butuh ADR yang membatalkannya secara eksplisit |
| **OQ-K9** | Bolehkah simbol agama (bulan-bintang, salib, guci) muncul di antarmuka publik seperti pada kamboja? | Keputusan produk, bukan estetika (§8.2). Berdampak pada ikonografi layanan |
| **OQ-K11** | Bolehkah beranda menyatakan dua jalur — At-Need vs Pre-Need — di layar pertama? | **Memblokir Tahap 6**, karena §4.5 adalah kontrak produk. U2/U3 mengusulkannya; keputusannya bukan milik desain |

---

## 15. NOT VERIFIED — daftar lengkap

- **Tampilan mobile kamboja — seluruhnya.** Ini celah terbesar yang tersisa. Tangkapan hidup §0.3
  hanya desktop: upaya mengecilkan jendela ke 390×844 dilaporkan berhasil tetapi viewport terender
  tetap 1200px, sehingga hasilnya tata letak desktop pada jendela sempit. Blok
  `@media (max-width: 479px)` di `universal.css` hanya mengubah ±50 deklarasi, jadi arsip juga
  tidak menutupinya (§1.4b). **Tidak ada satu pun klaim mobile kamboja di dokumen ini**, dan tidak
  satu pun disimpulkan dari tangkapan desktop. OQ-K1 separuh-mobile tetap terbuka.
- Tampilan kamboja **di bawah lipatan hero** pada 13 Sep 2026. Tangkapan hidup menutup hero, baris
  kartu, dan testimoni. Section lain (layanan lainnya, mitra, aktivitas layanan, FAQ, liputan pers,
  footer) masih bersandar pada arsip 10 Mei 2026.
- *Padding* tingkat-section kamboja dan gambar latar section di luar hero — CSS per-halaman tidak
  terarsip (§0.2). Tinggi section di §1.4 berasal dari rekonstruksi dan hanya indikatif.
- Nilai hex persis untuk aksen uang hijau kamboja (§1.5b). Terlihat jelas hijau dan berbeda dari
  magenta merek, tetapi dibaca dari tangkapan JPEG, bukan disampling dari CSS — `#41B78A` dan
  `#07BA28` ada di `universal.css`, tetapi mana yang dipakai untuk angka uang tidak dipastikan.
  Tidak berdampak: Makam memakai `--mk-text-price` sendiri, bukan warna kamboja.
- Apakah elemen < 44px di §2.7 benar-benar melanggar §7.3 — yang diukur kotak elemen, bukan area
  sentuh. Verifikasi masuk Tahap 1.
- Jumlah total baris `cemeteries` di basis data produksi. Yang dihitung adalah **sembilan lokasi
  yang tampil** di direktori publik; jumlah baris termasuk yang tidak dipublikasikan tidak
  diperiksa.
- Tampilan `dev.makam.co.id`. Hanya `makam.co.id` produksi yang diperiksa; keduanya meresolusi ke
  103.92.214.243, tetapi tidak diasumsikan identik.
- Tampilan panel Filament dan peta petak `plot-floor-map` — sengaja di luar cakupan (§11.15), tidak
  dimuat.
- State layar §6 selain yang kebetulan terlihat (empty-state pratinjau petak, banner `warning`).
  Loading, error, otorisasi, dan pending **tidak** dipicu atau diamati; §6 adalah usulan atas dasar
  dokumen, bukan atas dasar pengamatan.
- Dampak visual dari setiap usulan. Tidak ada yang diimplementasikan — ini rencana.
- ~~Klaim ADR-0037 soal aksen uang~~ — **TERKONFIRMASI 13 Sep 2026**, dipindahkan ke §1.5b. Angka
  uang kamboja dirender hijau dalam muka huruf tersendiri, terpisah dari magenta merek. Separuh
  klaim yang lain (*"exactly one accent colour for urgency"*) juga konsisten dengan tangkapan:
  banner darurat memakai magenta merek, bukan warna keempat. Dicatat di sini sebagai riwayat, bukan
  sebagai celah.
