# Booking Wizard — Field and Behavior Contract

## Global behavior

- Draft created at first meaningful input.
- Autosave after every valid step change and at least every 10 seconds while dirty.
- Draft resume works across sessions for authenticated users.
- Anonymous draft uses secure opaque token and is attached after login/verification.
- Progress displays 1–4.
- Back navigation preserves data.
- Step completion is server-validated.
- User cannot skip required upstream decisions.
- Every save is idempotent and versioned.

## Step 1 — Cari & Pilih

### Pilih Lokasi

Required:

- `city_or_regency_code`

Allowed MVP values:

- `JAKARTA`
- `BOGOR`
- `DEPOK`
- `TANGERANG`
- `BEKASI`

### Pilih TPU/TPS

Display:

- type: TPU/TPS;
- name;
- primary photo;
- address;
- latitude/longitude;
- `google_maps_url`;
- facilities;
- price range/source;
- availability status;
- “perlu konfirmasi” label when indicative.

Required input:

- `cemetery_id`
- package/class when applicable.

### Pilih Jenis Layanan

Values:

- `NEW_GRAVE`
- `OVERLAPPING_GRAVE`
- `URGENT_TODAY`
- `PRE_NEED`

Rules:

- `OVERLAPPING_GRAVE` only selectable when cemetery/package supports it.
- `URGENT_TODAY` checks service area, operating hours, and capacity.
- `PRE_NEED` creates interest-only path while paid Pre-Need gate is closed.

### Pilih Layanan

Basic:

- `DOCUMENT_PROCESSING`
- `GRAVE_DIGGING`

Add-ons:

- `AMBULANCE`
- `FUNERAL_HOME`
- `HEARSE`
- `TENT_AND_CHAIRS`
- `SOUND_SYSTEM`
- `FLOWERS`
- `GRAVESTONE`
- `DOCUMENTATION`
- `CATERING`
- `LIVE_STREAMING`

Each line shows name, description, provider/fulfillment owner, price, availability, quantity/variant where relevant.

### Ringkasan sidebar

Not a step — a persistent display element that reflects the in-progress selection, not a numbered stop in the journey.

Display:

- city;
- cemetery;
- service type;
- selected package/class;
- basic services;
- add-ons;
- schedule;
- price line items;
- delivery/area fee;
- tax/other fee if applicable;
- total;
- exclusions;
- quote validity.

Price changes require explicit reconfirmation and new quote version.

## Step 2 — Data Pemesan & Data Almarhum

### Data Pemesan

Required:

- full name;
- mobile number;
- email;
- address;
- relationship to deceased;
- preferred contact channel;
- privacy notice acceptance.

Optional/conditional:

- identity number only when legally/operationally required;
- alternate contact;
- organization.

### Data Almarhum and Documents

Data:

- full name;
- date of birth;
- date of death;
- relationship to customer;
- gender/other administrative attributes only when required.

Documents:

- KTP;
- Kartu Keluarga;
- Surat Keterangan Kematian.

Behavior:

- private upload;
- type/size/content validation;
- malware quarantine;
- upload progress;
- retry;
- signed URL max five minutes;
- access audit.

## Step 3 — Pembayaran

Online mode:

- amount and merchant binding;
- hosted checkout;
- pending state;
- validated webhook;
- payment failure recovery.

Manual fallback mode:

- method/instructions;
- payment reference;
- optional proof upload;
- status `MENUNGGU_VERIFIKASI_PEMBAYARAN`;
- admin verification;
- no false success.

## Step 4 — Konfirmasi

Display:

- order reference;
- current status;
- invoice/receipt availability;
- email delivery status;
- WhatsApp delivery status or unavailable reason;
- admin/operator notification status where safe to display;
- next action;
- service/customer-support contact;
- order timeline link.

## Branching behavior

The UI retains the stakeholder’s four-step framing. Internal workflow may shorten operational data collection for Urgent or replace payment with interest registration for gated Pre-Need, but the user receives an explicit outcome and confirmation page.
