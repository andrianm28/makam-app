# Bahasa Desain FFI untuk Makam.co.id — Spesifikasi

**22 September 2026.** Menjawab permintaan pemilik proyek *"owner minta
tampilannya sama dengan ffi"*, menunjuk `/home/ubuntu/fundforindonesia.org`.

> **DISUPERSEDE 22 Sep 2026, sore, oleh
> [`2026-09-22-ffi-full-visual-clone-design.md`](2026-09-22-ffi-full-visual-clone-design.md).**
> Dokumen ini ditulis di bawah ADR-0042 (hanya lapisan sistem yang
> menyeberang). Hari yang sama, pemilik proyek menegaskan lewat instruksi
> lebih kuat — *"owner minta visual ui persis seperti ffi"* — bahwa itu
> termasuk palet, tipografi, dan struktur halaman, bukan lapisan sistem
> saja. ADR-0043 mencatat pembalikan itu. Dokumen ini dipertahankan apa
> adanya, bukan ditulis ulang, sesuai konvensi repo untuk keputusan yang
> disupersede: temuan Kitabisa, inventarisasi lapisan sistem, dan
> pemeriksaan kepatuhan PRD di dalamnya tetap akurat sebagai catatan kapan
> ditulis, dan sebagian besar diwarisi langsung oleh dokumen penggantinya.

Keputusannya ada di [ADR-0042](../../adr/0042-ffi-system-layer-is-the-design-language-source.md)
(disupersede oleh [ADR-0043](../../adr/0043-ffi-full-visual-clone-supersedes-system-layer-only.md));
dokumen ini adalah spesifikasinya. Konteks untuk pemilik ada di
[`catatan-pemilik-2026-09-22-tampilan-ffi.md`](../../product/catatan-pemilik-2026-09-22-tampilan-ffi.md).

> **Ini spesifikasi, bukan implementasi.** PR yang memuatnya tidak menyentuh
> satu pun berkas Blade, CSS, atau token. Rencana pelaksanaannya menyusul
> terpisah, sesuai `AGENTS.md` §Development methodology.

---

## 0. Temuan yang membentuk seluruh dokumen ini

Permintaannya mengandaikan Makam perlu mengambil lapisan sistem dari FFI.
Setelah berkas token dibaca, bukan dikira-kira, andaian itu **tidak benar**.

| Lapisan | FFI | Makam hari ini | Perlu diambil? |
|---|---|---|---|
| Skala sudut | 8 / 12 / 16 px | `--radius-md` 8px, `--radius-lg` 12px, `--radius-xl` 16px, plus xs/sm/2xl/full | Tidak. Sudah sama, dan lebih lengkap |
| Bayangan | 2 tingkat (`card`, `elevated`) | `--shadow-xs` sampai `--shadow-xl`, lima tingkat, semuanya bernada `rgb(13 17 17 / …)` bukan hitam murni | Tidak |
| Kerangka memuat | konvensi shimmer | `--mk-skeleton-base`, `--mk-skeleton-sheen`, plus aturan §6.1 termasuk syarat ritme anti-CLS | Tidak |
| Komponen kerangka | ada | **tidak ada** | **Ya** |
| Navigasi bawah ponsel | ada, 5 tab | **tidak ada**; §3.11 menyebutnya "PROPOSED, NOT APPROVED" | **Ya** |

**Konsekuensinya:** menyalin token FFI tidak akan mengubah apa pun yang
dilihat pemilik. Perbedaan yang beliau rasakan berasal dari tempat lain.
Rencana Kamboja sudah mendiagnosis tiga mekanismenya terhadap bukti terukur,
dan yang pertama adalah ritme vertikal yang dipakai setengah dari yang
diwajibkan — mekanisme itu sudah diperbaiki di Tahap 1 dan 2 yang sudah merge.

Maka dokumen ini punya dua bagian yang jujur: **dua komponen yang benar-benar
kurang** (§2, §3), dan **satu daftar penolakan sadar** (§4) agar tidak ada
yang mengira bagian FFI yang tidak diambil itu terlewat.

---

## 1. Yang tidak berubah, dan kenapa disebut di sini

Agar pelaksana tidak perlu menebak batasnya.

| Hal | Otoritas | Status |
|---|---|---|
| Palet Forest / Sage / Sand / Ivory / Charcoal | ADR-0041, dari pedoman merek yang diserahkan pemilik | Tetap. Tidak ada nilai warna FFI yang masuk |
| Plus Jakarta Sans | ADR-0041, halaman 08 pedoman | Tetap. Inter tidak masuk |
| Empat kartu layanan beranda | `AGENTS.md` §Mandatory MVP UX, PRD §1 | Tetap: Pemesanan, Layanan, Perpanjangan, FAQ |
| Sepuluh state layar wajib | `design-system.md` §6 | Tetap. Gaya FFI hanya boleh mengisi §6.1, tidak mengurangi satu pun |
| Larangan geometri pil | komentar pada `--radius-full` | Tetap. Header FFI memakai bilah pencarian pil; itu tidak diikuti |
| Register citra | pedoman merek halaman 09 | Tetap. Melarang foto stok yang dipentaskan dan dramatisasi duka |
| Suara salinan | pedoman merek halaman 01–03 | Tetap. Anti hard-selling |

---

## 2. Komponen kerangka — `<x-mk.skeleton>`

### Kenapa perlu

Aturannya sudah ada di `design-system.md` §6.1, tetapi tidak ada primitifnya,
sehingga tiap layar menulis sendiri `bg-[var(--mk-skeleton-base)] rounded-md
animate-pulse`. Tiga akibatnya nyata:

1. **Gerbang CI kecolongan secara diam-diam.** GATE 3 melarang nilai arbitrer
   Tailwind untuk keputusan desain. Bentuk `bg-[var(--mk-skeleton-base)]`
   lolos karena isinya token, tetapi polanya tetap menyalin kelas utilitas ke
   belasan tempat.
2. **Addendum 14 Sep tidak terjamin.** §6.1 mewajibkan kerangka tingkat
   halaman memakai `py-section lg:py-section-lg` yang sama dengan seksi yang
   diwakilinya, supaya tidak menggeser tata letak. Aturan yang hanya hidup di
   dokumen tidak dijalankan oleh apa pun.
3. **Teks pembaca layar mudah lupa.** §6.1 mewajibkan `sr-only` yang
   mengumumkan apa yang sedang dimuat.

### Kontrak

```blade
<x-mk.skeleton :lines="3" />
<x-mk.skeleton shape="card" :count="4" />
<x-mk.skeleton shape="section" section-rhythm announce="Memuat hasil pencarian…" />
```

| Prop | Nilai | Default | Arti |
|---|---|---|---|
| `shape` | `text` \| `card` \| `media` \| `section` | `text` | Bentuk yang ditiru |
| `lines` | integer | 3 | Hanya untuk `shape=text` |
| `count` | integer | 1 | Berapa banyak bentuk diulang |
| `section-rhythm` | boolean | false | Menerapkan `py-section lg:py-section-lg`, wajib untuk `shape=section` |
| `announce` | string | `Memuat…` | Isi `sr-only` |

**Aturan yang dipaksakan komponen, bukan diserahkan ke pemanggil.**

- Selalu memancarkan `aria-busy="true"` pada pembungkusnya dan satu `sr-only`
  berisi `announce`. Tidak ada cara memakai komponen ini tanpa pengumuman.
- `shape=section` **menolak** `section-rhythm=false`; itu satu-satunya cara
  addendum §6.1 dijamin.
- Tidak menerima kelas warna dari luar. Warnanya selalu
  `--mk-skeleton-base` dan `--mk-skeleton-sheen`.
- Menghormati `prefers-reduced-motion`: animasi denyut mati, warna dasar
  tetap. Berkas token sudah mematikan seluruh bayangan pada mode kontras
  tinggi; komponen ini mengikuti pola yang sama.

**Bukan pengganti `<x-mk.spinner>`.** Spinner untuk aksi yang dipicu pengguna
dan tetap dipakai oleh `<x-mk.button loading>`. Kerangka untuk muatan
struktural. §6.1 sudah memisahkan keduanya dan pemisahan itu tidak berubah.

### Hubungannya dengan FFI

FFI menyumbang satu hal saja: bukti bahwa konvensi kerangka layak dijadikan
komponen, bukan disalin per layar. Nilai warna, durasi, dan bentuk animasinya
milik Makam.

---

## 3. Navigasi bawah ponsel — `<x-mk.bottom-nav>`

### Kenapa perlu

Ini satu-satunya pola FFI yang benar-benar tidak dimiliki Makam, dan
kemungkinan besar inilah yang paling dirasakan pemilik. `design-system.md`
§3.11 sudah menggambarkannya dan menandainya **"PROPOSED, NOT APPROVED"**.
ADR-0042 adalah persetujuan yang ditunggu seksi itu.

Alasan produknya lebih kuat daripada alasan visualnya: Makam mobile-first
sesuai PRD §9, dan penggunanya keluarga yang sedang berduka, sering memegang
ponsel dengan satu tangan sambil mengurus hal lain. Menu hamburger menaruh
seluruh navigasi di sudut atas layar, titik terjauh dari ibu jari.

### Kontrak

Lima tab, diambil dari layanan wajib Makam sendiri, **bukan** dari lima tab
FFI (Home / Galang Dana / Donasi Saya / Inbox / Akun):

| Urutan | Label | Rute | Sumber kewajiban |
|---|---|---|---|
| 1 | Beranda | `/` | — |
| 2 | Pemesanan | `/pemesanan-makam` | HOME-01 |
| 3 | Perpanjangan | `/perpanjangan` | HOME-03 |
| 4 | Akun | `/akun` | PRD MK-10 |
| 5 | Bantuan | `/bantuan` | PRD MK-12, `design-system.md` §6.10 |

**Kenapa Layanan Pemakaman dan FAQ tidak masuk tab.** Empat kartu layanan
beranda tetap utuh dan tidak boleh berkurang; tab bukan pengganti kartu.
Lima tab adalah batas kenyamanan ibu jari, dan Bantuan wajib ada di setiap
langkah menurut MK-12, sehingga ia mengambil slot yang secara visual mungkin
diharapkan FAQ. Marketplace dan FAQ tetap dijangkau dari kartu beranda,
header, dan footer.

**Aturan.**

- Muncul hanya di bawah `lg`, karena di atas itu header horizontal penuh sudah
  ada dan tidak boleh diduplikasi.
- Tinggi memenuhi `touch-target` yang sudah didefinisikan sebagai `@utility`
  di `app.css`, dan menghormati `env(safe-area-inset-bottom)`.
- `z-index` memakai utilitas `z-*` semantik dari `app.css`. GATE 11 menolak
  `z-index` mentah.
- Tab aktif ditandai dengan warna **dan** bentuk, tidak boleh warna saja;
  `design-system.md` §7 melarang status yang hanya dibedakan warna.
- Tanpa animasi indikator geser. FFI memakai `framer-motion` untuk itu;
  Makam tidak memakai pustaka animasi, dan §5 membatasi gerak.
- `aria-current="page"` pada tab yang aktif, dan seluruh bilah adalah
  `<nav aria-label="Navigasi utama">`.
- **Tidak menutupi tombol aksi utama.** Wizard pemesanan punya CTA lengket di
  bawah pada ponsel; spesifikasi pelaksanaan wajib menguji keduanya bersamaan
  dan memutuskan mana yang mengalah, karena menutupi tombol bayar adalah
  cacat, bukan detail visual.

---

## 4. Yang sengaja tidak diambil dari FFI

Ditulis eksplisit agar tidak terbaca sebagai kelalaian.

| Elemen FFI | Kenapa tidak |
|---|---|
| Palet biru dan oranye | Bertentangan dengan ADR-0041, dari pedoman merek pemilik sendiri |
| Inter | Sama |
| Bilah pencarian pil di header | `--radius-full` melarang geometri pil untuk layanan kedukaan |
| Carousel hero tiga slide | Tidak ada isinya di Makam; hero Makam memegang satu `<h1>` dan satu CTA utama |
| Kartu kampanye, kampanye mendesak, hitungan mundur | Pola urun dana. Makam tidak punya kampanye, dan urgensi buatan bertentangan dengan suara anti hard-selling di pedoman halaman 01–03 |
| Dinding doa dengan reaksi | Milik produk lain. Makam punya Memorial/QR yang sudah dirancang sendiri |
| Grid enam ikon aksi | Menggandakan empat kartu layanan yang jumlahnya dikunci |
| Fotografi stok | Pedoman halaman 09 melarang fotografi stok yang dipentaskan |
| Nama komponen, teks halaman, komentar kode | Membawa jejak Kitabisa. Lihat catatan pemilik |

---

## 5. Pemeriksaan kepatuhan PRD

Diminta eksplisit: *"pastikan sepenuhnya memenuhi prd"*. Diperiksa terhadap
[`prd-yiem-2026-09-18.md`](../../product/prd-yiem-2026-09-18.md).

### 5.1 Beranda

| Kewajiban PRD | Status hari ini | Dampak spesifikasi ini |
|---|---|---|
| Empat kartu layanan: Pemesanan, Layanan, Perpanjangan, FAQ (§1 Rev.) | **Ada** — seksi `services-heading` | Tidak berubah. §3 menjaga jumlahnya tetap empat |
| Hero dengan satu `<h1>` | **Ada** — `<x-mk.hero>` | Tidak berubah |
| Status layanan Urgent yang jujur | **Ada** — dibaca server lewat `ModeResolver::urgentMode()`, tidak pernah dikeraskan | Tidak berubah |
| CTA customer service | **Ada** — seksi `cs-cta-heading` | Tidak berubah; §3 menambah Bantuan sebagai tab permanen, memperkuat MK-12 |
| Elemen kepercayaan di area pertama: lokasi terverifikasi, harga transparan, bantuan administrasi | **Sebagian** — ada pita `trust-heading`, tetapi posisinya setelah beberapa seksi, bukan di area pertama | **Celah, tidak ditutup di sini.** Butuh keputusan tata letak, dan "lokasi terverifikasi" bergantung pada badge yang belum dibangun (PRD §17 butir 4) |
| CTA utama Cari Makam | **Labelnya berbeda** — hero memakai `'label' => 'Pesan Makam'` | **Celah kecil tetapi nyata.** Lihat §5.4 |
| CTA sekunder Perpanjang Makam, Layanan Pemakaman, Wakaf Tanah di hero | **Belum ada, dan pernah dihapus dengan sengaja** | **Konflik, bukan sekadar celah.** Lihat §5.4 |

### 5.2 Non-fungsional

| Ketentuan PRD §9 | Dampak |
|---|---|
| Web responsif, mobile first | Diperkuat. Navigasi bawah adalah pekerjaan mobile-first murni |
| Halaman pencarian tampil di bawah 3 detik pada 4G | Netral sampai terbukti. Komponen kerangka **membantu** persepsi, tetapi tidak mempercepat apa pun. Spesifikasi pelaksanaan wajib mengukur, bukan mengklaim |
| Alur pemesanan punya halaman status bila gateway gagal | Tidak disentuh |
| Bahasa Indonesia saja | Seluruh label di §3 berbahasa Indonesia |

### 5.3 Yang PRD wajibkan dan spesifikasi ini **tidak** penuhi

Disebut terbuka, bukan disembunyikan:

1. **Elemen kepercayaan belum di area pertama.** Perlu keputusan tata letak
   hero, dan badge terverifikasi belum ada.
2. **CTA sekunder di hero belum ada**, termasuk Wakaf Tanah.
3. **Halaman Wakaf Tanah dan Tentang Kami belum ada.** Yang kedua diblokir
   menunggu identitas legal YIEM.

Ketiganya sudah tercatat di PRD §17 sebagai tindak lanjut dengan spec
pemiliknya masing-masing. Spesifikasi ini tidak mengambil alih, dan tidak
mengklaim menutupnya.

### 5.4 Dua temuan baru soal hero, ditemukan saat memeriksa PRD

Keduanya muncul dari membaca `resources/views/livewire/public/home-page.blade.php`,
bukan dari dokumen. Tidak diperbaiki di sini, tetapi tidak boleh hilang.

**Pertama, label CTA utama tidak sama dengan PRD.** PRD §1 menulis CTA utama
**Cari Makam**, mengikuti slide 10 deck. Hero merender
`'label' => 'Pesan Makam'`. Perbedaannya bukan gaya bahasa: "Cari" menjanjikan
penelusuran tanpa komitmen, "Pesan" menjanjikan transaksi. Untuk keluarga yang
belum tahu lokasi mana yang tersedia, kata pertama lebih jujur dan lebih
mengundang. Perlu keputusan: label diubah mengikuti PRD, atau PRD dikoreksi
mengikuti produk. Salah satu, bukan dibiarkan berbeda.

**Kedua, CTA sekunder di hero bertabrakan dengan keputusan sistem desain.**
PRD §1 meminta tiga CTA sekunder di hero, yaitu Perpanjang Makam, Layanan
Pemakaman, dan Wakaf Tanah. Tetapi komentar di berkas hero mencatat bahwa
tombol sekunder **pernah ada dan sengaja dipindahkan keluar**: *"Single
primary CTA per §2.3; the prior secondary 'Lihat TPU & TPS' button moved into
Section 3's card grid area as a plain text link."*

Jadi menambahkan tiga tombol sekunder ke hero berarti membalik §2.3, bukan
mengisi kekosongan. Dua dokumen yang sama-sama berlaku saling bertentangan:
PRD meminta empat tombol di hero, sistem desain membatasi satu.

Jalan keluar yang saya usulkan, untuk diputuskan pemilik: pertahankan satu CTA
utama di hero, dan penuhi maksud PRD dengan menampilkan ketiga tujuan sekunder
sebagai tautan teks di bawah CTA, bukan sebagai tombol. Maksud slide 10 adalah
ketiganya terlihat di area pertama, dan itu terpenuhi tanpa membatalkan §2.3
maupun melemahkan satu CTA utama yang justru penting bagi pengguna yang
panik. Bila pemilik ingin tombol penuh, §2.3 perlu diubah lewat ADR, bukan
dilanggar diam-diam.

---

## 6. Gerbang yang harus tetap hijau

| Gerbang | Yang diperiksa | Risiko dari pekerjaan ini |
|---|---|---|
| GATE 1 | Kontras WCAG AA | Warna tab aktif dan teks kerangka |
| GATE 2 | Tidak ada nilai desain dikeraskan di luar `tokens.css` | Tinggi bilah, inset area aman |
| GATE 3 | Tidak ada nilai arbitrer Tailwind | Pola kerangka yang selama ini disalin manual |
| GATE 11 | Tidak ada `z-index` mentah | Bilah bawah yang melayang |
| GATE 12 | Tidak ada penindasan fokus tanpa pengganti | Target ketuk tab |
| `design:verify-filament-palette` | Palet panel Filament | Tidak tersentuh; §4 hanya permukaan publik |
| `blade:verify-content-survival` | Konten Blade tidak hilang saat refactor | Menyentuh header dan tata letak |

Utilitas baru mengikuti pola yang sudah ada: `tokens.css` memakai `@theme`
Tailwind 4 untuk memancarkan variabel dan utilitas, `app.css` melapisi
`@utility` semantik di atasnya seperti `py-section`, `surface-quiet`, dan
`touch-target`. Utilitas kerangka dan bilah bawah ditulis di lapisan yang
sama, bukan sebagai kelas lepas.

---

## 7. Yang tidak dijawab dokumen ini

- Apakah pemilik, setelah tahu FFI adalah kloning Kitabisa, tetap menginginkan
  kemiripan visual dengannya.
- Apakah Makam dan FFI memang dimaksudkan terbaca sebagai satu organisasi.
- Tiga celah PRD di §5.3.
- Nasib empat cabang desain yang masih terbuka.
- Tahap 5, 6, dan 7 Kamboja. Tidak dibatalkan ADR-0042, karena tidak satu pun
  bergantung pada kamboja.co.id: Tahap 5 dan 7 justru sudah dibuka oleh
  pedoman merek Makam sendiri, dan Tahap 6 menunggu tinjauan kontrak produk.

**Catatan kebersihan dokumen.** Seluruh kotak centang di rencana Kamboja masih
`- [ ]` padahal lima dari delapan tahapnya sudah merge, yaitu Tahap 1, 2, 3,
4, dan 8. Berkas itu berbohong tentang keadaan dirinya sendiri, dan satu-satunya
cara mengetahui keadaan sebenarnya saat ini adalah membaca git log, bukan
membaca rencananya. Perlu disinkronkan; bukan pekerjaan spesifikasi ini, tetapi
dicatat agar tidak hilang.

Perlu diketahui juga bahwa Tahap 3 dikenali dari **isinya, bukan dari
labelnya**: PR #303 tidak menyebut nomor tahap sama sekali, dan dicocokkan
karena yang dikerjakannya persis deskripsi Tahap 3. Pemetaan seperti ini
rapuh, dan itu alasan tambahan kenapa berkas rencananya perlu disinkronkan.
