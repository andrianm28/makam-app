# Rekonsiliasi PRD YIEM 18 Sep 2026 — rencana eksekusi

Dokumentasi saja. Brainstorming dilakukan sebagai sesi grill 42 pertanyaan
pada 19 Sep 2026 (`grill-with-docs` atas PRD Makam.co.id 18 Sep 2026);
keputusannya tercatat di `docs/product/prd-yiem-2026-09-18.md` §16.

## Cakupan

1. Tulis PRD rekonsiliasi ke `docs/product/prd-yiem-2026-09-18.md`: teks
   asli dipertahankan, baris yang bertentangan dengan keputusan repo direvisi
   dan ditandai, ditambah tabel rekonsiliasi per MK, catatan keputusan, dan
   tindak lanjut.
2. Tambah dua katalog kanonik baru: `facility-catalog.md` dan
   `required-document-catalog.md` di `docs/product/`.
3. Tambah tiga istilah ke `docs/domain/domain-model.md` §6.
4. Catat kebijakan jendela dan penerima pengingat di
   `docs/contracts/notification-matrix.md` tanpa menyentuh baris tabel yang
   dipin tes.

## Di luar cakupan

Amandemen spec (`cemetery-directory-and-availability`,
`public-home-and-navigation`, `renewal-and-grave-registry`), migrasi kolom
`cemeteries`, dan halaman baru. Semuanya terdaftar sebagai tindak lanjut di
PRD §17 dengan spec pemiliknya.

## Commit kedua

Q31–Q42 (lapisan tindak lanjut) dijawab setelah commit pertama; PRD §16, §17,
MK-04, MK-06, MK-13, §3, §5, dan §13 diperbarui agar PRD dan 42 keputusan
satu suara. Amandemen spec (Q41) menjadi PR bertingkat terpisah.

## Verifikasi

`ci/verify-docs.sh` di worktree sebelum commit; commit dan verifikasi tidak
pernah dalam satu invokasi.
