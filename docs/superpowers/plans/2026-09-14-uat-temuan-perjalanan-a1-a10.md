# Temuan UAT — Perjalanan pengguna A1–A10

Dua puluh tiga temuan dari menjalankan sepuluh perjalanan tamu di Chrome
terhadap `dev.makam.co.id` pada 13–14 Sep 2026, dengan verifikasi silang ke
basis data `makam_dev` dan `makam_beta`. Ringkasannya ada di Tahap 5
[`2026-09-13-uat-end-to-end.md`](2026-09-13-uat-end-to-end.md); berkas ini
memuat bukti lengkapnya.

Setiap temuan menyebut berkas dan barisnya, dan setiap angka di sini diukur
— bukan disimpulkan dari membaca kode.

## UAT-A1-01 — Label wajib pada dua field yang validatornya sengaja jadikan opsional
**Journey:** A1 booking wizard, Langkah 2 (Data Almarhum)
**Berat:** Medium (aksesibilitas + kontradiksi copy)

`resources/views/livewire/public/booking/wizard.blade.php:1107` dan `:1128`
memberi `Tanggal Lahir` dan `Tanggal Meninggal` penanda `*` plus
`<span class="sr-only">(wajib diisi)</span>`.

`app/Domain/Booking/Actions/SaveBookingDraftStep.php:547-553` sengaja
membuat keduanya opsional, dengan komentar yang menyebut alasannya:
copy Blade di atas seksi itu berbunyi "Isi sebisa Anda".

**Bukti langsung dari browser** (submit form kosong, dev.makam.co.id):
delapan field lain mendapat `aria-invalid="true"`; kedua field tanggal
mendapat `null`. Sembilan pesan galat muncul, tidak satu pun soal tanggal.

**Akibat:** pembaca layar mengumumkan "wajib diisi" pada field yang tidak
wajib, tepat di seksi yang paragrafnya menjanjikan sebaliknya — kepada
keluarga yang mungkin memang belum tahu tanggal lahir almarhum.

**Perbaikan:** hapus `*` dan `sr-only` pada kedua label (samakan dengan
pola `Jenis Kelamin (opsional)` yang sudah dipakai di file yang sama).

## UAT-A1-02 — Langkah Pembayaran tidak menampilkan jumlah yang harus dibayar
**Journey:** A1 booking wizard, Langkah 3 (Pembayaran)
**Berat:** High (kepercayaan + calon sengketa)

Diukur langsung di dev.makam.co.id, draft
`01a09d27-d53f-70ee-be9e-722e05c9035c`:

    document.querySelector('main').innerText.match(/Rp[\s ][\d.]+/g)  -> []
    innerText.match(/Ringkasan Pesanan/)                             -> null

Ringkasan Pesanan (Petak + tabel layanan + "Total: Rp 1.650.000") ada di
Langkah 2, lalu **hilang seluruhnya** di Langkah 3. Tombol "Bayar Sekarang"
diminta ditekan tanpa satu pun angka di layar.

**Kenapa ini penting sekarang:** rencana #295 memindahkan model ke
bayar-penuh-di-muka. Meminta keluarga berduka menekan "Bayar Sekarang"
tanpa melihat nominal adalah dasar sengketa yang paling mudah dihindari,
dan ia menjadi jauh lebih mahal begitu uangnya benar-benar berpindah
lebih dulu.

**Perbaikan:** tampilkan ulang blok Ringkasan Pesanan (petak + total) di
Langkah 3, di atas tombol bayar. Komponennya sudah ada — ia hanya tidak
dirender pada langkah ini.

## UAT-A1-03 — Instruksi transfer manual merujuk dua hal yang tidak ada di layar
**Journey:** A1, Langkah 3, blok Pembayaran Manual
**Berat:** Low-Medium (copy), tapi ia memperkuat UAT-A1-02

`resources/views/livewire/public/booking/wizard.blade.php:1451` merender
paragraf ini **tanpa syarat**:

> "Transfer sejumlah total pesanan ke rekening tujuan di bawah ini..."

Ketika `$bankTransferConfigured` false — keadaan dev saat ini — yang
muncul "di bawah ini" justru alert "Rekening tujuan belum dikonfigurasi".
Dan "total pesanan" tidak ada di halaman ini sama sekali (UAT-A1-02).

Jadi pengguna disuruh mentransfer **sejumlah yang tidak ditampilkan** ke
**rekening yang tidak ada**.

Alert kosongnya sendiri bagus dan jujur — ia menyuruh menghubungi admin
lebih dulu justru agar dana tidak salah kirim. Yang salah hanya paragraf
di atasnya, yang tidak ikut menyesuaikan diri.

**Perbaikan:** jadikan paragraf itu bersyarat pada `$bankTransferConfigured`,
dengan varian untuk keadaan belum dikonfigurasi.

## UAT-A1-04 (LULUS) — Idempotensi pembuatan pesanan, diverifikasi di basis data
Dua klik "Bayar Sekarang" berturut-turut pada draft yang sama menghasilkan
**satu** baris `orders` (`reference=MK-2026-2ZGKBMFI`, `status=MASUK`),
dijaga `idempotency_key='booking:<draft-id>'`. `payment_sessions` dev tetap
1 baris (angka all-time sebelum UAT) — tidak ada sesi pembayaran terbuat,
konsisten dengan pesan "Pembayaran dibuka setelah tim kami mengonfirmasi".
Bukan klaim dari UI: dihitung langsung lewat query di `makam_dev`.

## UAT-A2-01 — Progressbar perpanjangan mengumumkan "Progres pemesanan"
**Journey:** A2 perpanjangan, seluruh halaman /perpanjangan
**Berat:** Medium (aksesibilitas + bahasa)

`resources/views/livewire/public/renewal/start.blade.php:41` **sudah**
mengirim `aria-label="Progres perpanjangan makam"` — persis seperti yang
diundang oleh komentar komponen di `stepper.blade.php:56`, yang berjanji
"a non-booking journey ... can also supply its own accessible group name".

Tapi janji itu hanya berlaku untuk div terluar. Di dalamnya,
`stepper.blade.php:186` menulis `aria-label="Progres pemesanan"` sebagai
**atribut literal**, bukan merge default — jadi tidak bisa ditimpa.

**Bukti dari browser** (pohon aksesibilitas dev.makam.co.id/perpanjangan):
`progressbar "Progres pemesanan"`. Pembaca layar pengguna yang sedang
memperpanjang sewa makam diberi tahu ia sedang memesan.

**Perbaikan:** jadikan `:186` memakai nilai aria-label yang sama dengan
div luar (prop baru, mis. `$progressLabel`, default "Progres pemesanan").

## UAT-A2-02 — Dua sistem penomoran "Langkah" bertabrakan di satu layar
**Journey:** A2 perpanjangan, /perpanjangan?kota=JAKARTA
**Berat:** Medium (informasi arsitektur)

Stepper di atas layar: **"Langkah 1 dari 3"** — benar, karena langkah
perjalanan perpanjangan adalah 1 Cari Makam / 2 Biaya & Bayar /
3 Konfirmasi (`app/Domain/Renewal/RenewalWizardScreen.php:20-22`), dan
seluruh halaman ini adalah langkah 1.

Judul-judul **di dalam** halaman yang sama: "Langkah 1 — Pilih Kota",
"Langkah 2 — Pilih TPU/TPS", "Langkah 3 — Cari Makam".

Jadi saat pengguna membaca "Langkah 3 — Cari Makam", header tepat di
atasnya berkata "Langkah 1 dari 3". Dua penghitungan berbeda, kata yang
sama, rentang yang sama (1..3), satu layar.

**Catatan:** saya nyaris melaporkan ini sebagai "stepper beku". Ia tidak
beku — ia benar. Yang salah adalah sub-bagian halaman meminjam kata
"Langkah".

**Perbaikan:** ganti judul sub-bagian jadi kata yang bukan "Langkah"
(mis. "1. Pilih Kota" / "Pilih Kota", "Pilih TPU/TPS", "Cari Makam").

## UAT-A2-03 — Tanggal ISO mentah di hasil pencarian makam
**Journey:** A2 perpanjangan, hasil Langkah 3
**Berat:** Medium (bahasa)

Hasil nyata di dev untuk pencarian "budi santosa":

    Tanggal Wafat   2018-04-11
    Jatuh Tempo     2026-04-11

`resources/views/livewire/public/renewal/start.blade.php:354` dan `:357`
mencetak `{{ $row->deathDate }}` / `{{ $row->dueDate }}` apa adanya.
`app/Support/Design/IndonesianDate.php` sudah ada dan tidak dipakai di
sini — ini instans konkret dari temuan W5 di rencana cleanup.

Halaman berbahasa Indonesia yang menampilkan tanggal wafat kepada
keluarga sebaiknya menulis "11 April 2018", bukan format mesin.

## UAT-A2-04 — Makam yang sudah lewat jatuh tempo tidak ditandai
**Journey:** A2 perpanjangan, hasil Langkah 3
**Berat:** Medium

Baris hasil menampilkan `Jatuh Tempo 2026-04-11`. Hari ini 13 Sep 2026 —
makam itu **sudah lewat lima bulan**. Tidak ada lencana, warna, atau kata
apa pun yang menyatakannya; pengguna harus menghitung sendiri selisih
tanggal, dari format ISO pula (lihat UAT-A2-03).

Dari 30 baris `grave_records` di dev, beberapa sudah lewat tempo
(mis. `Contoh Sejahtera 1`, jatuh tempo 2026-01-01) dan sebagian belum
(`Contoh Siti Rahayu`, 2027-09-02). Keduanya tampil identik.

**Kenapa penting:** membedakan "belum jatuh tempo" dari "sudah terlambat"
adalah satu-satunya informasi yang menentukan apakah pengguna perlu
bertindak hari ini. `StatusIntent` sudah menyediakan kosakata visualnya.

## UAT-A2-05 (KEPUTUSAN, bukan bug) — Nama almarhum masuk URL dan riwayat peramban
**Journey:** A2 perpanjangan
**Berat:** perlu keputusan pemilik

URL nyata setelah mencari: `/perpanjangan?kota=JAKARTA&tpu=<uuid>&nama=budi+santosa`

Ini **disengaja**: `app/Livewire/Public/Renewal/RenewalStart.php:65`
memakai `#[Url(as: 'nama', history: true)]`, dan komentar di `:102-104`
menyatakan alasannya — "a shared/bookmarked result link". Manfaatnya
nyata: keluarga bisa saling mengirim tautan hasil.

Biayanya juga nyata, dan hanya dua kalimat untuk dinyatakan: nama
almarhum masuk riwayat peramban, dan pada **muat halaman penuh** (bukan
pushState Livewire) ia masuk log akses nginx sebagai query string.
`AGENTS.md` §Observability berbunyi "Never place restricted data in logs".

Saya tidak menyebutnya cacat karena ia keputusan desain yang
terdokumentasi. Yang perlu diputuskan: apakah nama almarhum termasuk
"restricted data" menurut aturan itu. Bila ya, tautan bisa dibagikan
lewat token pencarian buram alih-alih nama mentah.

## UAT-A2-06 — Nama bulan Inggris di halaman biaya perpanjangan
**Journey:** A2 perpanjangan, /perpanjangan/pembayaran
**Berat:** Medium (bahasa) — cacat bahasa paling kasatmata yang ditemukan

Tampil nyata di dev:

    Jatuh tempo saat ini   2026-04-11        <- ISO
    Terakhir diperbarui    08 August 2026    <- bulan BAHASA INGGRIS

Dua format tanggal berbeda pada satu kartu, salah satunya bukan bahasa
halaman ini.

`resources/views/livewire/public/renewal/payment.blade.php:135` memakai
`->format('d F Y')`. `F` pada PHP selalu menghasilkan nama bulan Inggris
— ia tidak mengikuti lokal aplikasi. Yang melokalkan adalah
`translatedFormat()`/`isoFormat()`, atau helper rumah yang sudah ada:

`App\Support\Design\IndonesianDate::longDate()` (`:68`) sudah menghasilkan
"Senin, 17 Agustus 2026", lengkap dengan dua belas nama bulan Indonesia
di `MONTH_LABELS`. Ia ada, benar, dan tidak dipanggil di sini.

**Perbaikan:** tambahkan varian tanpa nama hari pada `IndonesianDate`,
lalu pakai di `payment.blade.php:135` dan di `start.blade.php:354,357`
(UAT-A2-03) — satu perbaikan menutup keduanya.

**Bukan temuan:** tidak munculnya blok denda keterlambatan pada makam yang
telat 5 bulan. `QuoteRenewal.php:104-105` mengembalikan
`lateFineMinor: null` selama gate **G-RATE-01** tertutup, dan itu
terdokumentasi di `RenewalQuoteDraft.php:41`. Perilaku benar.

## UAT-A2-07 — Layar bayar perpanjangan kehilangan nominal DAN nama almarhum
**Journey:** A2 perpanjangan, /perpanjangan/pembayaran (keadaan bayar)
**Berat:** High — ini menaikkan UAT-A1-02 dari satu halaman jadi pola sistemik

Layar sebelumnya (Biaya Perpanjangan) menampilkan:

    "Perpanjangan masa sewa makam Contoh Budi Santoso di TPU Jakarta Menteng."
    Estimasi biaya perpanjangan  Rp 4.000.000

Setelah menekan "Terima Tarif — Lanjut ke Pembayaran", layar yang memuat
tombol "Bayar Sekarang" berbunyi lengkapnya:

    "Perpanjangan masa sewa makam."

Tanpa nama. Tanpa nominal. Tanpa TPU.

`resources/views/livewire/public/renewal/payment.blade.php` mencabangkan
`@elseif ($graveView && $quote)` untuk layar tarif (`:92`) dan `@else`
untuk layar bayar (`:188`). Cabang `@else` merender judul generik `:194`
yang bahkan membaca seperti kalimat terpotong — tempat nama seharusnya
berada.

**Gabungan dengan UAT-A1-02:** kedua perjalanan yang melibatkan uang
meminta pengguna menekan tombol bayar tanpa satu angka pun di layar.
Ini bukan dua cacat halaman, melainkan satu pola.

## UAT-A3-01 — Harga & vendor di halaman daftar berbeda dari halaman detail, untuk SEMUA produk
**Journey:** A3 marketplace, /marketplace -> /marketplace/produk/{code}
**Berat:** High (struktural). Terjadi di **dev DAN beta**.

Kartu daftar dan halaman detail membaca **dua sumber berbeda** untuk dua
fakta yang sama, dan tidak ada apa pun yang merekonsiliasinya:

| | dibaca dari | dipakai oleh |
|---|---|---|
| "Mulai Rp 3.200.000 — CV Nisan Granit Sentosa" | `products.base_price_idr`, `products.vendor_name` (via `MarketplacePresenter:77,101`) | kartu daftar |
| "Rp 4.500.000 — Toko Bunga Contoh 3" | `vendor_listings.price_minor` + `vendors.name` | halaman detail **dan keranjang** |

Diukur langsung di basis data **beta**, kesembilan produk aktif berbeda:

    Karangan Bunga Papan   daftar=350.000     detail=5.500.000   (15,7x)
    Paket Bunga Tabur      daftar=175.000     detail=7.500.000   (42,9x)
    Granit                 daftar=3.200.000   detail=4.500.000
    Marmer                 daftar=3.800.000   detail=6.500.000
    Kaligrafi              daftar=3.950.000   detail=5.500.000
    Bulanan                daftar=150.000     detail=500.000
    3 Bulan                daftar=350.000     detail=1.500.000
    6 Bulan                daftar=600.000     detail=2.000.000
    Tahunan                daftar=950.000     detail=1.500.000

Nama vendornya pun berbeda pada kesembilan baris. Pengunjung melihat
"CV Nisan Granit Sentosa", mengklik, lalu ditawari oleh "Toko Bunga
Contoh 3" — perusahaan lain, dengan harga lain. Yang masuk keranjang
adalah angka detail.

**Yang meredakan, dan harus disebut:** kedua angka itu data contoh, dan
UI menandainya — "(vendor contoh)" dan "Estimasi internal (data contoh)".
Migrasi `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products`
menyatakan sendiri "None of the following is real". Jadi tidak ada
pelanggan yang tertipu soal produk nyata hari ini.

**Yang tidak diredakan:** strukturnya. Dua kolom sumber kebenaran untuk
harga dan vendor, dibaca oleh dua halaman dalam satu perjalanan, tanpa
gate yang membandingkannya. `MarketplacePresenter`'s doc block menjaga
agar tiap angka tidak tampil tanpa penanda — tapi tidak ada yang menjaga
agar **kedua angka itu sama**. Begitu vendor sungguhan masuk lewat dua
layar admin berbeda, cacat yang sama muncul dengan uang sungguhan.

**Perbaikan:** jadikan `vendor_listings` satu-satunya sumber harga/vendor
yang dirender ke publik, dan biarkan kartu daftar membaca listing aktif
termurah (itulah arti kata "Mulai" yang sudah dipakai). Lalu pasang gate
yang gagal bila sebuah produk aktif punya `base_price_idr` yang tidak
cocok dengan listing aktif mana pun — idiom `verify-docs.sh` sudah ada.

**Bukan temuan:** varian yang hanya ditampilkan dan tidak bisa dipilih.
`ProductDetail.php:103-110` menyatakan itu keputusan sadar — kontrol yang
menerima pilihan varian tapi tidak punya tujuan pengiriman lebih buruk
daripada tidak ada kontrol.

## UAT-A3-02 — Harga di halaman detail tampil tanpa baris atribusi sumber
**Journey:** A3 marketplace, /marketplace/produk/{code}
**Berat:** Low-Medium

Kartu daftar menampilkan "Mulai Rp 3.200.000" **diikuti** "Estimasi
internal (data contoh)". Halaman detail menampilkan "Rp 4.500.000"
**tanpa** baris sumber apa pun.

`docs/design/design-system.md:214` §2.3 menyatakan sebagai DO: "Show the
source and last-updated time on any fee or availability figure."
`MarketplacePresenter` dibuat justru untuk menegakkan itu — doc block-nya
menyebutnya aturan struktural, bukan konvensi. Tapi harga detail datang
dari `vendor_listings`, yang **melewati** `priceAttribution()` sepenuhnya.

Jadi kelas penjaga itu menjaga satu jalur harga, dan jalur harga kedua
(yang justru masuk keranjang) tidak dilewatkan kepadanya sama sekali.
Ini sisi lain dari UAT-A3-01: dua sumber, satu penjaga.

## UAT-A3-03 — Ongkos kirim tidak masuk akal di beta (Rp 0 / Rp 250 / Rp 500)
**Journey:** A3 marketplace, /marketplace/checkout
**Berat:** Medium (kredibilitas beta publik)

Checkout nyata di dev: batu nisan granit Rp 4.500.000 + **"Ongkos kirim
(Jakarta Pusat) Rp 500"** = Rp 4.500.500. Beta sama: Rp 0 / 250 / 500 /
750 untuk empat vendor contoh.

**Bukan bug satuan.** Saya memeriksanya: `VendorListingExampleData::
serviceAreas()` (`:146-160`) memang mendeklarasikan `delivery_fee_minor`
langsung, jadi `150_000` memang berarti Rp 1.500. Konversinya benar.

Yang terjadi: ada **dua** kelas data contoh untuk tabel yang sama.
`RealisticMarketplacePricingExampleData::serviceAreas()` (`:172-189`)
ditulis belakangan justru untuk memperbaiki ini — ia memakai
`delivery_fee_idr` pada skala Rp 100.000 dan doc block-nya menjelaskan
alasannya ("florist delivers per-order, so a real fee"). Tapi penjaga
idempotensinya melewati basis data "yang sudah memuat salah satu dari
tiga nama vendor ini" — dan dev/beta memuat nama vendor dari kelas
**lama** ("Toko Bunga Contoh 1..5"), jadi perbaikannya tidak pernah
berlaku. Beta masih menayangkan angka yang kelas baru itu ada untuk
menggantikan.

**Perbaikan:** ini kerja satu sentuhan pemilik, bukan perubahan kode —
jalankan ulang penyemaian harga realistis di beta, atau perbarui
`service_areas` yang ada. Nilainya: menghapus "Rp 500" dari layar
checkout publik selama masa beta.

## UAT-A3-04 — Checkout memakai validasi native, sehingga pesan Indonesia-nya tak pernah tampil
**Journey:** A3 marketplace, /marketplace/checkout
**Berat:** Medium (bahasa + konsistensi)

Menekan "Buat pesanan" dengan form kosong di dev: satu cincin merah pada
"Nama penerima", **nol pesan galat di halaman**. Bandingkan wizard
pemesanan, yang pada kondisi sama memunculkan sembilan pesan Indonesia
yang spesifik ("Alamat lengkap harus diisi minimal 10 karakter.").

`resources/views/livewire/public/marketplace/checkout.blade.php:190,200,
210,219` memasang atribut **`required` native** pada keempat field wajib.
Atribut itu memblokir submit di peramban, sebelum `wire:submit="placeOrder"`
sempat berjalan — jadi `:error="$errors->first('recipientName')"` yang
sudah terpasang di baris yang sama tidak pernah punya kesempatan terisi
untuk kasus kosong.

Akibat bahasa: pesan yang dilihat pengguna adalah pesan bawaan peramban,
yang mengikuti bahasa peramban/sistem operasi — **bukan** `lang` halaman.
Pengguna Indonesia dengan Chrome berbahasa Inggris mendapat teks Inggris
di satu-satunya halaman checkout berbayar.

**Perbaikan:** lepas `required` native (biarkan `aria-required` untuk
aksesibilitas) sehingga validasi server yang sudah tertulis rapi itulah
yang tampil, seperti di wizard pemesanan.

## UAT-A4-01 — Parameter URL direktori berbahasa Inggris, sendirian di antara yang lain
**Journey:** A4 direktori, /pemakaman
**Berat:** Low (bahasa/konsistensi)

Kosakata query string publik hampir seluruhnya bahasa Indonesia:

    /perpanjangan   ?kota= ?tpu= ?nama= ?blok= ?tanggal=   (RenewalStart.php:54-71)
    /marketplace    ?kategori=
    /pemakaman      ?city= ?type=                          (CemeteryDirectoryIndex.php:68,71)

Direktori satu-satunya yang memakai Inggris, dan ia bukan halaman baru
atau internal — ia halaman telusur publik.

**Perbaikan:** `#[Url(as: 'kota')]` dan `#[Url(as: 'jenis')]`, dengan
penerimaan nama lama bila ada tautan yang sudah beredar.

## UAT-A4-02 (LULUS) — Kota tanpa data tidak disembunyikan
Memfilter /pemakaman ke Sukabumi (nol TPU/TPS terpublikasi) memunculkan:

> "Belum ada lokasi yang cocok dengan filter ini. Kombinasi kota dan jenis
> lokasi yang Anda pilih belum memiliki TPU/TPS yang terpublikasi. Area
> layanan kami tetap mencakup seluruh kota di atas — data lokasinya sedang
> kami lengkapi."

plus "Reset filter" dan "Hubungi Customer Service".

Kota tetap muncul di daftar filter, persis seperti yang diwajibkan aturan
"NEVER filter this" pada `CemeteryPublicQuery::launchCities()`. Ini
empty state terbaik yang saya temui di situs ini.

## UAT-A4-03 — Halaman TPU menjanjikan dua hal yang wizard pemesanan langsung membantahnya
**Journey:** A4 -> A1, TPU Jakarta Menteng, dua halaman publik, satu klik
**Berat:** HIGH. Ini temuan paling serius kedua setelah UAT-A3-01.

`/pemakaman/tpu-jakarta-menteng` menyatakan dua hal:

> "Denah blok dan petak untuk lokasi ini **tidak ditampilkan secara
> publik**. Data petak hanya dibuka melalui pengelola bersama registri
> resmi..."

> "...pengelola mengonfirmasi ketersediaan **sebelum ada biaya yang perlu
> dibayar**. **Tidak ada petak yang terkunci sebelum konfirmasi tersebut.**"

Di sesi UAT yang sama, untuk TPU yang sama, `/pemesanan-makam`:

- menampilkan denah petak publik penuh — BLOK-A, petak 001-006, lengkap
  dengan status tersedia/dipesan/terisi/perawatan;
- dan saat saya mengklik petak 001 **sebagai pengunjung anonim**, petak
  itu **langsung terkunci**: "Plot ditahan sementara — ditahan agar tidak
  diambil pengunjung lain hingga pukul 23:58", dan ketersediaan blok
  turun dari 5 menjadi 4.

Kalimat "Tidak ada petak yang terkunci sebelum konfirmasi" salah secara
harfiah, dan dibantah dalam satu klik.

**Akarnya: dua flag di dua tabel menjawab pertanyaan yang sama.**

    cemetery_capability_profiles (cemetery_id=1419ce17-...)
        map_mode     = LOCATION_ONLY        -> halaman direktori
        booking_mode = REQUEST_CONFIRMATION -> halaman direktori

    cemeteries.plot_tracking_mode = 'granular'  -> wizard pemesanan

`BookingWizard::pickerAppliesTo()` (`:617`) menggerbangi peta petak publik
**hanya** pada `plot_tracking_mode === GRANULAR`. `hasPublicPlotMap()` dan
`isRequestConfirmationBooking()` tidak muncul satu kali pun di
`BookingWizard.php` maupun `wizard.blade.php` — saya grep keduanya, nol
hasil. Wizard mengimpor `PublicCapabilityProjection` (`:38`) tapi
memakainya untuk hal lain (`:1809`, kartu TPU), tidak pernah untuk
keputusan peta petak.

**Dan profil kapabilitasnya mengakui dirinya belum dievaluasi.** Kolom
`evidence` baris itu berbunyi: "belum ada evaluasi operator lapangan;
seluruh mode mengikuti nilai aman default, bukan hasil aktivasi
kapabilitas nyata."

Jadi: satu tabel berkata "kami konservatif di sini, jangan tampilkan
petak", tabel lain berkata "granular", dan wizard hanya mendengarkan yang
kedua. Halaman direktori lalu menyampaikan janji konservatif itu kepada
pengunjung sebagai fakta.

**Perbaikan (dua pilihan, keputusan pemilik):**
1. Jadikan `pickerAppliesTo()` juga menuntut `hasPublicPlotMap()` — peta
   petak hilang dari wizard untuk TPU ini, janji direktori jadi benar; atau
2. Perbarui profil kapabilitas TPU ini agar mencerminkan yang benar-benar
   berjalan (`map_mode` publik, `booking_mode` yang menahan petak), lalu
   perbaiki copy "Tidak ada petak yang terkunci".

Pilihan 1 aman-default dan sejalan dengan `evidence` yang mengaku belum
dievaluasi. Pilihan 2 jujur terhadap produk yang sudah berjalan. Yang
tidak boleh adalah membiarkan keduanya hidup berdampingan.

## UAT-A6-01 — Validasi native dipakai di 10 formulir publik (perluasan UAT-A3-04)
**Journey:** A6 pre-need, dan sembilan halaman publik lain
**Berat:** Medium (bahasa) — naik dari Medium-lokal jadi Medium-menyeluruh

Menekan "Daftarkan Minat" kosong di /preneed: tidak ada pesan galat yang
dirender, sama seperti checkout. Setelah dihitung, ini pola, bukan kasus:

    9  pre-need/pre-need-interest-page.blade.php
    6  marketplace/checkout.blade.php
    5  auth/register-page.blade.php
    4  auth/reset-password-page.blade.php
    3  visitation/page.blade.php
    3  auth/login-page.blade.php
    2  faq/index.blade.php
    2  auth/forgot-password-page.blade.php
    1  marketplace/order-tracking.blade.php
    1  care-subscription/care-history-page.blade.php

(jumlah atribut `required` native per berkas)

Dan dua formulir yang jelas digarap paling hati-hati justru memakai nol:

    0  booking/wizard.blade.php
    0  renewal/start.blade.php

Keduanya merender pesan Indonesia yang spesifik — sembilan pesan pada
wizard, "Isi minimal satu kolom pencarian: nama almarhum, blok, atau
tanggal wafat." pada perpanjangan.

**Akibatnya:** pada sepuluh halaman itu, termasuk masuk, daftar, reset
kata sandi, dan satu-satunya checkout berbayar, pesan validasi yang
dilihat pengguna adalah teks bawaan peramban — mengikuti bahasa peramban
atau sistem operasi, bukan `lang` halaman. Pengguna Indonesia dengan
Chrome berbahasa Inggris membaca galat berbahasa Inggris.

**Perbaikan:** lepas `required` native dari sepuluh berkas itu (ganti
`aria-required="true"` untuk aksesibilitas), biarkan validasi server yang
sudah ada merender pesannya, mengikuti pola wizard. Bisa dijaga gate:
larang atribut `required` native di `resources/views/livewire/public/`,
allowlist menyusut ke 0 — idiom yang sudah dipakai `verify-docs.sh`.

## UAT-A7-01 — FAQ menjelaskan alur sembilan langkah yang sudah tidak ada
**Journey:** A7 FAQ, /faq — 22 artikel, semuanya terbit di **beta**
**Berat:** High (permukaan bantuan mandiri utama menyesatkan)

Dua artikel yang **terbit di beta** menjelaskan alur pemesanan yang tidak
cocok dengan produk yang saya lalui hari ini:

| slug (terbit di beta) | isi | kenyataan terukur |
|---|---|---|
| `bagaimana-cara-memesan-makam` | "alur booking online **sembilan langkah**" | wizard punya **4** langkah — `BookingWizardScreen.php:25-28`: Cari & Pilih / Detail Pemesanan / Pembayaran / Konfirmasi; stepper di layar berbunyi "Langkah 1 dari 4" |
| `kapan-pembayaran-dapat-dilakukan` | "Pembayaran dilakukan pada **langkah kedelapan**, setelah ringkasan pesanan, data pemesan, dan dokumen selesai dikonfirmasi" | pembayaran adalah **langkah 3 dari 4**; tidak ada langkah 8, dan tidak ada langkah dokumen sama sekali |

Tiga artikel lain bertentangan dengan yang saya amati langsung:

- *"Apakah pembayaran manual didukung?"* → "Ya, pembayaran manual adalah
  **jalur pembayaran utama yang didukung penuh saat ini**." Kenyataannya
  blok pembayaran manual menampilkan "Rekening tujuan belum
  dikonfigurasi" dan tidak bisa diselesaikan; dan doc block
  `wizard.blade.php:1440` justru menyatakan manual hanya muncul sebagai
  jalur pemulihan, "never a second option offered alongside a live or
  untried online path".
- *"Dokumen apa yang diperlukan?"* → "KTP, Kartu Keluarga, dan Surat
  Keterangan Kematian." Wizard langkah 2 justru berkata: "**Anda belum
  perlu menyiapkan dokumen** — Pada tahap ini kami tidak meminta unggahan
  dokumen apa pun."
- *"Bagaimana mengirim bukti pembayaran?"* → "**Unggah** bukti pembayaran
  ... pada langkah pembayaran manual." Tidak ada kontrol unggah di sana;
  yang ada hanya satu kolom teks "Referensi Pembayaran".

**Kenapa ini High:** FAQ adalah tempat orang pergi ketika alurnya sudah
membingungkan. Sembilan langkah versus empat bukan ketidakcocokan kecil —
ia membuat pembaca mencari empat langkah yang tidak ada, lalu menyimpulkan
dirinya yang salah.

**Perbaikan:** artikel FAQ adalah data, bukan kode — pemilik dapat
menyuntingnya lewat panel admin (FaqArticles). Yang perlu kode adalah
gate: jumlah langkah dalam prosa FAQ tidak dapat dijaga mesin, jadi ini
masuk daftar "tidak bisa dimekanisasi, jadwalkan pass manusia" pada
rencana cleanup — dengan catatan bahwa pass pertamanya perlu terjadi
sebelum rilis publik.

## UAT-A10-01 — Tiga rute publik mengembalikan HTTP 500 pada ID tidak valid (dev DAN beta)
**Journey:** A10 memorial, A2 perpanjangan
**Berat:** HIGH. Temuan paling bisa langsung ditindak dari seluruh UAT ini.

Diukur dengan curl pada kedua host:

    dev                                                 beta
    /kenangan/TIDAK-ADA                          500    500
    /perpanjangan/pembayaran?perpanjangan=...    500    500
    /perpanjangan/konfirmasi?perpanjangan=...    500    500

    /kenangan/00000000-0000-0000-0000-000000000000  200   (UUID sah tapi tidak ada -> benar)

Jadi yang rusak bukan "tidak ditemukan", melainkan "bentuknya bukan UUID".

**Penyebab, dari log dev:**

    SQLSTATE[22P02]: Invalid text representation: 7
    ERROR: invalid input syntax for type uuid: "TIDAK-ADA"
    SQL: select * from "memorial_profiles" where "id" = ? limit 1

Postgres melempar sebelum pemeriksaan `null` sempat berjalan. Di SQLite
hal yang sama akan diam-diam mengembalikan null dan tampak baik-baik
saja — persis jebakan yang sudah tercatat di repo ini.

**Tiga lokasinya:**

    app/Livewire/Public/Memorial/MemorialFamilyPage.php:122
        MemorialProfile::query()->find($profileId);
    app/Livewire/Public/Renewal/RenewalConfirmation.php:39
        Renewal::query()->find($this->perpanjangan);
    app/Livewire/Public/Renewal/RenewalPayment.php:168 (dan :347)
        Renewal::query()->find($this->perpanjangan);

Ketiganya menerima string mentah dari rute atau `#[Url]`, langsung dari
pengunjung anonim.

**Idiomnya sudah ada di repo ini dan sudah benar**, di berkas yang sama
persis untuk dua baris di atasnya:

    app/Livewire/Public/Renewal/RenewalPayment.php:130
        $grave = Str::isUuid($graveId) ? GraveRecord::query()->find($graveId) : null;

Dua belas berkas sudah memakai `Str::isUuid()`. Tiga ini terlewat.

**Perbaikan:** terapkan penjaga yang sama di tiga baris itu. Lalu pasang
gate: larang `->find($` pada model berkolom `uuid` di
`app/Livewire/Public/` tanpa penjaga `Str::isUuid()` — allowlist menyusut
ke 0. PR #308 (`UuidColumnTypingPremiseTest`) sudah membangun premis
pengujiannya untuk sebelas kolom; ini menambahkan permukaan rutenya.

**Catatan positif:** log **tidak** membocorkan nilai bindingnya —
"1 binding value(s) redacted — see AGENTS.md §Observability". Aturan
observabilitas dihormati bahkan di jalur crash.

## UAT-A5-01 — Nilai enum mentah bocor ke layar sebagai nama kota
**Journey:** A5 kunjungan, /kunjungan
**Berat:** Low (bahasa)

Daftar lokasi menampilkan kotanya sebagai **"JAKARTA"**, "TANGERANG",
"DEPOK" — huruf kapital semua. Di halaman yang sama, nama TPU ditulis
"TPU Jakarta Menteng" dengan kapitalisasi normal, jadi satu kartu memuat
dua ejaan kota yang berbeda.

`resources/views/livewire/public/visitation/page.blade.php:45` mencetak
`{{ $picked->city }}` apa adanya, dan kolom `cemeteries.city` menyimpan
kode enum kapital (`JAKARTA`, `DEPOK`, `BOGOR`, `BEKASI`, `TANGERANG`).
Jadi yang terbaca pengunjung adalah nilai basis data, bukan label.

Direktori `/pemakaman` menampilkan "Jakarta" dengan benar pada tombol
filternya — labelnya sudah ada di suatu tempat, halaman ini saja yang
tidak memakainya.

## UAT-A10-02 — Halaman memorial tidak ditemukan berstatus 200 dan tanpa judul
**Journey:** A10 memorial, /m/{token}
**Berat:** Low

`/m/CONTOH-TOKEN-TIDAK-ADA` mengembalikan **HTTP 200** dengan
`<title>Makam.co.id</title>` — tanpa nama halaman, satu-satunya halaman
publik yang begitu (bandingkan "Status Pesanan - ...", "Masuk - ...").

Isinya sendiri benar dan bagus: "Memorial tidak tersedia. ... Jika Anda
menerima kode ini dari keluarga, silakan hubungi mereka untuk memastikan
kode masih berlaku." Ia sengaja tidak membedakan "tidak pernah ada" dari
"dicabut" — disiplin privasi yang sama dengan `ProductDetail`.

Disiplin itu tidak menuntut status 200. Karena **kedua** keadaan
menghasilkan halaman yang sama persis, mengembalikan 404 untuk keduanya
juga tidak membocorkan apa pun — dan lebih benar bagi perayapan serta
pemantauan.

**Perbaikan:** kembalikan 404 (isi tetap sama) dan beri `<title>` yang
menyebut halamannya.
