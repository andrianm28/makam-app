# Catatan untuk Pemilik Proyek — permintaan "tampilan Makam sama dengan FFI"

**22 September 2026.** Satu halaman, untuk dibaca sebelum memutuskan.
Ditulis karena permintaannya bisa berarti dua hal yang sangat berbeda, dan
satu fakta yang saya temukan mengubah taruhannya.

## Ringkasnya

Saya membaca kode situs Fund for Indonesia di `/home/ubuntu/fundforindonesia.org`
sebelum mengerjakan apa pun. Situs itu **kloning Kitabisa yang belum selesai
diganti mereknya.** Kitabisa adalah perusahaan urun dana Indonesia yang nyata
dan besar.

Ini bukan kesimpulan saya dari kemiripan tampilan. Ini tertulis di dalam
kodenya:

| Yang ditemukan | Di mana |
|---|---|
| Nama paket `"kitabisa-clone"` | `package.json` |
| "Matches kitabisa.com's green checkmark badge" | `VerificationBadge.tsx` |
| "shimmer animation matching kitabisa.com's loading UX" | `Skeleton.tsx` |
| Judul seksi "Yang Baru di Kitabisa", "Pilihan Kitabisa", "Tentang Kitabisa" | `page.tsx` dan komponen beranda |
| Tautan ke `instagram.com/kitabisacom`, `facebook.com/kitabisacom`, `youtube.com/kitabisacom` | `AboutSection.tsx` |
| Alamat `@kitabisa.com` di fixture tes; tes SEO mengharap nama organisasi "Kitabisa" | `src/__tests__/`, `src/lib/seo.test.ts` |

Bagian footer memang sudah memakai akun `fundforindonesia`, jadi penggantian
merek dimulai tetapi berhenti di tengah jalan.

## Kenapa ini penting untuk keputusan Anda

"Buat Makam sama seperti FFI" pada praktiknya berarti menyalin tampilan
Kitabisa lewat perantara. Kalau maksud Anda adalah *"samakan rasanya dengan
situs kami yang lain"*, itu wajar dan aman dikerjakan. Kalau yang dibayangkan
adalah menyalin produk Kitabisa, itu keputusan yang sebaiknya Anda ambil sadar,
bukan saya simpulkan dari satu kalimat.

Saya tidak menolak pekerjaannya. Saya hanya tidak mau Anda memutuskan tanpa
fakta ini.

## Hal kedua: dua instruksi Anda sendiri bertabrakan

Enam hari sebelum permintaan ini, Anda menyerahkan
`MAKAM_CO_ID_Brand_Guideline_Visual_2026.pdf` dan mengonfirmasi bahwa itu
**mengganti** palet lama. Itu menjadi ADR-0041 dan sudah terpasang sebagai
token di kode.

| | Makam, sesuai pedoman Anda | FFI |
|---|---|---|
| Warna utama | Forest, hijau tua | Biru |
| Aksen | Sand, krem hangat | Oranye |
| Huruf | Plus Jakarta Sans | Inter |

Pedoman itu juga, di halaman 09, **melarang** fotografi stok yang dipentaskan
dan dramatisasi duka. Beranda FFI justru dibangun dari foto stok dan kartu
kampanye mendesak dengan hitungan mundur. Jadi menyalin struktur berandanya
akan melanggar pedoman merek yang Anda serahkan sendiri.

## Apa yang saya kerjakan sambil menunggu jawaban Anda

Hanya bagian yang aman apa pun keputusan Anda nanti, yaitu **lapisan sistem**
yang tidak membawa identitas siapa pun:

- skala sudut membulat dan dua tingkat bayangan kartu, ditulis ulang memakai
  token Makam
- konvensi kerangka abu-abu saat halaman memuat
- **navigasi bawah di ponsel**, dengan tab milik Makam sendiri, yaitu Beranda,
  Pemesanan, Perpanjangan, Akun, Bantuan

Yang terakhir itu kemungkinan besar yang paling Anda rasakan saat melihat FFI,
dan justru peningkatan nyata untuk keluarga yang memakai satu tangan sambil
mengurus hal lain.

Yang **tidak** saya kerjakan tanpa jawaban Anda: mengganti warna dan huruf
Makam, menyalin struktur beranda FFI, atau mencabut pedoman merek Anda.

## Tiga pertanyaan untuk Anda

1. Setelah tahu FFI adalah kloning Kitabisa, apakah Anda tetap ingin Makam
   menyerupainya?
2. Apakah Makam dan FFI memang dimaksudkan terbaca sebagai dua produk dari
   satu organisasi? Kalau ya, sebaiknya kita buat gaya rumah yang disepakati,
   bukan satu situs meniru yang lain.
3. Apakah pedoman merek Makam 2026 tetap berlaku? Kalau Anda ingin
   menggantinya, itu perlu pernyataan eksplisit, karena seluruh palet dan
   kontras warna di kode dibangun di atasnya.

Catatan terpisah, di luar Makam: sisa jejak Kitabisa di situs FFI sendiri
sebaiknya dibereskan, terlepas dari keputusan soal Makam.
