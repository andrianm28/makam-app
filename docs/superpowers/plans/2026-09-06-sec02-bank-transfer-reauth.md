# SEC-02 Bank-Transfer Re-Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permanent fix for finding SEC-02 (Critical) — Task 1.3 of `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`. The manual-payment destination bank account (`bank_transfer_*` site settings) could be changed by any of four back-office roles with no re-authentication, no reason, and no audit distinction from an ordinary copy-text edit — the only active payment destination while the online gate is closed.
**Architecture:** One Filament page (`EditSiteSettings`), one new sensitive audit action.
**Tech Stack:** Laravel 13, Filament 5, PHPUnit against PostgreSQL 18.
**Spec:** Audit finding SEC-02 — `report-data.json` and `https://claude.ai/code/artifact/d331e5e4-456b-444d-b2c4-771a3be7ff61`.

## Global Constraints

- **Never store a bank account value, or a hash of one, in `audit_events.metadata`.** `App\Platform\Audit\MetadataAllowlist`'s own doc block states the requirement verbatim: "No KTP, KK, death-certificate content, **bank detail**, credential, or full address in an audit payload." This is a real correction made mid-implementation to this task's original plan text, which had proposed exactly that.
- Authorization for the bank-transfer fields specifically follows `docs/security/rbac-matrix.md`'s "Payout/refund, incl. manual payment verification" row (Operator: No) — narrower than `MasterDataAdminAuthorizerContract`'s page-wide four-role gate, which still governs page access.
- Re-authentication follows the established `ReauthenticationGuard::assertFresh()` + redirect pattern (`App\Filament\Admin\Pages\FeatureGateAdmin::transitionGate()` is the closest precedent — a plain Page method, not a Filament Action).
- Tests run against real PostgreSQL 18.

---

### Task 1: Step-up guard, mandatory reason, and a distinct sensitive audit action

**Files:**
- Modify: `app/Platform/SiteSettings/SiteSettingsAuditActions.php` — add `BANK_TRANSFER_UPDATED`.
- Modify: `app/Platform/Audit/SensitiveActions.php` — add it to `ACTIONS`.
- Modify: `app/Filament/Admin/Resources/SiteSettings/Schemas/SiteSettingsForm.php` — add a `bank_transfer_change_reason` Textarea (never persisted to `site_settings`).
- Modify: `app/Filament/Admin/Resources/SiteSettings/Pages/EditSiteSettings.php` — restructure `save()`.
- Modify: `tests/Feature/SiteSettings/EditSiteSettingsSmokeTest.php`, `tests/Unit/Platform/Audit/SensitiveActionsTest.php` (its exact-list assertion, by design, requires every addition to arrive with a stated authority).

- [x] **Step 1: Restructure `save()` to peek before writing**
  Extracted `computeChangedKeys(): array` — a read-only diff against persisted values, computed BEFORE the transaction opens, so the gates below can run first and abort with nothing written.

- [x] **Step 2: Gate bank-transfer changes**
  `guardBankTransferChange(): bool` — role check (admin/restricted_admin/finance only), then `ReauthenticationGuard::assertFresh()` with the established redirect-on-stale pattern, then mandatory reason (`ValidationException`, inline field error — the admin is still mid-edit, unlike the two flow-stopping checks before it).
  **A real control-flow bug found and fixed during implementation**: an earlier draft had the guard throw a made-up exception class to unwind out of a private helper back to `save()`. Corrected to the established boolean-return shape (`GravePlotsTable::requireFreshAuthentication(): bool`) so `save()` itself does the `return;`.

- [x] **Step 3: Cross-field completeness**
  All three bank fields must be filled together or left empty together (`App\Support\BankTransferInfo`'s own doc block already treats them as one unit on the read side).

- [x] **Step 4: Tests, including a mutation-test pass**
  6 new/rewritten cases in `EditSiteSettingsSmokeTest.php`: ordinary fields save with no re-auth gate at all (unchanged UX); a fresh session + reason saves and audits as `BANK_TRANSFER_UPDATED` with the reason recorded and NO bank value anywhere in metadata; a stale session redirects with nothing written; an Operator is refused; a blank reason is rejected; a partial bank submission is rejected. Per this repo's mutation-testing discipline, the guard call was temporarily disabled and the two authorization/freshness tests were confirmed to actually fail before restoring the fix — not vacuous assertions.

## After all tasks: whole-branch verification

```bash
IMAGE=149ac33766fb
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint php "$IMAGE" vendor/bin/pint --test
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint php "$IMAGE" -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
bash ci/verify-docs.sh
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:RKxTuGlM4MNUB65volwGUsTfCiDumShAS0GGdu5zXn4= \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<port> -e DB_DATABASE=testdb \
  -e DB_USERNAME=testuser -e DB_PASSWORD=testpass \
  --entrypoint php "$IMAGE" vendor/bin/phpunit \
  tests/Unit/Platform/Audit/SensitiveActionsTest.php \
  tests/Feature/SiteSettings/EditSiteSettingsSmokeTest.php \
  tests/Feature/Filament/SiteSettingsResourceTest.php
```

Result (this pass): pint clean (1743 files), phpstan 0 errors, verify-docs all 13 gates pass, 34/34 tests green against real Postgres 18.
