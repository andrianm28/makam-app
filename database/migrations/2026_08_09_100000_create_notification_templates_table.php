<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned notification template catalogue. The event and recipient/channel
 * facts remain owned by `docs/contracts/notification-matrix.md`; this table
 * stores the current versioned rendering pointer for each matrix event.
 *
 * `active_version_id` is added before the versions table exists so the two
 * tables can be created in dependency order. Its foreign key is attached by
 * the following migration after the target table has been created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('event_name')->unique();
            $table->string('default_channel');
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->index('active_version_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_default_channel_check '.
                "CHECK (default_channel IN ('EMAIL', 'WA'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
