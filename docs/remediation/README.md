# Remediasi Temuan Audit — Indeks

## Status

**Sumber kebenaran tunggal untuk status 343 temuan audit teknik 6 September 2026.** Data lengkapnya ada di [`findings.yml`](findings.yml); dokumen ini hanya indeks dan aturan mainnya.

## Kenapa file ini ada

Sebelum ini, satu-satunya salinan 343 temuan berada di scratchpad sesi agent yang **ephemeral dan di luar version control**. Rencana induk ([`../superpowers/plans/2026-09-06-remediasi-audit-makam.md`](../superpowers/plans/2026-09-06-remediasi-audit-makam.md)) secara sadar memilih tidak membuat ledger di repo dan menunjuk file scratchpad itu sebagai sumber tunggal.

Konsekuensinya: program remediasi **tidak bisa dihentikan dengan aman** — berhenti di tengah jalan tidak meninggalkan catatan apa pun tentang sisa pekerjaan, dan tidak ada cara memeriksa apakah sebuah temuan sudah tertutup selain membaca ulang seluruh riwayat PR.

## Ringkasan saat ini

| Severity | open | mitigated | in_review | resolved | Total |
|---|---|---|---|---|---|
| **Critical** | 1 | 1 | 1 | 1 | 4 |
| **High** | 2 | 1 | 5 | 9 | 17 |
| **Medium** | 111 | 1 | 28 | 52 | 192 |
| **Low** | 106 | 0 | 0 | 0 | 106 |
| **Info** | 24 | 0 | 0 | 0 | 24 |
| **Total** | **244** | **3** | **34** | **62** | **343** |

**Dua dari empat temuan Critical belum tertutup**, dan keduanya adalah aksi tingkat host yang harus dieksekusi manusia (`AGENTS.md` §Infrastructure-agent execution):

| Id | Status | Inti masalah |
|---|---|---|
| `CI-01` | **open** | Basis data produksi `makam_beta` **belum pernah dicadangkan sama sekali** — skrip backup hanya melooping `makam_dev` dan `makam_stg`. |
| `COORD-07` | **mitigated** | Tidak ada worker yang mengonsumsi antrean `media` di host beta, sehingga dokumen tidak pernah keluar dari karantina. PR #240 baru menambah alert, bukan perbaikannya. |
| `SEC-02` | in_review | Perubahan rekening bank tanpa re-autentikasi — perbaikannya ada di PR #242 yang masih terbuka. |
| `DOM-01` | resolved | Penghapus draft destruktif; sudah tertutup penuh oleh PR #241. |

## Kosakata status

| Status | Arti |
|---|---|
| `open` | Belum ada PR yang menangani temuan ini |
| `mitigated` | Stop-gap atau alert sudah terpasang, perbaikan permanen belum |
| `in_review` | Ditangani PR yang masih terbuka |
| `resolved` | Ditangani PR yang sudah merge |

`status` hanya boleh naik: `open` → `mitigated` → `in_review` → `resolved`.

## Seberapa jauh status ini bisa dipercaya

Status diturunkan secara mekanis dari **rujukan id temuan di badan PR**, lalu dikoreksi manual di tempat yang derivasi itu salah.

- `status_verified: true` — status sudah diperiksa manusia/agent terhadap isi PR-nya. Field `status_note` memuat kutipan yang jadi dasarnya. **Seluruh tingkat Critical dan High sudah diperiksa satu per satu.**
- `status_verified: false` — status murni hasil derivasi. Berlaku untuk tingkat Medium ke bawah.

Derivasi mekanis **melebih-lebihkan** dengan pola yang bisa diprediksi: sebuah PR kerap menyebut id temuan yang justru dinyatakannya *tidak* ditangani. Contoh nyata yang sudah dikoreksi:

> PR #247: *"CI-02 and COORD-17 are intentionally **not** implemented"* — regex menandainya `resolved`; keduanya kini `open`.

> PR #240: *"COORD-07 (Critical) — visibility added"* dan *"Permanent COORD-07 fix: an actual `media`-queue consumer on the beta host (host-level change, human-executed)"* — kini `mitigated`, bukan `resolved`.

Karena itu, **pass konfirmasi untuk 62 temuan berstatus `resolved` di tingkat Medium masih merupakan pekerjaan terbuka.** Jangan perlakukan `status_verified: false` sebagai fakta.

## Aturan pakai

1. **Jangan duplikasi isi `findings.yml` ke dokumen lain** (`AGENTS.md` §Documentation). Dokumen lain merujuk id-nya, tidak menyalin pernyataannya.
2. Setiap PR remediasi menyebut id temuan yang ditutupnya di judul atau badan PR — itulah yang membuat status bisa diturunkan.
3. Saat sebuah PR merge, perbarui `status` dan `status_evidence` temuan terkait di PR yang sama.
4. Bila mengoreksi status secara manual, isi `status_note` dengan kutipan buktinya dan set `status_verified: true`.

## Bentuk data

Setiap record di `findings.yml`:

| Field | Isi |
|---|---|
| `id` | `<DIM>-<NN>`, mis. `SEC-02`. 29 dimensi. |
| `dim` `severity` `category` `effort` | Klasifikasi dari audit |
| `audit_verdict` | Hasil verifikasi adversarial audit itu sendiri: `CONFIRMED`, `PARTIALLY_CONFIRMED`, `COORDINATOR-VERIFIED`, `UNVERIFIED`, `REFUTED` |
| `status` `status_evidence` `status_verified` `status_note` | Pelacakan remediasi (lihat di atas) |
| `files` | Path yang dikutip temuan |
| `statement` | Pernyataan temuan terverifikasi |
| `recommendation` | Rekomendasi perbaikan dari audit |

Catatan: `audit_verdict: UNVERIFIED` (275 temuan) berarti temuan itu **tidak** melalui putaran verifikasi adversarial dua-lensa — putaran itu hanya dijalankan untuk tingkat Critical dan High. Ini bukan berarti temuannya salah, tapi bobot buktinya lebih ringan.
