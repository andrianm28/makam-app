# Canonical Funeral Marketplace Catalog — MVP

## Categories and minimum products

### Karangan Bunga

- `FLOWER_BOARD` — Karangan Bunga Papan
- `FLOWER_PETAL_PACKAGE` — Paket Bunga Tabur

### Batu Nisan

- `GRAVESTONE_GRANITE` — Granit
- `GRAVESTONE_MARBLE` — Marmer
- `GRAVESTONE_CALLIGRAPHY` — Kaligrafi

Required variant attributes where applicable:

- size;
- material;
- color;
- inscription text;
- calligraphy style;
- preview/reference image.

### Perawatan Makam

- `GRAVE_CARE_MONTHLY` — Bulanan
- `GRAVE_CARE_QUARTERLY` — 3 Bulan
- `GRAVE_CARE_SEMIANNUAL` — 6 Bulan
- `GRAVE_CARE_ANNUAL` — Tahunan

## Marketplace flow

```text
Browse category
→ Choose product/package and variant
→ Add to cart
→ Select delivery/work schedule
→ Confirm service area and fee
→ Checkout
→ Payment or manual fallback
→ Vendor receives order
→ Vendor accepts/processes
→ Vendor updates status and evidence
→ Customer sees completion
```

## MVP operating constraint

A checkout may be restricted to products from one vendor. When a user adds another vendor’s item, the UI must:

1. offer separate checkout; or
2. ask to replace/currently split the cart.

It must not silently lose items or pretend multi-vendor settlement exists.

## Required product data

- vendor;
- category;
- name;
- description;
- photos;
- variants;
- price/version;
- stock or availability;
- service area;
- schedule;
- delivery fee rule;
- production lead time;
- cancellation policy;
- evidence requirement.

## Vendor processing statuses

```text
MENUNGGU_VENDOR
DITERIMA_VENDOR
DITOLAK_VENDOR
DIPROSES
DIKIRIM_OR_DIJADWALKAN
SELESAI
KOMPLAIN
DIBATALKAN
```
