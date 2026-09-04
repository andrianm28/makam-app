<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One nullable, indexed `demo_batch_id` (uuid) column added to every table
 * the demo seed-data subsystem writes to
 * (docs/superpowers/specs/2026-09-03-demo-seed-data-design.md, decision 1).
 * Nullable and inert for every real row — a temporary, beta-era marker, not
 * a permanent architectural decision. `cemeteries` already has real example
 * cemeteries identified by slug (`PurgeExampleDataCommand`); this column is
 * added there too so any NEW demo-specific cemetery this subsystem creates
 * (for the cemetery-operator account's scope grant) is independently
 * purgeable without touching the existing slug-based mechanism.
 */
return new class extends Migration
{
    private const array TABLES = [
        'booking_drafts', 'orders', 'renewals', 'care_plans', 'subscriptions',
        'agreements', 'certificates', 'vendors', 'vendor_users',
        'marketplace_orders', 'vendor_orders', 'visitation_bookings',
        'users', 'cemeteries', 'cemetery_visitation_policies',
        'actor_role_assignments', 'scope_assignments',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('demo_batch_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('demo_batch_id');
            });
        }
    }
};
