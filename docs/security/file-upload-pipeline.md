# Secure File Upload and Malware Quarantine Pipeline — v0.4

## 1. Scope

Applies to KTP, KK, death certificate, payment proof, agreements, certificates, vendor work evidence, product images, import files, and any future user-supplied file.

## 2. State machine

```text
INITIATED
-> UPLOADED_TO_QUARANTINE
-> VALIDATING
-> SCANNING
-> ACCEPTED_PRIVATE
or -> REJECTED
or -> QUARANTINED_FOR_REVIEW
or -> DELETED
```

No business workflow may treat a file as usable before `ACCEPTED_PRIVATE`, except an explicitly authorized security review.

## 3. Upload path

```text
client upload
-> private quarantine object/prefix
-> size and extension allowlist
-> MIME/content signature validation
-> archive/decompression-bomb limits
-> malware scan adapter
-> optional image metadata stripping/re-encoding
-> accepted private object/prefix
-> domain attachment created
-> audit and retention applied
```

## 4. Scanner implementation

Use a provider-neutral `MalwareScanner` interface. Implementation may be managed object-storage scanning or an isolated ClamAV-compatible scanner. Scanner outage fails closed for restricted documents.

```text
scan(fileReference) -> CLEAN | INFECTED | SUSPICIOUS | ERROR
```

## 5. Controls

- random opaque object key, never original filename as authority;
- private bucket/container and encryption at rest;
- content-length and actual decompressed-size limits;
- file type allowlist per document kind;
- no executable/script formats for identity documents;
- image processing isolated from web process;
- import parsers stream/chunk and enforce row/column/formula limits;
- rejected/infected file is not downloadable by ordinary admin/vendor/customer;
- scanner result, engine/version, hash, and time recorded;
- signed URL generated only after policy check and acceptance;
- deceased identity document URL maximum five minutes;
- all restricted access audited.

## 6. Download/display

Use `Content-Disposition: attachment` where inline rendering is unnecessary. Apply safe content type and browser headers. Never proxy raw untrusted content through a public permanent URL.

## 7. Failure behavior

- Scanner unavailable: keep in quarantine, queue retry, show pending state.
- Infected: reject, alert security/operations, preserve minimum evidence according to policy.
- Suspicious: restricted manual review.
- Timeout/duplicate upload: idempotently resume or create a new version; do not expose partial object.

## 8. Re-scan and lifecycle

Re-scan when scanner intelligence materially changes, before high-risk bulk release, or after incident. Quarantine/accepted/deleted retention follows data policy. Object deletion must coordinate with legal hold, financial/audit evidence, and domain record state.

## 9. Development/staging scanner profile

On the Ubuntu 22.04 2/4 combined host:

- development may use a deterministic mock scanner only for application-flow development;
- CI includes scanner-adapter and EICAR-style test evidence;
- staging release verification requires a real external or temporary isolated scanner path;
- restricted staging uploads remain quarantined when the real scanner is unavailable;
- permanent local MinIO and always-on ClamAV are excluded from the resource baseline.

Mock scanning is never production evidence.
