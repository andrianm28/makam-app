-- NOT executed automatically by any migration, seeder, artisan
-- command, or CI job. This file documents the statements to run manually,
-- once the environment has a distinct application role and migration role.
--
-- platform-document-vault requirements 8/11: document_access_events is
-- append-only. The migration role owns schema changes; the application role
-- may insert and read access records but must not UPDATE or DELETE them.
--
-- The current development/staging database setup provisions one role that
-- owns the database and runs the application. Applying this revoke to that
-- owner role would not create the intended boundary. Replace <app_role> only
-- after the environment's non-owner application role exists.

-- 1. Remove mutation privileges from the application role.
REVOKE UPDATE, DELETE ON document_access_events FROM <app_role>;

-- 2. Retain the privileges required to record and inspect access events.
GRANT SELECT, INSERT ON document_access_events TO <app_role>;

-- 3. Apply this per table. Do not use ALTER DEFAULT PRIVILEGES: the
-- application role must retain normal mutation rights on unrelated tables.

-- 4. Verify as the application role, not the migration/owner role.
-- SET ROLE <app_role>;
-- UPDATE document_access_events SET outcome = 'denied' WHERE id = 1;
-- -- expected: ERROR: permission denied for table document_access_events
-- DELETE FROM document_access_events WHERE id = 1;
-- -- expected: ERROR: permission denied for table document_access_events
-- RESET ROLE;
