# Traceability Matrix - Platform Document Vault

`Closed (local evidence)` means the implementation and named tests exist and
pass locally; CI PostgreSQL evidence is still pending. `NOT TESTED` is reserved
for provider, role, or external-channel behavior that cannot be exercised on
the combined development host.

| AC | Requirement | Test evidence | Status |
|---|---|---|---|
| AC1 | Private quarantine and no direct accepted write | `tests/Feature/DocumentVault/UploadDocumentTest.php`; `tests/Unit/Platform/DocumentVault/Adapters/LocalFilesystemObjectStorageTest.php` | Closed (local evidence) |
| AC2 | No use/download/preview before acceptance | `tests/Feature/DocumentVault/DownloadDocumentTest.php`; `tests/Feature/DocumentVault/DocumentStateViewTest.php` | Closed (local evidence) |
| AC3 | Type, content, size, and extension validation | `tests/Unit/Platform/DocumentVault/DocumentValidatorTest.php` | Closed (local evidence) |
| AC4 | Scanner fail-closed behavior | `tests/Unit/Platform/DocumentVault/Adapters/MockScannerTest.php`; `tests/Feature/DocumentVault/DocumentLifecycleTest.php` | Closed (local evidence) |
| AC5 | Auditable accepted transition | `tests/Feature/DocumentVault/DocumentLifecycleTest.php`; `tests/Feature/DocumentVault/RecordDocumentAccessTest.php` | Closed (local evidence) |
| AC6 | Purpose-scoped URL with five-minute maximum | `tests/Feature/DocumentVault/IssueSignedUrlTest.php` | Closed (local evidence) |
| AC7 | No URL before accepted state | `tests/Feature/DocumentVault/IssueSignedUrlTest.php` | Closed (local evidence) |
| AC8 | Access audit for grant and use | `tests/Feature/DocumentVault/IssueSignedUrlTest.php`; `tests/Feature/DocumentVault/RecordDocumentAccessTest.php`; `tests/Feature/DocumentVault/DownloadDocumentTest.php` | Closed (local evidence) |
| AC9 | Role and relationship authorization | `tests/Feature/DocumentVault/DocumentAccessPolicyTest.php`; `tests/Feature/DocumentVault/DownloadDocumentTest.php` | Closed (local evidence) |
| AC10 | Private encrypted S3-compatible storage | `tests/Unit/Platform/DocumentVault/DocumentVaultConfigurationTest.php`; `tests/Unit/Platform/DocumentVault/Task8DocumentVaultTest.php` | NOT TESTED: real S3-compatible provider |
| AC11 | Retention and deletion policy | `tests/Feature/DocumentVault/DocumentLifecycleTest.php` | Closed (local evidence) |
| AC12 | No external-channel attachments | - | NOT TESTED: external-channel integration is outside this lane |
| AC13 | Progress, cancellation, and retry state contract | `tests/Feature/DocumentVault/DocumentStateViewTest.php` | State contract covered locally; consuming Livewire/browser cancellation, retry, and parent-draft preservation NOT TESTED |
| AC14 | Grave import uses the quarantine pipeline | `tests/Feature/DocumentVault/UploadDocumentTest.php` | Closed (local evidence) |

## Task 8 Evidence

- `tests/Unit/Platform/DocumentVault/Task8DocumentVaultTest.php` verifies the
  configured provider bindings, revoke SQL statements, and event catalog rows.
- PostgreSQL application-role UPDATE/DELETE execution is explicitly skipped
  until a distinct application role exists; it must not be reported as PASS.
- The exact role list remains `admin`, `operator`, `case_manager`, `customer`.
- Actor roles remain fail-closed; no actor-role seam is introduced here.
