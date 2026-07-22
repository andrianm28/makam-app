# Product Brief — Makam.co.id v0.3

## 1. Ringkasan

Makam.co.id adalah **Indonesia End-of-Life Services Platform** yang membantu keluarga menemukan TPU/TPS, memesan makam dan layanan pemakaman, membeli produk funeral marketplace, memperpanjang makam, memperoleh informasi melalui FAQ, serta berinteraksi dengan administrator dan vendor.

MVP wajib menampilkan empat jalur publik:

1. Pemesanan Makam
2. Layanan Pemakaman / Funeral Marketplace
3. Perpanjangan Makam
4. FAQ

Platform juga menyediakan Dashboard Admin dan Dashboard Vendor.

## 2. Masalah yang diselesaikan

Keluarga harus mencari lokasi, ketersediaan, biaya, dokumen, vendor, dan status pelayanan pada saat kondisi emosional berat. Pengelola dan vendor bekerja dengan data, jadwal, pesanan, pembayaran, serta komunikasi yang tersebar. Makam.co.id menggabungkan discovery, orchestration, checkout, status, dan administrasi dalam satu pengalaman yang jelas.

## 3. Target pengguna

| Aktor | Tujuan |
|---|---|
| Pemesan/keluarga | Memesan makam dan layanan dengan langkah jelas |
| Ahli waris | Menemukan dan memperpanjang makam |
| Pembeli marketplace | Memesan bunga, nisan, atau perawatan |
| Administrator platform | Mengelola master data, order, payment, vendor, FAQ, dan laporan |
| Pengelola TPU/TPS | Memberi konfirmasi availability dan melihat order lokasinya |
| Vendor | Mengelola katalog, jadwal, order, status, bukti, dan transaksi |
| Finance/support | Menangani pembayaran, invoice, exception, dan bantuan |

## 4. MVP scope yang disetujui

### 4.1 Homepage

Empat menu utama harus tampil jelas dan dapat diakses di desktop maupun mobile:

- Pemesanan Makam
- Layanan Pemakaman
- Perpanjangan Makam
- FAQ

### 4.2 Pemesanan Makam

Sembilan langkah:

1. Pilih Kota/Kabupaten: Jakarta, Bogor, Depok, Tangerang, Bekasi.
2. Pilih TPU/TPS: tipe, nama, foto, alamat, Google Maps, fasilitas, harga, availability.
3. Pilih jenis layanan: Makam Baru, Makam Tumpang, Urgent, Pre-Need.
4. Pilih layanan dasar dan tambahan.
5. Ringkasan pesanan dan rincian biaya.
6. Data pemesan.
7. Data almarhum dan upload dokumen.
8. Pembayaran.
9. Konfirmasi: invoice, email, WhatsApp bila aktif, serta notifikasi admin/pengelola.

### 4.3 Funeral Marketplace

Kategori minimum:

- Karangan Bunga: papan dan bunga tabur.
- Batu Nisan: granit, marmer, kaligrafi.
- Perawatan Makam: bulanan, tiga bulanan, enam bulanan, tahunan.

Flow minimum:

```text
Pilih Produk/Paket
→ Keranjang/Checkout
→ Pembayaran
→ Vendor Memproses
→ Status/Bukti
```

### 4.4 Perpanjangan Makam

```text
Pilih Kota
→ Pilih TPU/TPS
→ Cari Data Makam
→ Tampilkan Biaya
→ Pembayaran
→ Konfirmasi dan Invoice
```

### 4.5 FAQ

Topik minimum:

- Cara memesan
- Dokumen
- Pembayaran
- Perpanjangan
- Pembayaran gagal
- Customer service

### 4.6 Dashboard Admin

Mengelola TPU/TPS, vendor, transaksi, pembayaran, status pesanan, FAQ, dan laporan.

### 4.7 Dashboard Vendor

Login, kelola produk, menerima pesanan, update status, mengelola jadwal, dan melihat riwayat transaksi.

## 5. UX principles

1. Mobile-first, empatik, dan tidak menggunakan dark pattern.
2. Empat menu utama terlihat tanpa harus memahami istilah teknis.
3. Wizard menunjukkan step aktif, selesai, dan berikutnya.
4. Setiap langkah autosave serta dapat dilanjutkan lintas sesi.
5. Error menjelaskan tindakan perbaikan.
6. Harga dan status availability tidak boleh menyesatkan.
7. Urgent menampilkan kesiapan jam, wilayah, dan jalur bantuan manusia.
8. Pre-Need tetap dapat dipilih sebagai pendaftaran minat ketika payment/legal gate tertutup.
9. Payment success hanya berasal dari bukti server-side.
10. Semua halaman memiliki customer-service escape hatch.

## 6. Payment and notification behavior

- Online payment tersedia ketika shared money path dan merchant gate aktif.
- Ketika belum aktif, Step 8 menggunakan koordinasi pembayaran manual dan status `MENUNGGU_PEMBAYARAN`.
- Invoice diterbitkan setelah event pembayaran yang sah atau pencatatan pembayaran manual yang disetujui.
- Email wajib menjadi kanal baseline.
- WhatsApp berjalan hanya jika BSP/template gate aktif.
- Admin platform selalu menerima in-app notification untuk order baru dan payment exception.
- Pengelola TPU/TPS menerima notification untuk permintaan availability dan status relevan pada lokasi yang menjadi kewenangannya.

## 7. Exact initial launch area

- Jakarta
- Bogor
- Depok
- Tangerang
- Bekasi

Daftar ini dapat diperluas oleh admin setelah change approval, tetapi kelima wilayah wajib tersedia pada seed/config MVP.

## 8. Success metrics MVP

- Homepage-to-service click-through.
- Booking wizard completion rate.
- Draft resume success.
- Availability-confirmation time.
- Payment completion/manual-confirmation rate.
- Marketplace order acceptance and on-time fulfillment.
- Grave-search success and renewal completion.
- FAQ self-service resolution and customer-service escalation.
- Zero duplicate invoice/journal.
- Zero unauthorized cross-vendor/cross-cemetery access.

## 9. MVP completion definition

MVP dinyatakan memenuhi ekspektasi hanya jika:

- empat menu publik tersedia;
- seluruh sembilan langkah dapat diselesaikan atau memakai fallback yang sah;
- seluruh kategori layanan dan marketplace minimum tersedia;
- perpanjangan enam langkah tersedia;
- enam kategori FAQ tersedia;
- admin dan vendor dashboard memenuhi acceptance criteria;
- notification matrix lulus;
- responsive browser tests dan critical journey tests lulus.

## 10. Post-MVP/gated capabilities

Specific plot reservation, plot map, paid Pre-Need, automated vendor settlement, card-on-file, memorial/QR, visitation, dan multi-vendor settlement tetap gated atau post-MVP. Gate tersebut tidak boleh menghilangkan atau memalsukan flow MVP baseline.
