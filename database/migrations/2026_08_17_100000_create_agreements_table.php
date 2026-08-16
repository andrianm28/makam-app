<?php

declare(strict_types=1);

use App\Domain\AgreementCertificate\AgreementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `agreements` — Task 1 (Lane 1) of
 * `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`. One row
 * per AGREEMENT VERSION, mirroring `quotes`: supersession inserts a new
 * row and preserves the old one (AC5 — "WHEN a certificate/agreement is
 * reissued or replaced THE SYSTEM SHALL preserve its earlier history"),
 * and acceptance binds to a single version row (AC2).
 *
 * `subject_type` / `subject_id` hold the subject's fully-qualified class
 * name and primary key — the same no-morph-map convention as
 * `price_versions.priceable_type` (`App\Domain\ServiceCatalog\Models\
 * PriceVersion`'s class doc block records why this codebase has no
 * `Relation::morphMap()`). The design doc's "morph: order |
 * pre_need_case" is that class-name pair in this schema.
 *
 * `reference` is globally unique: the plan pins AC7's per-issuer/type
 * uniqueness for CERTIFICATES (`certificates_issuer_type_reference_unique`
 * below); `agreements` carries no issuer column, so its references are
 * unique outright. `(subject_type, subject_id, type, version_number)` is
 * the same "the database decides, not a read-then-write" version
 * discipline as `quotes_order_version_unique` — two concurrent writers
 * racing the same version collide on this pair.
 *
 * The AC4 display columns are the explicit approved strings the plan
 * names — `price_guarantee`, `cancellation_refund`, `transferability`,
 * `term`, `included_services`, `responsible_entity` — all nullable while
 * the draft is being prepared.
 *
 * The Postgres CHECK on `status` pins the column to the `AgreementStatus`
 * vocabulary, guarded to `pgsql` because SQLite cannot `ALTER TABLE ...
 * ADD CONSTRAINT` and remains the local/test driver — the same
 * convention as `orders.status`, `quotes.status`, and
 * `funeral_cases.status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('reference');

            // `App\Domain\AgreementCertificate\AgreementType`.
            $table->string('type', 64);

            // Unique per subject+type — see class doc block.
            $table->unsignedInteger('version_number');

            // `App\Domain\AgreementCertificate\AgreementStatus`.
            $table->string('status', 32);

            // Fully-qualified subject class name + key — see class doc
            // block. Plain strings, never an FK: the subject may be an
            // order or a pre-need case, and `orders` deliberately has no
            // inbound FK (the `funeral_cases` migration's doc block).
            $table->string('subject_type');
            $table->string('subject_id');

            // AC2 binding — captured at acceptance; see
            // `Actions\AcceptAgreement`.
            $table->string('accepted_by_ref')->nullable();
            $table->string('accepted_quote_id')->nullable();
            $table->string('accepted_agreement_version_id')->nullable();

            // AC4 display fields — explicit approved strings, nullable
            // while the draft is prepared.
            $table->string('price_guarantee')->nullable();
            $table->string('cancellation_refund')->nullable();
            $table->string('transferability')->nullable();
            $table->string('term')->nullable();
            $table->text('included_services')->nullable();
            $table->string('responsible_entity')->nullable();

            $table->timestamps();

            $table->unique('reference', 'agreements_reference_unique');
            $table->unique(
                ['subject_type', 'subject_id', 'type', 'version_number'],
                'agreements_subject_type_version_unique'
            );
            $table->index(['subject_type', 'subject_id'], 'agreements_subject_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (AgreementStatus $status): string => $status->value,
                AgreementStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE agreements ADD CONSTRAINT agreements_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
