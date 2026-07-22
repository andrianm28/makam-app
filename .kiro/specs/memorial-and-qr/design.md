# Design — Memorial and QR

Entities: `memorial_profiles`, `memorial_editors`, `memorial_contents`, `memorial_media`, `memorial_qr_tokens`, `moderation_cases`, `abuse_reports`.

Public read model is generated from allowlisted fields. Tokens are random, revocable, rate-limited, and monitored for enumeration.
