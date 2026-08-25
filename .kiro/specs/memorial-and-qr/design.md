# Design — Memorial and QR

## Authority

Obeys this spec's own `requirements.md` (AC1–AC8), `docs/architecture/overview.md` §5 (module
boundary) and §6 (capability profile vocabulary), `docs/governance/assumptions-and-gates.md`
(gate `G-MEM-01`), and `docs/contracts/event-catalog.md`. No other document is cited as authority
here, so there is no conflict to resolve.

## Module boundary

Owned by **Memorial** (`overview.md` §5: "Optional memorial profile, QR token, privacy and
moderation"). All tables below belong to this module; none is borrowed from or lent to another.

## Components

`MemorialProfile` (privacy mode, lifecycle), `MemorialEditor` (authority/consent-gated roles),
`MemorialContent` + `MemorialMedia` (family-authored, moderated), `MemorialQrToken` (opaque,
revocable), `ModerationCase` (report intake, moderator action).

## Data

```text
memorial_profiles(id, grave_record_id, privacy_mode, published_at, unpublished_at)
memorial_editors(id, memorial_profile_id, actor_id, consent_evidence_ref, granted_at)
memorial_contents(id, memorial_profile_id, body, moderation_state)
memorial_media(id, memorial_profile_id, storage_ref, scan_state)
memorial_qr_tokens(id, memorial_profile_id, token, revoked_at, rotated_at)
moderation_cases(id, memorial_profile_id, reported_content_type, reported_content_id, status)
abuse_reports(id, moderation_case_id, reporter_ref, reason)
```

**AC7 boundary:** `memorial_profiles.grave_record_id` is the only link to `GraveRegistry`. The
memorial lifecycle (privacy_mode, published_at) never writes back to the grave record, and no
grave-record field is copied into a memorial table — the two lifecycles stay independent, per AC7.

**AC4 boundary:** `memorial_qr_tokens.token` is a random opaque value, never derived from
`memorial_profile_id` or any other identifier — deriving it would make the token guessable
against a known profile, which is exactly the enumeration risk AC4 exists to close.

## Sequence — QR resolve, gate-checked

```mermaid
sequenceDiagram
    actor V as Visitor
    participant A as Application
    participant G as FeatureGate
    V->>A: GET /m/{token}
    A->>G: isOpen('G-MEM-01')
    alt gate open
        A->>A: look up token (not revoked, not rotated-away)
        alt token valid AND profile.privacy_mode = public
            A-->>V: allowlisted public projection (AC3)
        else token invalid/revoked/private
            A-->>V: generic "not available" — same response for revoked,
                     private, and never-existed (AC5 negative criterion:
                     never reveal which case applies)
        end
    else gate closed
        A-->>V: memorial feature disabled, no token lookup attempted
    end
```

Both branches matter: the gate-closed branch means a closed `G-MEM-01` must not even attempt a
token lookup, since a lookup that returns "gate closed" for a valid token and "not found" for an
invalid one would itself leak which tokens exist.

## Error handling

- **Revoked/rotated token** (AC5): responds identically to a token that never existed. Rotation
  writes `rotated_at` and mints a new `memorial_qr_tokens` row rather than mutating the old one in
  place — the old physical QR code fails the same way a forgery would, not with a distinguishable
  error.
- **Cross-family / unauthorized editor access** (AC1, AC8's negative criterion): denial response
  must not disclose whether a `memorial_profile_id` exists at all. Same shape as
  `App\Domain\CemeteryDirectory\CemeteryPublicQuery::findPublishedBySlug()`'s "draft and unknown
  are indistinguishable" precedent (see that class's own doc block) — this spec should follow the
  same pattern when built, not invent a new one.
- **Media scan pending/failed**: `memorial_media.scan_state` is fail-closed — a scan that hasn't
  completed or has failed is never previewable, matching `tasks.md`'s §6.7 requirement.
- **Moderation action on already-unpublished content**: idempotent — re-unpublishing a profile or
  re-revoking an already-revoked token is a no-op success, not an error, so a moderator retrying
  after an ambiguous response never gets stuck.

## Events

`memorial.unpublished.v1` (already catalogued, Producer: Memorial, Consumer: Public read/QR) is
the event this spec emits on AC5's moderator unpublish action. **Gap, surfaced rather than
guessed at:** `event-catalog.md` has no catalogued event for a memorial being *published*, or for
QR token rotation/revocation specifically — only the unpublish direction exists today. A future
implementation batch needs either a real `memorial.published.v1` / `memorial.token_rotated.v1`
addition to the catalogue, or a documented decision that those transitions are read-model-only and
never need to be events. Not decided here — this design does not invent a permanent event name to
fill the gap.

## Technology stack

No new infrastructure. Uses the existing Laravel/Postgres/Redis baseline, `FeatureGateResolver`
for `G-MEM-01` (server-side only — a front-end flag is never the enforcement point), and the same
media-scan pipeline other upload surfaces use (§6.7 states, `tasks.md`'s own note).

## Testing strategy

Per AC-to-test mapping: privacy-mode default and consent-gated editor access (AC1) via a real
seeded profile in each mode; token enumeration resistance (AC4) via asserting a random guess never
resolves; moderator unpublish + token rotation (AC5) via a full lifecycle test asserting the old
token 404s identically to a token that never existed; moderation/report reachability (AC6) via a
route test on the public projection; AC7's boundary via a static/reflection check that no memorial
table column duplicates a grave-record field. Matches `makam-testing`'s no-factory,
real-seeded-row convention — no fixture invents a memorial that could pass for a real one, mirroring
the `Contoh`-prefix convention `grave_records`' own seed migration already established.

## Explicitly not covered

- No screen-inventory ID exists for this spec (`mvp-scope.md` §8 excludes Memorial/QR from MVP,
  already noted in `tasks.md`) — this design does not assign UI routes; that follows only if
  `G-MEM-01` opens.
- Retention/deletion policy under AC8 is named as a requirement but not designed here — it depends
  on the approved policy `AC8` defers to, which does not exist yet as a cited document. Flagged,
  not guessed at.
- The publish/token-rotation event-catalogue gap above is surfaced, not resolved.
