# Requirements — Memorial and QR

**Status:** Optional/privacy-gated P2/B07.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. THE SYSTEM SHALL default a memorial to private, and THE SYSTEM SHALL require authority/consent evidence before granting editor access or publication.
2. THE SYSTEM SHALL support privacy modes: private, family-only, unlisted, and public.
3. THE SYSTEM SHALL render the public projection using an explicit field/media allowlist.
4. WHEN a QR code is resolved THE SYSTEM SHALL use an opaque, revocable token, and THE SYSTEM SHALL NOT embed a restricted identifier.
5. THE SYSTEM SHALL allow an authorized moderator to immediately unpublish a memorial and rotate or revoke its token.
6. THE SYSTEM SHALL moderate user-generated messages/media, and THE SYSTEM SHALL make them reportable.
7. THE SYSTEM SHALL keep the memorial lifecycle separate from the authoritative grave record.
8. WHEN deletion or retention is applied THE SYSTEM SHALL follow approved policy while preserving audit/evidence as required.
