# ADR-0006: Feature Flags for Gated Capabilities

- **Status:** Accepted from RKS

## Decision

Capabilities dependent on legal, data, operational, adoption, or provider activation are protected by server-side feature gates. UI visibility alone is insufficient.

Examples: Pre-Need payment, Urgent, online payment, grave search/reminder, WhatsApp, vendor payout, tokenization.

## Consequences

Flags need owner, activation evidence, audit, rollback, and environment-specific configuration.
