# Order Lifecycle

## 1. Canonical states

```text
MASUK
-> DIVERIFIKASI
-> MENUNGGU_KETERSEDIAAN
-> PENAWARAN_TERKIRIM
-> DISETUJUI_PEMESAN
-> MENUNGGU_PEMBAYARAN
-> DIBAYAR
-> DIPROSES
-> SELESAI
```

Terminal branches:

```text
DITOLAK
DIBATALKAN
KEDALUWARSA
```

## 2. Transition matrix

| From | To | Actor/trigger | Guard | Required audit data |
|---|---|---|---|---|
| Draft submission | MASUK | Customer | Required data complete | actor, draft, timestamp |
| MASUK | DIVERIFIKASI | Admin | Identity/data review completed | verifier, note |
| DIVERIFIKASI | MENUNGGU_KETERSEDIAAN | Admin | PIC assigned | PIC, cemetery |
| MENUNGGU_KETERSEDIAAN | PENAWARAN_TERKIRIM | Admin | Availability confirmed manually | source, operator/admin note, quote version |
| PENAWARAN_TERKIRIM | DISETUJUI_PEMESAN | Customer | Quote not expired | customer, quote version |
| DISETUJUI_PEMESAN | MENUNGGU_PEMBAYARAN | Admin/system | Payment gate active and admin opens payment | actor, gate evidence |
| MENUNGGU_PEMBAYARAN | DIBAYAR | Valid webhook | Signature, amount, merchant, idempotency valid | provider transaction, journal ref |
| DIBAYAR | DIPROSES | Admin/vendor/operator | Fulfilment started | actor, work reference |
| DIPROSES | SELESAI | Admin | Completion evidence/confirmation | actor, evidence, note |

## 3. Branch rules

- `DITOLAK`: admin/operator availability input or verification decision; reason mandatory.
- `DIBATALKAN`: customer cancellation while policy permits; financial consequences are TBD.
- `KEDALUWARSA`: quote or payment window expired.
- No transition backward.
- Correction creates a new reasoned event/status or financial compensating action.

## 4. Urgent rules

- Feature flag and operating-hour check before submission.
- Priority assignment and separate notification route.
- Do not promise same-day fulfilment solely from software state.
- Operational escalation must exist outside the application.

## 5. Pre-Need rules

While legal gate closed:

```text
INTEREST_REGISTERED -> CONTACTED -> CLOSED
```

No invoice, payment session, or financial obligation may be created.
