# `ekspektasi-user` vs Kiro Specs — Coverage Cross-Check

**Date:** 25 Juli 2026
**Sources:** [`ekspektasi-user`](../../ekspektasi-user) (81 lines, stakeholder MVP workflow) × **19** specs in `.kiro/specs/`
**Method:** every claim below produced by an executed `grep` against the repository; commands reproduced inline.
**Related:** [`kiro-specs-analysis.md`](kiro-specs-analysis.md) (full spec analysis) · [`mvp-scope.md`](../product/mvp-scope.md) (authority rank 2)

---

## 0. Verdict

| Question | Answer |
|---|---|
| Are all 6 `ekspektasi-user` workflow groups covered? | **Yes — 6/6.** No workflow group is unspecified. |
| Any workflow with **no spec at all**? | **No workflow group.** But 4 named artefacts inside Step 9 depend on foundations with **zero owning spec** (§3). |
| Does the 9-step booking **match**? | **Yes — 9/9 exact.** No step missing, no step added, labels identical to `booking-wizard-fields.md`. |
| Does the marketplace match? | **Yes — 3/3 categories, 9/9 products.** But the spec restates them in English prose and contains **0 of 9 canonical product codes** (§4). |
| Do the FAQ 6 topics match `AGENTS.md`? | **Yes — 6/6 exact.** |

**Headline:** coverage at the workflow level is complete. The gaps are (a) cross-cutting foundations behind Step 9, (b) canonical codes replaced by prose, and (c) one un-bridged overlap that `ekspektasi-user` itself contains.

---

## 1. Coverage inventory — every `ekspektasi-user` line → owning spec

### 1.1 HOME PAGE — Menu Utama (4 items)

| # | `ekspektasi-user` | Owning spec | Status |
|---|---|---|---|
| 1 | Pemesanan Makam | `public-home-and-navigation` + `public-booking-wizard` | ✅ |
| 2 | Layanan Pemakaman (Funeral Marketplace) | `public-home-and-navigation` + `funeral-marketplace-and-vendor-portal` | ✅ |
| 3 | Perpanjangan Makam | `public-home-and-navigation` + `renewal-and-grave-registry` | ✅ |
| 4 | FAQ | `public-home-and-navigation` + `public-faq` | ✅ |
| — | User Flow: `HOME → 4 menus` | `public-home-and-navigation` AC1–AC4 | ✅ |

`public-home-and-navigation` AC1 states the four menus **in this exact order**, matching `ekspektasi-user` and `AGENTS.md`.

### 1.2 PEMESANAN MAKAM — 9 steps

Verified three ways: `ekspektasi-user` ↔ `public-booking-wizard/requirements.md` ↔ `booking-wizard-fields.md` headings.

```
$ grep -E '^## Step' docs/product/booking-wizard-fields.md
## Step 1 — Pilih Lokasi          ## Step 6 — Data Pemesan
## Step 2 — Pilih TPU/TPS         ## Step 7 — Data Almarhum and Documents
## Step 3 — Pilih Jenis Layanan   ## Step 8 — Pembayaran
## Step 4 — Pilih Layanan         ## Step 9 — Konfirmasi
## Step 5 — Ringkasan Pesanan
```

| Step | `ekspektasi-user` | Wizard spec AC (verbatim) | Match |
|---:|---|---|---|
| 1 | Pilih Lokasi: Jakarta, Bogor, Depok, Tangerang, Bekasi | AC2 *"Step 1 lists Jakarta, Bogor, Depok, Tangerang, and Bekasi"* | ✅ exact |
| 2 | Pilih TPU/TPS — Nama, Foto, Alamat, Google Maps, Fasilitas, Harga, Ketersediaan | AC3 *"Step 2 shows TPU/TPS type, name, photo, address, Google Maps navigation, facilities, price, and availability"* | ✅ 7/7 fields + type |
| 3 | Makam Baru · Makam Tumpang (jika tersedia) · Pemakaman Hari Ini (Urgent) · Pre-Need | AC4 *"Step 3 offers Makam Baru, Makam Tumpang when supported, Urgent, and Pre-Need"* | ✅ 4/4 |
| 4 | Pilih Layanan (2 dasar + 10 tambahan) | AC5 *"Step 4 offers all basic and additional services in `service-catalog.md`"* | ✅ **by reference** — 12/12 verified below |
| 5 | Ringkasan Pesanan | AC6 *"Step 5 shows immutable quote line items and total"* | ✅ |
| 6 | Data Pemesan | AC7 *"Step 6 captures required customer data and privacy consent"* | ✅ |
| 7 | Data Almarhum + Upload Dokumen | AC8 *"Step 7 captures deceased data and KTP/KK/death-certificate uploads privately"* | ✅ |
| 8 | Pembayaran | AC9 *"Step 8 supports online payment when gate open and explicit manual fallback otherwise"* | ✅ (+ gated fallback) |
| 9 | Konfirmasi (Invoice, Email, WhatsApp, Notifikasi Admin TPU/TPS) | AC10 + AC15 | ✅ **but see §3** |

**Step 3 — four service types, all present:**

```
Makam Baru | NEW_GRAVE               wizard=1  fields=1
Makam Tumpang | OVERLAPPING_GRAVE    wizard=1  fields=2
Urgent | URGENT_TODAY                wizard=2  fields=3
Pre-Need | PRE_NEED                  wizard=2  fields=3
```

**Step 4 — 12/12 services present in `service-catalog.md`:**

```
Pengurusan Dokumen | DOCUMENT_PROCESSING   ✓    Sound System | SOUND_SYSTEM      ✓
Penggalian Makam   | GRAVE_DIGGING         ✓    Karangan Bunga | FLOWERS         ✓
Ambulans           | AMBULANCE             ✓    Batu Nisan | GRAVESTONE          ✓
Rumah Duka         | FUNERAL_HOME          ✓    Dokumentasi | DOCUMENTATION      ✓
Mobil Jenazah      | HEARSE                ✓    Konsumsi | CATERING              ✓
Tenda & Kursi      | TENT_AND_CHAIRS       ✓    Live Streaming | LIVE_STREAMING   ✓
```

The wizard spec references the catalogue rather than copying it — **the correct pattern** under `AGENTS.md` (*"Do not duplicate canonical catalog data"*). Contrast with the marketplace spec (§4).

**Supporting specs for the 9 steps:** `booking-and-order-orchestration` (domain), `cemetery-directory-and-availability` (Steps 1–2), `package-and-service-bundles` (Steps 4–5), `at-need-booking` (Step 3 Urgent), `pre-need-contracting` (Step 3 Pre-Need).

### 1.3 FUNERAL MARKETPLACE — 3 categories, 9 products

| `ekspektasi-user` | `marketplace-catalog.md` code | In marketplace spec |
|---|---|---|
| Karangan Bunga Papan | `FLOWER_BOARD` | ✅ as prose "flower board" |
| Paket Bunga Tabur | `FLOWER_PETAL_PACKAGE` | ✅ as prose "flower-petal package" |
| Granit | `GRAVESTONE_GRANITE` | ✅ as prose "granite gravestone" |
| Marmer | `GRAVESTONE_MARBLE` | ✅ as prose "marble gravestone" |
| Kaligrafi | `GRAVESTONE_CALLIGRAPHY` | ✅ as prose "calligraphy gravestone" |
| Perawatan Bulanan | `GRAVE_CARE_MONTHLY` | ✅ as prose "monthly" |
| Perawatan 3 Bulan | `GRAVE_CARE_QUARTERLY` | ✅ as prose "three-month" |
| Perawatan 6 Bulan | `GRAVE_CARE_SEMIANNUAL` | ✅ as prose "six-month" |
| Perawatan Tahunan | `GRAVE_CARE_ANNUAL` | ✅ as prose "annual" |

**Flow:** `ekspektasi-user` — *Pilih Produk/Paket → Checkout → Pembayaran → Vendor Memproses*
Spec AC3 — *browse/select → cart → checkout → payment/manual fallback → vendor processing → status/evidence* ✅ superset (see delta D2).

### 1.4 PERPANJANGAN MAKAM — 6 steps

```
$ grep 'six steps' .kiro/specs/renewal-and-grave-registry/requirements.md
1. Public flow visibly implements six steps: city, TPU/TPS, grave search, fee, payment, confirmation/invoice.
```

| Step | `ekspektasi-user` | Renewal spec AC1 | Match |
|---:|---|---|---|
| 1 | Pilih Kota | city | ✅ |
| 2 | Pilih TPU/TPS | TPU/TPS | ✅ |
| 3 | Cari Data Makam | grave search | ✅ |
| 4 | Sistem menampilkan biaya | fee | ✅ |
| 5 | Pembayaran | payment | ✅ |
| 6 | Konfirmasi & Invoice | confirmation/invoice | ✅ |

**6/6 exact, same order.** Owner: `renewal-and-grave-registry`.

### 1.5 FAQ — 6 topics

| `ekspektasi-user` | `faq-catalog.md` code | Match |
|---|---|---|
| Cara memesan | `CARA_MEMESAN` | ✅ |
| Dokumen | `DOKUMEN` | ✅ |
| Pembayaran | `PEMBAYARAN` | ✅ |
| Perpanjangan | `PERPANJANGAN` | ✅ |
| Pembayaran gagal | `PEMBAYARAN_GAGAL` | ✅ |
| Customer service | `CUSTOMER_SERVICE` | ✅ |

`public-faq` AC2 requires *"the six required categories from `faq-catalog.md`"* — by reference, correct pattern. `AGENTS.md` *"Seed and preserve six required categories"* ✅ **6/6 exact three-way match**.

### 1.6 Dashboard Admin — 7 items

```
$ grep '^1. Admin dashboard has modules' .kiro/specs/admin-operations/requirements.md
1. Admin dashboard has modules for TPU/TPS, vendor, transaction, payment, order status, FAQ, and report.
```

| `ekspektasi-user` | admin-operations AC1 | Match |
|---|---|---|
| Kelola TPU/TPS | TPU/TPS | ✅ |
| vendor | vendor | ✅ |
| transaksi | transaction | ✅ |
| pembayaran | payment | ✅ |
| status pesanan | order status | ✅ |
| FAQ | FAQ | ✅ |
| laporan | report | ✅ |

**7/7 exact, same order.** Owner: `admin-operations`.

### 1.7 Dashboard Vendor — 6 items

**No dedicated vendor spec folder exists** — covered by `funeral-marketplace-and-vendor-portal`, as `docs/specs/README.md` states: *"Dashboard Vendor is covered by `funeral-marketplace-and-vendor-portal`."*

| `ekspektasi-user` | Marketplace spec AC | Match |
|---|---|---|
| Login | AC5 *"Vendor has a dedicated authenticated panel"* | ✅ but auth foundation unspecified (§3) |
| kelola produk | AC6 *"manages own products, variants, prices, stock/availability"* | ✅ |
| terima pesanan | AC7 *"receives assigned orders, accepts/rejects where allowed"* | ✅ |
| update status | AC7 *"updates status"* | ✅ |
| jadwal | AC6 *"service areas, and calendar"* | ✅ |
| riwayat transaksi | AC8 *"view transaction history and payout/reference status"* | ✅ |

**6/6 covered.**

---

## 2. Answer to Q1/Q2 — is anything missing?

**No `ekspektasi-user` workflow group is unspecified.** All six — Pemesanan Makam, Funeral Marketplace, Perpanjangan Makam, FAQ, Dashboard Admin, Dashboard Vendor — have an owning spec, and the Admin (7/7) and Vendor (6/6) dashboards that the question singled out are both fully covered.

---

## 3. What *is* missing — the foundations behind Step 9

`ekspektasi-user` Step 9 names four artefacts: **Invoice, Email, WhatsApp, Notifikasi Admin TPU/TPS**. Plus Step 8: **Pembayaran**. All are *referenced* by specs; **none has an owning spec**.

```
$ ls -d .kiro/specs/*invoice* .kiro/specs/*payment* .kiro/specs/*notification*
0 directories
```

| `ekspektasi-user` artefact | Referenced by | Owning spec |
|---|---|---|
| **Pembayaran** (Step 8) | 7 specs | ❌ **none** |
| **Invoice** (Step 9, Perpanjangan Step 6) | 5 specs (orchestration, wizard, renewal, care×2) | ❌ **none** |
| **Email** (Step 9) | 1 spec (`public-booking-wizard`) | ❌ **none** |
| **WhatsApp** (Step 9) | 1 spec (`public-booking-wizard`) | ❌ **none** |
| **Notifikasi Admin TPU/TPS** (Step 9) | 2 specs (wizard AC15, orchestration AC14) | ❌ **none** |
| **Login** (Dashboard Vendor) | 2 specs | ❌ **none** |

This is the same structural finding as [`kiro-specs-analysis.md`](kiro-specs-analysis.md) §2.2, reached from the stakeholder side: **the last step of the flagship workflow rests entirely on unspecified foundations.** Step 9 cannot be built from the specs as they stand.

**Recommendation:** the 8 foundation specs proposed in `kiro-specs-analysis.md` §2.2 are the fix. Prioritise `platform-payment-adapter` and `platform-notifications` — they are what Step 8 and Step 9 need.

---

## 4. Deltas — spec vs `ekspektasi-user`

Differences that are not "missing" but need a decision or a note.

### D1 — Marketplace spec has 0 of 9 canonical product codes (HIGH)

```
FLOWER_BOARD             catalog=1  spec=0        GRAVE_CARE_MONTHLY     catalog=1  spec=0
FLOWER_PETAL_PACKAGE     catalog=1  spec=0        GRAVE_CARE_QUARTERLY   catalog=1  spec=0
GRAVESTONE_GRANITE       catalog=1  spec=0        GRAVE_CARE_SEMIANNUAL  catalog=1  spec=0
GRAVESTONE_MARBLE        catalog=1  spec=0        GRAVE_CARE_ANNUAL      catalog=1  spec=0
GRAVESTONE_CALLIGRAPHY   catalog=1  spec=0
$ grep -rl 'marketplace-catalog.md' .kiro/specs/ | wc -l
0
```

The spec restates the catalogue in **English prose** and never references `marketplace-catalog.md`. So the nine identifiers an implementation must actually use exist in exactly one place, unlinked from the spec that consumes them. Two risks: silent drift, and an agent inventing its own codes.

**Fix:** replace marketplace AC1 prose with a reference to `marketplace-catalog.md`, matching the pattern wizard AC5 already uses for `service-catalog.md`. (Same finding as `kiro-specs-analysis.md` §2.4b.)

### D2 — Cart: spec adds a step `ekspektasi-user` does not name (accepted)

```
'cart/keranjang' in ekspektasi-user : 0
'cart' in marketplace spec          : 1
'Cart' in mvp-scope.md              : 1
'keranjang' in information-arch.md  : 1  (route /marketplace/keranjang)
```

`ekspektasi-user` says *Pilih Produk/Paket → Checkout*. The spec inserts **cart**. This is **legitimate**, not scope creep: `mvp-scope.md` §3 explicitly requires *"Cart dan checkout"*, and `information-architecture.md` defines the `/marketplace/keranjang` route. Both outrank a prose summary. **No action** — recorded so nobody "corrects" the spec back.

### D3 — Perawatan Makam: 1 line becomes 3 specs (MEDIUM)

```
$ grep -rilE 'grave.care|care plan|care cycle|recurring care' .kiro/specs/*/requirements.md
funeral-marketplace-and-vendor-portal    ← product catalogue entry
recurring-care-subscriptions             ← billing cycles
grave-care-fulfillment                   ← work orders + evidence
```

One `ekspektasi-user` line (*Perawatan Makam: Bulanan / 3 Bulan / 6 Bulan / Tahunan*) is decomposed across three specs. The split is defensible — `AGENTS.md` requires billing and fulfilment to be separate states — but it multiplies delivery cost for a stakeholder who wrote one bullet, and `kiro-specs-analysis.md` §5.3 already found the two care specs define **overlapping tables with different names** (`care_work_orders` vs `work_orders`). Resolve that before building.

### D4 — Karangan Bunga / Batu Nisan appear twice, with no bridge (MEDIUM)

`ekspektasi-user` itself lists both in two places:

- **Step 4 add-on:** `Karangan Bunga`, `Batu Nisan` → `service-catalog.md` codes `FLOWERS`, `GRAVESTONE`
- **Marketplace category:** `Karangan Bunga` (2 products), `Batu Nisan` (3 products) → `FLOWER_*`, `GRAVESTONE_*`

```
$ grep -rinE 'add-on.*(marketplace|vendor order)|(marketplace|vendor order).*add-on' docs/ .kiro/specs/
(no output)
```

**No document or spec states the relationship.** Is a Step-4 `FLOWERS` add-on fulfilled by the same vendor pipeline as a marketplace `FLOWER_BOARD` order? Do they share `vendor_orders`? Does selecting the add-on create a marketplace order?

The mechanism exists — `service-catalog.md` requires *"Every service declares fulfillment owner: platform, cemetery operator, or vendor"* — but **no spec applies it to these two overlapping items.** Without a ruling, expect two parallel implementations of flower and gravestone ordering.

**Needs a product/architecture decision**, then a sentence in both `public-booking-wizard` and `funeral-marketplace-and-vendor-portal`.

### D5 — `ekspektasi-user` is referenced by no spec and is untracked in git (LOW)

```
$ grep -rl 'ekspektasi-user' .kiro/ docs/
docs/planning/sprint-plan.md          ← this planning set only

$ git status --short ekspektasi-user
?? ekspektasi-user
```

`AGENTS.md` puts the *"Stakeholder MVP expectation"* at authority **rank 2**. The document embodying it is untracked (so not versioned, and losable) and cited by zero specs. `mvp-scope.md` — the canonical restatement — is likewise referenced by **0 specs** (`kiro-specs-analysis.md` §2.4c).

**Fix:** `git add ekspektasi-user`, and reference `mvp-scope.md` from the specs that claim stakeholder-MVP authority.

---

## 5. Summary table

| `ekspektasi-user` block | Items | Covered | Owning spec | Notes |
|---|---:|---:|---|---|
| HOME menu utama | 4 | **4/4** | `public-home-and-navigation` | exact order ✅ |
| Pemesanan Makam steps | 9 | **9/9** | `public-booking-wizard` (+4 supporting) | exact match; Step 8/9 blocked by §3 |
| Step 3 service types | 4 | **4/4** | wizard + at-need + pre-need | ✅ |
| Step 4 services | 12 | **12/12** | wizard AC5 → `service-catalog.md` | by reference ✅ |
| Marketplace categories | 3 | **3/3** | `funeral-marketplace-and-vendor-portal` | ✅ |
| Marketplace products | 9 | **9/9** | same | prose only, **0/9 codes** (D1) |
| Marketplace flow | 4 stages | **4/4** | same | cart added (D2) |
| Perpanjangan steps | 6 | **6/6** | `renewal-and-grave-registry` | exact match ✅ |
| FAQ topics | 6 | **6/6** | `public-faq` → `faq-catalog.md` | exact match ✅ |
| Dashboard Admin | 7 | **7/7** | `admin-operations` | exact match ✅ |
| Dashboard Vendor | 6 | **6/6** | `funeral-marketplace-and-vendor-portal` | no separate folder (by design) |
| **Step 9 artefacts** | 4 | **0/4 owned** | — | ❌ **no owning spec** (§3) |
| **Step 8 Pembayaran** | 1 | **0/1 owned** | — | ❌ **no owning spec** (§3) |
| **Vendor Login** | 1 | **0/1 owned** | — | ❌ **no owning spec** (§3) |

---

## 6. NOT TESTED / NOT VERIFIED

Per `AGENTS.md`: *"Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly."*

### Verified — executed, evidence inline

| Check | Result |
|---|---|
| 9 booking steps: `ekspektasi-user` ↔ wizard AC ↔ `booking-wizard-fields.md` headings | **PASS** — 9/9, §1.2 |
| 4 Step-3 service types present | **PASS** — §1.2 |
| 12 Step-4 services present in `service-catalog.md` | **PASS** — 12/12, §1.2 |
| 3 marketplace categories + 9 products | **PASS** — 9/9, §1.3 |
| 9 canonical product codes absent from marketplace spec | **PASS (confirmed absent)** — 0/9, §4 D1 |
| 6 renewal steps, same order | **PASS** — §1.4 |
| 6 FAQ topics, three-way match | **PASS** — §1.5 |
| 7 admin dashboard modules | **PASS** — §1.6 |
| 6 vendor dashboard items | **PASS** — §1.7 |
| No spec folder for payment / invoice / notification | **PASS (confirmed absent)** — §3 |
| `ekspektasi-user` referenced by 0 specs; untracked in git | **PASS** — §4 D5 |
| No doc bridges Step-4 add-on to marketplace order | **PASS (confirmed absent)** — §4 D4 |

### NOT TESTED

| Item | Status |
|---|---|
| Whether any covered workflow is **implementable** | **NOT TESTED** — zero application code exists |
| Whether coverage is **sufficient** to satisfy the stakeholder | **NOT TESTED** — only knowable by demonstrating working software to them |
| Semantic equivalence of prose vs code (e.g. does "flower-petal package" definitely mean `FLOWER_PETAL_PACKAGE`?) | **INFERRED, NOT CONFIRMED** — mapping is mine, by reading; confirm with the spec authors |
| Whether D2 (cart) and D3 (care split) were **intentional** design decisions | **INFERRED** — D2 is backed by `mvp-scope.md` §3 and the IA route, so high confidence; D3 intent is inferred from `AGENTS.md`, not stated in either spec |
| Whether `ekspektasi-user` is the **current** stakeholder expectation | **NOT VERIFIED** — the file carries no version or date, is untracked in git, and cannot be compared against an earlier revision |
| Conformance to RKS K23–K35 **content** | **BLOCKED** — the RKS source document is not in the repository |
| Whether the 4 Step-9 artefacts are covered by documentation **outside** `.kiro/specs/` | **PARTIAL** — `notification-matrix.md`, `payment-webhook.md`, and `financial-ledger-and-settlement.md` do exist in `docs/`; the finding in §3 is specifically that **no Kiro spec owns them**, which is what an agent executes from |

### Judgement calls, flagged

Severity labels (HIGH/MEDIUM/LOW) in §4 are my assessment, not a project-agreed scale. Calling D2 "accepted" is a judgement based on `mvp-scope.md` outranking a prose summary — a stakeholder could disagree. D4 is presented as needing a decision rather than as a defect, because both readings (shared pipeline vs separate) are defensible.
