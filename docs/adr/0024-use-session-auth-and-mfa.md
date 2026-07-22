# ADR-0024: Use Session Authentication and Mandatory Privileged MFA

## Status
Accepted — 23 July 2026

## Decision
Use Laravel same-origin session authentication for MVP. Require TOTP MFA for privileged roles and recent re-authentication for sensitive actions. Do not add OAuth/JWT/Passport without mobile/partner API requirements.

## Consequences
Minimum complexity and strong browser security; API clients require a future scoped authentication decision.
