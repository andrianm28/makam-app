<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `external_certificate_references` — Task 1 (Lane 1) of
 * `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`, the
 * AC8 record: when a manual, EXTERNAL certificate (issued by a cemetery
 * or other authority, not by this platform) is referenced, it is recorded
 * here — a row of this table IS the "not platform-issued" flag, so every
 * display of one can and must mark it external
 * (`Models\ExternalCertificateReference::isExternal()`).
 *
 * `(issuer_ref, type, reference)` is unique: the same external document
 * cannot be recorded twice, and the constraint mirrors the platform
 * certificates' own AC7 backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_certificate_references', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('issuer_ref');
            $table->string('reference');
            $table->string('type', 64);

            // Fully-qualified subject class name + key — same convention
            // as the sibling tables.
            $table->string('subject_type');
            $table->string('subject_id');

            $table->timestamps();

            $table->unique(
                ['issuer_ref', 'type', 'reference'],
                'external_certificate_references_issuer_type_reference_unique'
            );
            $table->index(['subject_type', 'subject_id'], 'external_certificate_references_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_certificate_references');
    }
};
