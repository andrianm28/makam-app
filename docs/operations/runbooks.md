# Operations Runbooks — v0.3

## 1. Urgent/At-Need cannot be fulfilled

1. Close gate for affected area/time.
2. Identify active FuneralCases and assign incident owner.
3. Contact families with truthful alternatives; do not show automated success.
4. Escalate operator/vendor/capacity issue.
5. Preserve communications and decisions.
6. Reactivate only after readiness review.

## 2. Case manager unavailable or handover required

1. Assign authorized replacement.
2. Record handover, open critical tasks, deadlines, contacts, and unresolved risks.
3. Notify required parties.
4. Verify no overdue task is orphaned.

## 3. Operator silent

Use configured manual channel, record person/time/evidence, and preserve admin fallback. Track repeated adoption failure.

## 4. Plot inventory stale/degraded

1. Disable new plot reservations for affected cemetery.
2. Keep package/class request-confirmation flow available where safe.
3. Protect active reservations; reconcile source status.
4. Contact owners of conflicting/stale cases.
5. Reactivate after freshness and reconciliation pass.

## 5. Double-reservation suspicion

1. Freeze affected plot and new payment creation.
2. Compare reservation locks, source system, quotes, payments, and audit.
3. Do not delete history or select a winner automatically.
4. Escalate to operations/legal and contact affected families carefully.
5. Resolve through approved compensating/reassignment process.

## 6. Reservation expires during payment

1. Do not assume payment or plot success.
2. Verify provider transaction and reservation state.
3. If paid but plot unavailable, enter financial/operational exception queue.
4. No automatic substitute without customer acceptance.

## 7. Payment webhook delayed/failing

Inspect durable receipt, signature failures, queue, workers, journal/invoice references. Never manually set paid; replay only through idempotent mechanism.

## 8. Certificate delayed, duplicate, or incorrect

1. Separate order/payment completion from certificate status.
2. Freeze duplicate number or incorrect version.
3. Issue replacement/revocation event per authority SOP; never overwrite.
4. Notify customer and preserve delivered copies/audit.

## 9. Memorial privacy complaint

1. Unpublish/disable QR token immediately when authorized by incident policy.
2. Preserve content, consent, access, and moderation evidence privately.
3. Review authority and field exposure.
4. Restore only after approval.

## 10. Grave data/search/import incident

Disable misleading functionality if dataset unavailable; preserve batch errors; retry idempotently; reconcile deduplication/source identity before publication.

## 11. Vendor payout unavailable

Disable auto payout, retain payable records, use approved manual maker/checker transfer and proof, reconcile on recovery.

## 12. Sensitive document exposure

Revoke URLs/sessions, preserve audit, scope impact, rotate credentials if needed, and follow privacy/security incident procedure.

## 13. Backup restore

Declare recovery point, restore DB/object/external references, verify audit and finance consistency, run authorization/payment/reservation smoke tests, and record actual RTO/RPO.


## MVP channel and fallback runbook

### Online payment unavailable

1. Set server-side payment mode to `MANUAL_COORDINATION`.
2. Preserve Step 8 and show approved payment instructions.
3. Create admin verification task.
4. Never display payment success before approval.
5. Notify affected users through email/in-app.
6. Reconcile all pending manual references before returning to online mode.

### WhatsApp unavailable

1. Mark channel unavailable in notification configuration.
2. Continue email and in-app delivery.
3. Do not retry indefinitely or block order state.
4. Display accurate delivery status where shown.

### Grave data unavailable

1. Disable automated search through feature gate.
2. Display explanatory state on renewal flow.
3. Offer manual data entry or customer-service assistance.
4. Preserve submitted request and source evidence.

### Vendor does not respond

1. Escalate to admin after configured deadline.
2. Contact vendor through fallback channel.
3. Offer customer an updated ETA, replacement, or cancellation path according to policy.
4. Audit all decisions and communication.

## Combined host memory pressure / OOM

1. Stop development and batch workers first.
2. Pause imports, media, reports, and non-critical schedulers.
3. Check container memory, PostgreSQL connections, Redis memory, PHP-FPM children, and swap.
4. Preserve staging critical/urgent queues and authoritative database state.
5. Restart only the failed non-financial process after evidence capture.
6. Do not clear Redis queues or delete outbox rows as a memory workaround.
7. If normal memory remains above 80%, swap persists, or OOM repeats, upgrade to 4 vCPU/8 GB or split development and staging.
8. Record incident and capacity decision.
