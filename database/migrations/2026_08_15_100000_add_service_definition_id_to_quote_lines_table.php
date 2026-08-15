<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0 reconciliation — dual quote-line types on `quote_lines` (the ruling:
 * `IssueQuote` accepts service-version lines alongside the existing
 * package-version lines, because the booking wizard quotes individual
 * SERVICES, not packages).
 *
 * `service_definition_id` is the new column: for a service line, the
 * authoritative reference to the quoted `ServiceDefinition`, written once
 * at issuance and frozen (restrict FK, same as the existing two
 * references). It is nullable only because package lines leave it blank.
 *
 * `service_package_version_id` becomes nullable: a service line has no
 * package version, so the existing NOT NULL constraint must relax to let
 * service lines through (expand/contract — no existing row is changed,
 * and package lines keep populating it as before).
 *
 * `price_version_id` stays NOT NULL and shared by both line types: the
 * append-only price snapshot is the frozen amount anchor regardless of
 * which family the line belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->foreignId('service_definition_id')
                ->nullable()
                ->after('quote_id')
                ->constrained('service_definitions')
                ->restrictOnDelete();
            $table->foreignId('service_package_version_id')->nullable()->change();

            $table->index('service_definition_id');
        });
    }

    public function down(): void
    {
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->dropIndex(['service_definition_id']);
            $table->dropForeign(['service_definition_id']);
            $table->dropColumn('service_definition_id');
            $table->foreignId('service_package_version_id')->nullable(false)->change();
        });
    }
};
