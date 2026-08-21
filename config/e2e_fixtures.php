<?php

declare(strict_types=1);

/**
 * `seed_admin_vendor_users` exists for exactly one caller:
 * `database/migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php`,
 * which `RefreshDatabase` applies once per PHPUnit process for EVERY Feature
 * test, not just the E2E-ADMIN/VENDOR browser suite it exists for. Left
 * unconditional, that migration's real `GrantActorRole`/`GrantScopeAssignment`
 * writes permanently pollute `actor_role_assignments`/`scope_assignments`/
 * `audit_events` for every unrelated test in the same process — confirmed the
 * hard way 21 Aug 2026: 8+ pre-existing tests broke, including
 * `PurgeStaleBookingDraftsTest` and `ServiceCatalogAuditIntegrationTest`, both
 * of which assert exact/zero counts on those tables.
 *
 * Deliberately NOT gated on `app()->environment('testing')` — that value is
 * ALSO what `phpunit.xml` sets for every ordinary PHPUnit run
 * (`<env name="APP_ENV" value="testing"/>`), so an `environment('testing')`
 * gate would silently seed these rows for every ordinary PHPUnit run too,
 * defeating the tests above. This is the exact same collision
 * `THROTTLE_PUBLIC_GUEST_DISABLED` (`config/rate_limiting.php`) already hit
 * and solved with a dedicated, default-false env flag — same fix shape
 * applied here. This flag is scoped to nothing but the one CI step
 * (`.github/workflows/ci.yml`'s browser-test job, "Serve the app and run the
 * browser smoke test") that explicitly opts in.
 */
return [
    'seed_admin_vendor_users' => (bool) env('SEED_E2E_ADMIN_VENDOR_USERS', false),
];
