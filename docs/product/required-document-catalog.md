# Canonical Required-Document Catalog — MVP

**Status:** approved 19 Sep 2026 through the PRD reconciliation grill
(`docs/product/prd-yiem-2026-09-18.md` §16, decisions Q15, Q22, Q30). Not yet
implemented: no `cemeteries` column or admin field exists for this list.

## What this is, and is not

This is the list of documents a family must **bring to the cemetery
operator** after booking. It is printed on the order confirmation screen and
in the confirmation email (PRD MK-04 "daftar dokumen yang perlu dibawa"). It
is **not** an upload requirement: the booking wizard deliberately asks for no
uploads at the deceased-data step (`resources/views/livewire/public/booking/wizard.blade.php`,
the "tidak meminta unggahan dokumen apa pun" copy), and uploaded documents
follow `docs/domain/document-and-certificate-lifecycle.md`, not this file.

## Platform default

Shown for every cemetery that has not overridden the list.

| Code | Label | Applies to |
|---|---|---|
| KTP_PEMESAN | KTP pemesan | booking, renewal |
| KK | Kartu Keluarga | booking, renewal |
| SURAT_KETERANGAN_KEMATIAN | Surat keterangan kematian (RS, puskesmas, atau kelurahan) | booking |
| KTP_ALMARHUM | KTP almarhum, bila ada | booking |
| BUKTI_HAK_MAKAM | Bukti hak/izin penggunaan makam terakhir (IPTM atau setara) | renewal |

## Optional codes an operator may add

| Code | Label |
|---|---|
| SURAT_PENGANTAR_RT_RW | Surat pengantar RT/RW |
| SURAT_KUASA | Surat kuasa ahli waris |
| BUKTI_BAYAR_MANUAL | Bukti transfer, bila membayar lewat jalur manual |

## Catalog rules

- Admin edits the per-cemetery list in the admin panel; the operator panel
  reads it in release 1 (ADR-0008: the operator panel never blocks a flow).
- A cemetery with no override shows the platform default, never an empty
  list.
- Codes are closed; a cemetery-specific document not in either table is a
  product change approval, not a free-text row.
- The list is snapshotted onto the order at confirmation so a later edit
  does not rewrite what a family was told to bring.
