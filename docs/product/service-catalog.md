# Canonical Funeral Service Catalog — MVP

## Basic services

| Code | Label | Required behavior |
|---|---|---|
| DOCUMENT_PROCESSING | Pengurusan Dokumen | Definition, price/source, provider, availability |
| GRAVE_DIGGING | Penggalian Makam | Definition, price/source, provider, availability |

## Additional services

| Code | Label |
|---|---|
| AMBULANCE | Ambulans |
| FUNERAL_HOME | Rumah Duka |
| HEARSE | Mobil Jenazah |
| TENT_AND_CHAIRS | Tenda & Kursi |
| SOUND_SYSTEM | Sound System |
| FLOWERS | Karangan Bunga |
| GRAVESTONE | Batu Nisan |
| DOCUMENTATION | Dokumentasi |
| CATERING | Konsumsi |
| LIVE_STREAMING | Live Streaming |

## Catalog rules

- Admin manages definition, price, provider, area, schedule, and availability without deployment.
- Availability can be restricted per cemetery/location.
- Price is versioned and snapshot into quote/order.
- Unavailable service remains visible only when useful, with reason and alternative.
- Every service declares fulfillment owner: platform, cemetery operator, or vendor.
- Services requiring schedule expose date/time window.
- Sensitive services may require manual confirmation.
