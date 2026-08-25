<?php

declare(strict_types=1);

use App\Platform\Payment\ProviderEventStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $statuses = implode("', '", ProviderEventStatus::values());

        DB::statement('ALTER TABLE provider_events DROP CONSTRAINT IF EXISTS provider_events_status_check');
        DB::statement(
            'ALTER TABLE provider_events ADD CONSTRAINT provider_events_status_check '.
            "CHECK (status IN ('{$statuses}'))"
        );
    }

    public function down(): void
    {
        // The additive status must remain available for already-deployed
        // schemas; removing it would make a Task 4 PROCESSING row invalid.
    }
};
