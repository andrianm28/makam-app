## Problem Statement

Keluarga dan stakeholder membaca panduan yang saling bertentangan: wizard pemesanan yang live merender empat langkah, sementara dokumen MVP, README, dan dua artikel FAQ masih menarasikan sembilan langkah berurutan termasuk pembayaran sebagai langkah kedelapan. Ketidakcocokan ini membuat klaim "memenuhi PRD" tidak bisa dinyatakan dengan jujur: pengguna bingung, admin mewarisi acuan usang, dan setiap audit PRD gagal pada baris pertama sebelum menyentuh marketplace, perpanjangan, atau fallback pembayaran.

## Solution

Jadikan empat langkah sebagai satu-satunya narasi kanonis di seluruh permukaan pengguna dan dokumen hidup, perbaiki dua artikel FAQ seed beserta test penguncinya, dan selaraskan dokumen MVP serta inventaris screen ke kosakata empat langkah dengan catatan historis yang eksplisit. Setelah itu, validasi public-render membuktikan tidak ada lagi teks sembilan langkah di permukaan mana pun, dan validasi drift membuktikan katalog FAQ tetap sinkron dengan kode.

## User Stories

1. As a keluarga yang memesan makam, I want panduan FAQ yang menyebut empat langkah yang sama dengan wizard yang saya lihat, so that saya tidak ragu langkah mana yang benar.
2. As a keluarga yang membaca cara memesan, I want artikel cara memesan menjelaskan alur cari-pilih, data pemesan dan almarhum, pembayaran, konfirmasi, so that saya tahu apa yang disiapkan di tiap tahap.
3. As a keluarga yang bertanya kapan membayar, I want artikel pembayaran menjelaskan pembayaran terjadi pada tahap pembayaran setelah data dan ringkasan terkonfirmasi, so that saya tidak mencari "langkah kedelapan dari sembilan".
4. As a pemesan yang memakai wizard, I want stepper menampilkan empat titik dengan label Cari dan Pilih, Detail Pemesanan, Pembayaran, Konfirmasi, so that progres saya jelas.
5. As a pemesan yang memilih kota, I want hanya lima kota Jabodetabek sebagai opsi kanonis, so that saya tidak memesan di luar cakupan launch.
6. As a pemesan yang memilih TPU/TPS, I want kartu lokasi menampilkan tipe, nama, foto, alamat, fasilitas, rentang harga beserta sumber, dan status availability, so that saya memutuskan berbasis info yang diatribusikan.
7. As a pemesan yang memilih jenis layanan, I want empat opsi Makam Baru, Makam Tumpang, Urgent, Pre-Need, so that kebutuhan saya terpetakan ke alur yang benar.
8. As a pemesan yang memilih layanan, I want layanan dasar dan tambahan dari katalog kanonis beserta pemilik fulfillment dan harga per baris, so that tidak ada label inventaris.
9. As a pemesan yang meninjau ringkasan, I want kartu ringkasan persisten dengan line item dan total, so that saya tidak kehilangan konteks saat mengisi data.
10. As a pemesan yang mengisi data diri, I want form data pemesan tervalidasi server dengan data yang tersimpan saat kembali ke tahap sebelumnya, so that saya tidak mengulang input.
11. As a pemesan yang mengisi data almarhum, I want pesan jujur bahwa dokumen belum perlu disiapkan di wizard, so that saya tidak mencari tombol upload yang tidak ada.
12. As a pemesan yang membayar, I want cabang manual selalu tersedia dan cabang online hanya muncul saat gate pembayaran terbuka, so that fallback tertutup tidak menghapus tahap pembayaran dari UX.
13. As a pemesan yang menerima konfirmasi, I want nomor pesanan, status, status notifikasi email dan WhatsApp yang jujur, dan langkah berikutnya plus bantuan, so that saya tahu apa yang terjadi setelah bayar.
14. As a pembeli marketplace, I want katalog sembilan produk dari tiga keluarga yang sama dengan kode kanonis, so that yang saya beli adalah yang didukung.
15. As a pembeli marketplace, I want batas satu vendor per checkout dijelaskan dan konflik ditawarkan sebagai checkout terpisah atau penggantian, so that barang saya tidak hilang diam-diam.
16. As a ahli waris yang memperpanjang, I want pencarian makam fuzzy dengan empty state jujur dan jalur input manual atau bantuan, so that keterbatasan data tidak disamarkan.
17. As a ahli waris yang membayar perpanjangan, I want biaya menampilkan sumber tarif dan waktu pembaruan terakhir, so that saya percaya angkanya.
18. As a pembaca FAQ, I want enam kategori wajib dengan list, filter kategori, detail artikel, pencarian sederhana, dan CTA customer service, so that saya menemukan jawaban mandiri.
19. As a pembaca FAQ, I want artikel draft tidak pernah muncul di permukaan publik mana pun, so that konten belum terbit tidak bocor.
20. As a administrator, I want dokumen MVP dan inventaris screen memakai kosakata empat langkah yang sama dengan kode, so that saya tidak mengoperasikan dua kebenaran.
21. As a auditor, I want catatan historis eksplisit bahwa sembilan langkah adalah warisan RKS dan empat langkah adalah kanonis sejak keputusan owner 2 Sep 2026, so that sejarah tidak dihapus dan masa kini tidak ambigu.
22. As a auditor, I want test pengunci teks basi dimutakhirkan bersama seed-nya, so that suite hijau berarti narasi benar, bukan basi yang dikunci.
23. As a finance, I want tidak ada klaim pembayaran dari URL kembali browser, so that status dibayar hanya berasal dari bukti terverifikasi atau webhook.
24. As a operator, I want opsi Urgent menampilkan jam dan cakupan jujur serta hotline saat di luar kapasitas, so that saya tidak menjanjikan yang tidak bisa dipenuhi.
25. As a pendaftar Pre-Need saat gate legal tertutup, I want halaman minat dengan banner yang tidak bisa ditutup dan tanpa sesi pembayaran, so that ekspektasi saya benar sejak awal.

## Implementation Decisions

- Empat langkah adalah kanonis; sembilan langkah adalah historis. Setiap narasi pengguna memakai empat tahap: cari dan pilih, data pemesan dan almarhum, pembayaran, konfirmasi.
- Ringkasan adalah kartu persisten pada tahap data, bukan tahap bernomor sendiri.
- Fallback pembayaran manual adalah bagian permanen dari tahap pembayaran, bukan opsi paralel; cabang online hanya dirender saat gate pembayaran terbuka.
- Kosakata kanonis mengikuti alias Indonesia-UX dan Inggris-kode: unit plot, blok, booking kunjungan, langganan perawatan, hold sebagai reservasi, draft yang disubmit menjadi order dengan diskriminator tipe produk, serta perbedaan bunga Pre-Need yang tidak pernah di-gate versus kasus Pre-Need berbayar yang fail-closed.
- Pre-Need berbayar mustahil saat gate legal tertutup; yang hidup hanyalah pendaftaran minat dan konsultasi.
- Tidak ada klaim pengiriman WhatsApp saat gate tertutup; status yang dirender adalah belum tersedia.
- Perubahan teks seed FAQ dilakukan bersama test penguncinya dalam satu perubahan atomik.
- Dokumen MVP dan inventaris screen direvisi minimal dengan catatan historis, tanpa rewrite sejarah keputusan.
- Invoice dan timeline detail per-order tetap di luar spec ini bila belum dibangun; konfirmasi booking tidak mengarang tautan yang tidak ada.

## Testing Decisions

- **Seam under test (utama): public render.** Setiap perilaku dan edge case dicakup MELALUI render publik: wizard pemesanan, daftar dan filter dan detail dan pencarian FAQ, halaman produk dan keranjang dan checkout marketplace, serta layar cari dan bayar dan konfirmasi perpanjangan. Bentuk input tidak biasa, kondisi batas, dan jalur kegagalan ikut dicakup lewat seam ini. Helper internal diuji tidak langsung lewat seam, tidak pernah langsung. Nilai harapan dalam test adalah literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.
- **Seam under test (sekunder): catalog-vs-seed drift.** Sinkronisasi katalog FAQ dengan kode dan seed dicakup MELALUI perbandingan kode katalog terhadap seed yang ditanam, mengikuti prior art yang sudah ada untuk katalog produk dan kode layanan. Nilai harapan adalah kode dan label literal dari katalog.
- Test yang baik hanya menguji perilaku eksternal (teks yang dirender, status pengiriman yang ditampilkan, transisi yang diizinkan), bukan detail implementasi (nama konstanta, struktur migrasi, isi file dokumen).
- Modul yang diuji: wizard publik, permukaan FAQ publik, seed FAQ, dokumen MVP dan inventaris screen sebagai artefak yang dirender secara tidak langsung lewat klaimnya.
- Prior art: server-side HTTP dan Livewire feature tests untuk rute FAQ dan artikel, screen tests untuk keranjang dan checkout, dan drift tests untuk katalog produk dan kode layanan.

## Out of Scope

- Membangun scheduler pengingat per makam per jendela (akui belum dibangun; spec terpisah).
- Membangun tautan invoice dan timeline detail per-order bila belum ada (tidak mengarang tautan).
- Autosave berjangka sepuluh detik dan adopsi draft anonim saat login (tetap klaim eksplisit per tahap).
- Upload dokumen almarhum di dalam wizard (tetap pesan jujur tanpa upload; vault domain terpisah).
- Precondition makam tumpang, SLA Urgent, dan availability per layanan (tetap disclosed absent).
- Migrasi data historis di luar dua artikel FAQ seed dan dokumen yang disebut.
- Superseding ADR-0038 (utang tata kelola terpisah; spec ini beroperasi di bawah konvensi specflow yang sudah diputuskan owner).

## Further Notes

- Keputusan 2 Sep 2026 oleh owner adalah otoritas yang membuat empat langkah menang atas sembilan langkah warisan.
- Spec ini adalah spec specflow pertama di `.scratch/` setelah setup; `.kiro/specs/*` adalah arsip beku dan tidak dikonsultasikan untuk status kini.
- Validasi penuh PRD memakai spec ini sebagai pintu pertama: selama narasi langkah masih ganda, klaim marketplace, perpanjangan, dan fallback tidak bisa dinyatakan utuh.
