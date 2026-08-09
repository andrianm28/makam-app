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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')
                ->constrained('notification_templates')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('subject')->nullable();
            $table->text('body');
            $table->jsonb('variable_allowlist');
            $table->jsonb('restricted_fields');
            $table->string('created_by');
            $table->timestamp('created_at');

            $table->unique(
                ['template_id', 'version'],
                'notification_template_versions_template_version_unique'
            );
        });

        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->foreign('active_version_id', 'notification_templates_active_version_fk')
                ->references('id')
                ->on('notification_template_versions')
                ->nullOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_template_versions ADD CONSTRAINT notification_template_versions_json_arrays_check '.
                "CHECK (jsonb_typeof(variable_allowlist) = 'array' AND jsonb_typeof(restricted_fields) = 'array')"
            );
        }
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropForeign('notification_templates_active_version_fk');
        });

        Schema::dropIfExists('notification_template_versions');
    }
};
