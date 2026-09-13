# Bahasa Desain kamboja.co.id untuk Makam.co.id — Rencana

> **Untuk agen pelaksana:** SUB-SKILL WAJIB: `superpowers:subagent-driven-development` atau
> `superpowers:executing-plans`. Setiap tahap memakai sintaks checkbox (`- [ ]`).
>
> **Dokumen ini adalah RENCANA, bukan implementasi.** PR yang memuatnya tidak boleh menyentuh
> satu pun file Blade, CSS, atau token.

**Tujuan:** Menjawab masukan pemilik produk — *"utk design makam nya nnti dibuat ky kamboja ajaa..
mgkn itu kelihatan msh plain krn aku blm ksh brand guideline sehingga blm ada corak2 warna nya
yaa"* — dengan memisahkan dua hal yang berbeda: **bahasa desain** (ritme tata letak, strategi
citra, suara tipografi, kepadatan, gerak, cara kehangatan dibentuk) yang boleh diambil dari
kamboja.co.id, dan **identitas merek** (palet, logo, nama, nilai token) yang tetap milik
Makam.co.id dan sudah dikunci [ADR-0034](../../adr/0034-adopt-makam-brand-identity.md).

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

**Satu-satunya cara menutup celah ini:** tangkapan layar dari mesin pemilik produk sendiri
(desktop + mobile, halaman penuh). Lihat §9 OQ-K1.

### 0.3 Bukti makam.co.id

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

Breakpoint: `max-width: 479px` (33 aturan), `767px` (22), `991px` (17), `1120px` (7) — jelas
mobile-first dengan pengerjaan terbanyak di layar kecil.

Yang perlu dicatat: **halaman ini panjang dan longgar.** Satu section bisa 2.000–10.000px. Tidak ada
usaha memadatkan.

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

### 2.6 Yang sudah benar dan tidak perlu disentuh

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
| A6 | Hero yang dipimpin tipografi, dengan sepasang CTA dan bukti langsung | Beri bobot pada panel teks hero; foto tetap terpisah | Hero kamboja sendiri **tidak** punya foto — hanya logo Google Review + Trustpilot |
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
itulah yang direncanakan dokumen ini. Konfirmasi bacaan ini ada di §9 OQ-K2.

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
| Tahap 1 (ritme) | tidak ada | tidak |
| Tahap 2 (`--mk-surface-quiet`) | `text-default on secondary-50`, `text-strong on secondary-50` (sudah ada, baris 93–94) | **tidak** |
| Tahap 3 (citra) | tidak ada | tidak |
| Tahap 4 (kartu) | tidak ada | tidak |
| Tahap 5 (salinan) | tidak ada | tidak |

**Hitungan pasang tetap 49 di seluruh rencana ini.** Satu-satunya usulan yang bisa mengubahnya
adalah scrim hero opsional di §4.3, yang sengaja tidak masuk tahap mana pun.

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

### 4.3 Opsional, di luar tahap mana pun — scrim hero

Jika nanti diputuskan teks hero harus berada **di atas** foto (bukan di bawahnya seperti §2.4),
maka diperlukan satu token scrim, misalnya `--mk-scrim-hero`. Ini **tidak** diusulkan dalam
rencana ini karena dua alasan:

1. Hero kamboja sendiri tidak melakukannya — device itu tidak ada dalam bukti. Mengusulkannya
   berarti mengarang.
2. **`verify-contrast.py` tidak bisa menegaskan kontras teks di atas foto.** Ia membandingkan dua
   token warna; sebuah foto bukan token. Menambahkan pasang palsu "putih di atas scrim" akan lulus
   gate sambil tidak membuktikan apa pun tentang piksel yang sebenarnya.

Jika hal ini tetap diinginkan, ia butuh ADR sendiri **dan** metode verifikasi baru (pengukuran
piksel terender pada gambar terburuk), bukan sekadar entri di `PAIRS`. Dicatat di §9 OQ-K5.

---

## 5. Perubahan per komponen — file nyata

Semua path diverifikasi ada pada `7d3bcb81`.

| File | Perubahan | Tahap |
|---|---|---|
| `resources/views/livewire/public/home-page.blade.php` | Ganti 8 kemunculan `py-5 lg:py-8` / `py-8` pada `<section>` menjadi `section-y lg:section-y-lg`. Tambahkan alternasi tint. Tidak ada section ditambah, dihapus, atau **diurut ulang** — §4.5 adalah kontrak produk | 1, 2 |
| `resources/css/app.css` | Dua `@utility` baru (§4.2b) | 1 |
| `resources/css/tokens.css` | Satu alias semantik `--mk-surface-quiet` (§4.2a) | 2 |
| `resources/views/components/mk/card.blade.php` | Tambah varian penekanan agar kartu layanan ≠ baris FAQ (§2.3). Varian baru, default tidak berubah | 4 |
| `resources/views/components/mk/hero.blade.php` | Beri bobot lebih pada panel teks; ruang untuk baris pendukung. **Tanpa** overlay, tanpa scrim (§4.3) | 4 |
| `public/images/cemeteries/` | Ganti tiga foto drone daur ulang dengan satu foto per lokasi; bangun turunan AVIF/WebP seperti hero | 3 |
| `public/images/home/family-warmth.jpg` | Ganti stok "BALI" dengan foto yang relevan, atau hapus sectionnya | 3 |
| `app/Livewire/Public/HomePage.php` | Hanya jika salinan hero berubah (§5 Tahap 5) | 5 |
| `docs/design/design-system.md` | Sinkronkan §2.2 (temuan §1.5), §4.4 (utilitas baru), §2.1 SURFACES | tiap tahap |

**Komponen yang sengaja TIDAK disentuh:** `sticky-comparison-rail.blade.php`,
`icon-medallion.blade.php`, `badge.blade.php`, `stepper.blade.php`, `alert.blade.php` — tidak ada
temuan di §2 yang menyentuhnya. `<x-mk.trust-badge-strip>` **tidak dibangun** (N7).

---

## 6. Yang TIDAK boleh berubah

1. **Palet ADR-0034.** Earth brown `primary`, Leaf green `secondary` dalam kurungannya, hue
   `danger` 352°, `--mk-surface-warm` menunjuk `primary-50`. Semuanya disampling dari logo asli
   (OQ-12 tertutup).
2. **Poppins display / Inter body / Source Serif dokumen** — ADR-0034 D7.
3. **Kurungan `secondary`** — 50–200 tint, 300–400 dekoratif, 700–900 teks di atas tint. **Tidak
   pernah** fill, badge, tombol, atau alert (§1.2b, §9.2 MUST NOT 7).
4. **Urutan sembilan section beranda** — §4.5 menyebutnya *"a product contract, not a design
   preference"*.
5. **Satu aksi primer per tampilan** — §2.3 DO.
6. **`--mk-text-price` hanya untuk angka uang final** — ADR-0037.
7. **Larangan pil / gradien / bayangan berwarna / mode gelap** — §2.3, §1.7, OQ-07.
8. **Banner ketersediaan yang jujur** tetap di atas halaman selama `G-OPS-01` tertutup.
9. **Nol permintaan pihak ketiga di halaman publik** — §4.6. Ini berarti font tetap self-hosted;
   kamboja memuat `fonts.googleapis.com`, Makam tidak boleh.
10. **§3.3d `<x-mk.trust-badge-strip>` tetap RESERVED, NOT BUILT** sampai ada konten nyata.

---

## 7. Urutan bertahap — satu PR per tahap

Tiap tahap berdiri sendiri, bisa dikirim dan bisa dibalik sendiri. Tiap tahap wajib lulus
`bash ci/verify-docs.sh`, `php artisan design:verify-filament-palette`,
`php artisan blade:verify-content-survival`, dan suite `tests/browser/*.spec.ts`.

- [ ] **Tahap 1 — Ritme vertikal.** Dua `@utility` di `app.css`; ganti `py-5 lg:py-8` menjadi
  `section-y lg:section-y-lg` di beranda. Nol perubahan warna, nol perubahan salinan, nol
  perubahan urutan. Ini perbaikan kepatuhan terhadap §4.4, jadi sekaligus yang paling mudah
  dipertahankan di review. **Perubahan visual terbesar per baris kode dari seluruh rencana.**
- [ ] **Tahap 2 — Alternasi permukaan.** Tambah `--mk-surface-quiet` (butuh ADR); terapkan
  selang-seling tint antar section sehingga batas section terbaca tanpa garis pembatas (§4.4:
  *"Proximity carries the grouping — do not reach for divider lines"*).
- [ ] **Tahap 3 — Citra.** Satu foto nyata per TPU/TPS, hentikan daur ulang, bangun turunan
  AVIF/WebP, patuhi GATE 14. **Terblokir pada input pemilik** — lihat §9 OQ-K3.
- [ ] **Tahap 4 — Hierarki komponen.** Varian penekanan `<x-mk.card>`; bobot panel teks
  `<x-mk.hero>`. Tanpa scrim, tanpa overlay.
- [ ] **Tahap 5 — Suara salinan.** Hero dan judul section bergeser ke orang kedua yang hangat,
  **tanpa** eufemisme (A7 + N8). Butuh persetujuan pemilik atas teksnya.
- [ ] **Tahap 6 — Sinkronisasi dokumen.** `design-system.md` §2.2/§4.4/§2.1 + CHANGELOG.

Tahap 1–2 tidak bergantung pada input siapa pun dan bisa jalan sekarang. Tahap 3 dan 5 menunggu
pemilik. Tahap 4 bisa jalan paralel dengan 3.

---

## 8. Hubungan dengan dokumen yang sudah ada — bukan dokumen saingan

`AGENTS.md` §Documentation melarang menduplikasi data kanonik. Benchmark kamboja **sudah pernah
dikerjakan di repo ini**, dan rencana ini melanjutkannya, tidak menggantikannya:

| Dokumen | Yang sudah diputuskan di sana | Posisi rencana ini |
|---|---|---|
| [ADR-0037](../../adr/0037-price-emphasis-and-one-accent-one-purpose.md) (26 Agu 2026) | Tiga rekomendasi dari review benchmark kamboja. Rek. 1 → `--mk-text-price`. Rek. 2 → pelengkap positif §2.2. Rek. 3 (sinyal kepercayaan) → **ditahan** | Menghormati ketiganya. N7 menegaskan ulang penahanan rek. 3 |
| `design-system.md` §2.2 (26 Agu 2026) | Pelengkap citra: *"prefer candid warmth and connection"* saat ada orang dalam bingkai | §1.5 menambah bukti tersampel; §3.1 A3 menambah dimensi kepemilikan dan izin |
| `design-system.md` §3.3d (26 Agu 2026) | `<x-mk.trust-badge-strip>` RESERVED, NOT BUILT | Tidak dibangun. Tidak ada perubahan status |
| [Spec brand refresh](../specs/2026-08-21-brand-visual-refresh-design.md) (21 Agu 2026) | Arah "lighter, younger, warmer"; fase 1–3 | Ini adalah pendahulu Fase 3 yang belum punya rencana |
| [Fase 1](2026-08-21-brand-visual-refresh-phase1-foundation.md), [Fase 2](2026-08-25-brand-visual-refresh-phase2-homepage.md) | Palet di-anchor ulang; `<x-mk.hero>` dipasang | Beranda yang dinilai "plain" oleh pemilik **adalah hasil Fase 2**. §2 mendiagnosis mengapa |
| [`benchmark-validation-2026-07.md`](../../research/benchmark-validation-2026-07.md) | kamboja dikutip untuk At-Need/Pre-Need, bukan desain | Tidak tersentuh |

### 8.1 Satu ketidakcocokan yang harus diselesaikan, bukan dipilih diam-diam

`design-system.md` §2.2 dan ADR-0037 Context 2 sama-sama menyatakan:

> *"kamboja.co.id's hero photography is deliberately warm, joyful family photography — grandparents
> with grandchildren, a father with his kids"*

Bukti yang terkumpul untuk dokumen ini **tidak menemukan foto apa pun di hero**. Section 0 pada DOM
terarsip 10 Mei 2026 hanya memuat dua gambar: `google-review.png` dan `trustpilot-logo.png`. Foto
keluarga hangat memang ada di situs (`proteksi-family.jpg`, 1046×572), tetapi pada section 9, bukan
hero.

**Tiga penjelasan mungkin, semuanya belum diuji:** (a) foto hero adalah `background-image` CSS yang
berada di CSS per-halaman yang tidak terarsip (§0.2); (b) situs berubah antara 26 Agu 2026 dan
snapshot 10 Mei 2026 — perhatikan bahwa snapshot ini **lebih tua** dari review 26 Agu, sehingga
urutan waktunya tidak sederhana; (c) review 26 Agu melihat halaman lain.

**Status: NOT VERIFIED.** Rencana ini tidak mengubah §2.2 dan tidak menyatakan §2.2 salah. Tangkapan
layar dari pemilik (OQ-K1) menyelesaikannya. Sampai itu, §3.1 A6 sengaja dirumuskan sebagai "beri
bobot pada panel teks", bukan "tiru hero berfoto", karena rumusan itu benar di kedua kemungkinan.

---

## 9. Pertanyaan terbuka untuk pemilik produk

Pemilik menulis bahwa brand guideline **belum** ada. Rencana ini **tidak** mengarang satu pun dan
tidak menyajikan apa pun sebagai sudah disepakati.

| ID | Pertanyaan | Mengapa menghalangi |
|---|---|---|
| **OQ-K1** | Bisakah dikirim tangkapan layar halaman penuh kamboja.co.id (desktop + mobile) dari mesin sendiri? | Situs diblokir DNS dari host ini (§0.1). Bukti saat ini berumur 4 bulan dan CSS per-halaman hilang. Ini juga menyelesaikan ketidakcocokan §8.1 |
| **OQ-K2** | "Dibuat seperti kamboja" berarti (a) **warna dan bentuknya** — magenta, gradien, tombol pil — atau (b) **kehangatan dan kepenuhannya**, dengan identitas Earth/Leaf tetap? | Jika (a), ADR-0034 dan §2.3 harus dibatalkan lewat ADR baru, dan seluruh rencana ini berubah. Rencana ini mengasumsikan **(b)** |
| **OQ-K3** | Apakah ada foto TPU/TPS nyata — milik sendiri atau berlisensi — untuk enam lokasi di beranda? Satu per lokasi | **Memblokir Tahap 3.** Hari ini tiga foto dipakai untuk enam lokasi (§2.5). Tidak akan ada foto yang dikarang atau lokasi yang salah dilabeli |
| **OQ-K4** | Apakah Makam.co.id boleh memotret layanan nyata, dengan izin keluarga, seperti yang dilakukan kamboja? | Ini sumber kehangatan terbesar kamboja (§1.5) dan tidak bisa digantikan token. Butuh keputusan operasional + persetujuan, bukan keputusan desain |
| **OQ-K5** | Apakah teks hero harus berada **di atas** foto? | Butuh token scrim + ADR + metode verifikasi baru (§4.3). Sengaja di luar tahap mana pun |
| **OQ-K6** | Apakah sudah ada mitra, sertifikasi, listing ulasan, atau liputan pers yang **nyata**? | Menentukan kapan §3.3d boleh dibangun. Selama jawabannya tidak, strip kepercayaan tetap tidak dibangun (N7) |
| **OQ-K7** | Bolehkah salinan bergeser ke orang kedua yang hangat ("Untuk keluarga Anda"), dengan tetap menyebut hal apa adanya tanpa eufemisme? | **Memblokir Tahap 5.** N8 menolak eufemisme kamboja; A7 menerima kehangatannya. Batas ini milik pemilik |
| **OQ-K8** | Kalau nanti brand guideline terbit dan bertabrakan dengan ADR-0034, mana yang menang? | ADR-0034 menutup OQ-01 dan OQ-12 dengan nilai dari logo asli. Guideline baru butuh ADR yang membatalkannya secara eksplisit |

---

## 10. NOT VERIFIED — daftar lengkap

- Tampilan kamboja.co.id **hari ini**. Semua bukti dari arsip; halaman 10 Mei 2026, stylesheet
  30 Mei 2026.
- *Padding* tingkat-section kamboja, gambar latar section, dan warna final tombol non-hero — CSS
  per-halaman tidak terarsip (§0.2). Tinggi section di §1.4 berasal dari rekonstruksi dan hanya
  indikatif.
- Apakah hero kamboja memuat foto (§8.1).
- Tampilan `dev.makam.co.id`. Hanya `makam.co.id` produksi yang diperiksa; keduanya meresolusi ke
  103.92.214.243, tetapi tidak diasumsikan identik.
- Tampilan mobile Makam.co.id pada 360px. Semua pengukuran §2 pada 1440×900.
- Dampak visual dari setiap usulan. Tidak ada yang diimplementasikan — ini rencana.
- Klaim ADR-0037 bahwa kamboja memakai *"exactly one accent colour for urgency … and exactly one,
  different, accent colour for money"*. Sampling `universal.css` menemukan `#D23574` mendominasi
  dan aksen kecil `#41B78A`, `#07BA28`, `#F70000`, tetapi peran per-aksen tidak dapat dipisahkan
  tanpa CSS per-halaman. Tidak dikonfirmasi dan tidak dibantah.
