<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable render snapshots for `notification_templates`. JSON arrays are
 * deliberately data rather than a closed PHP/DB enum: the matrix and privacy
 * policy own the names of allowed and restricted fields.
 *
 * Version rows use restrictive ownership/deletion semantics. A composite
 * active-version foreign key proves that the pointer's version belongs to the
 * same template, while PostgreSQL and SQLite triggers close builder/raw UPDATE
 * and DELETE paths that cannot be seen by Eloquent model events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')
                ->constrained('notification_templates')
                ->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('subject')->nullable();
            $table->text('body');
            $table->jsonb('variable_allowlist');
            $table->jsonb('restricted_fields');
            $table->string('created_by');
            $table->timestamp('created_at');

            $table->unique(
                ['id', 'template_id'],
                'notification_template_versions_id_template_unique'
            );
        });

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX notification_template_versions_template_version_unique '.
                'ON notification_template_versions (template_id, version) '.
                'WHERE version IS NOT NULL'
            );
        } else {
            Schema::table('notification_template_versions', function (Blueprint $table): void {
                $table->unique(
                    ['template_id', 'version'],
                    'notification_template_versions_template_version_unique'
                );
            });
        }

        Schema::table('notification_templates', function (Blueprint $table): void {
            // The template id is carried in the same FK so an active version
            // cannot be borrowed from another template.
            $table->foreign(
                ['active_version_id', 'id'],
                'notification_templates_active_version_ownership_fk'
            )->references(['id', 'template_id'])
                ->on('notification_template_versions')
                ->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_template_versions ADD CONSTRAINT notification_template_versions_json_arrays_check '.
                "CHECK (jsonb_typeof(variable_allowlist) = 'array' AND jsonb_typeof(restricted_fields) = 'array')"
            );
        }

        $this->createVersionImmutabilityGuards($driver);
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropForeign('notification_templates_active_version_ownership_fk');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS notification_template_versions_immutable ON notification_template_versions; '.
                'DROP FUNCTION IF EXISTS notification_template_versions_prevent_mutation();'
            );
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS notification_template_versions_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS notification_template_versions_immutable_delete');
        }

        DB::statement('DROP INDEX IF EXISTS notification_template_versions_template_version_unique');
        Schema::dropIfExists('notification_template_versions');
    }

    private function createVersionImmutabilityGuards(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION notification_template_versions_prevent_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $function$
                BEGIN
                    RAISE EXCEPTION 'notification_template_versions rows are immutable';
                END;
                $function$;

                CREATE TRIGGER notification_template_versions_immutable
                BEFORE UPDATE OR DELETE ON notification_template_versions
                FOR EACH ROW
                EXECUTE FUNCTION notification_template_versions_prevent_mutation();
                SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER notification_template_versions_immutable_update
                BEFORE UPDATE ON notification_template_versions
                BEGIN
                    SELECT RAISE(ABORT, 'notification_template_versions rows are immutable');
                END;
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER notification_template_versions_immutable_delete
                BEFORE DELETE ON notification_template_versions
                BEGIN
                    SELECT RAISE(ABORT, 'notification_template_versions rows are immutable');
                END;
                SQL);
        }
    }
};
