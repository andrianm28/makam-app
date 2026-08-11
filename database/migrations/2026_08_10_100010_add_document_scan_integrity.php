<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Binds each scan attempt to the exact bytes presented to the scanner and
 * installs database-level append-only enforcement where PostgreSQL is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_scans', function (Blueprint $table): void {
            // Nullable keeps this expand step compatible with any historical
            // attempts; new ScanDocument writes always provide the digest.
            $table->string('checksum_sha256', 64)->nullable();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE document_scans ADD CONSTRAINT document_scans_checksum_sha256_check '.
            "CHECK (checksum_sha256 IS NULL OR checksum_sha256 ~ '^[0-9a-fA-F]{64}$')"
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION document_scans_reject_mutation()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $function$
BEGIN
    RAISE EXCEPTION 'document_scans is append-only';
END;
$function$;

REVOKE EXECUTE ON FUNCTION document_scans_reject_mutation() FROM PUBLIC;
REVOKE UPDATE, DELETE ON TABLE document_scans FROM PUBLIC;

CREATE TRIGGER document_scans_append_only
BEFORE UPDATE OR DELETE ON document_scans
FOR EACH ROW
EXECUTE FUNCTION document_scans_reject_mutation();

ALTER TABLE document_scans ENABLE ALWAYS TRIGGER document_scans_append_only;
SQL);
    }

    public function down(): void
    {
        // Forward-only production rollback preserves scan evidence.
    }
};
