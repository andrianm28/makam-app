# Threat Model — v0.2

| Threat | Impact | Controls |
|---|---|---|
| Premature payment | Charge without service/plot | confirmation/reservation + quote + authorization guard |
| Double plot reservation | Severe customer/operational harm | authoritative identity, atomic lock, unique active reservation, reconciliation |
| Stale inventory | Sold/unavailable plot | freshness SLO, disable reservation, fallback confirmation |
| Unauthorized plot override | Fraud/dispute | privileged permission, reason, maker/checker where possible, audit |
| Urgent accepted without capacity | Harm to vulnerable family | area/time capacity gate, case owner, escalation |
| Orphaned case task | Service failure | accountable owner, deadlines, handover and overdue alerts |
| Forged/duplicate webhook | False paid/duplicate records | signature, replay, idempotency, unique constraints |
| Certificate fraud/overwrite | Legal/consumer harm | issuer role, unique numbering, immutable versions, revoke/replace events |
| Grave data scraping | Privacy misuse | projection, rate limit, anti-enumeration, monitoring |
| Memorial impersonation/privacy breach | Harm/reputation | authority evidence, moderation, private default, unpublish/token revoke |
| QR enumeration | Data exposure | opaque token, rate limit, revocation |
| Cross-operator/vendor access | Privacy/commercial breach | query-level scoping and negative tests |
| Malicious upload/import | Malware/data corruption | scan/quarantine, row validation, quotas, batch approval |
| Unauthorized payout/refund | Financial loss | separation, re-auth, maker/checker, proof, audit |
| Land rights listing abuse | Fraud/title dispute | feature disabled; dedicated verification/escrow/legal gate |
| Pre-Need liability misuse | Long-term loss/regulatory breach | legal gate, separate product/accounting/contract |
| Audit tampering | Evidence loss | external append-only K8, restricted access |
