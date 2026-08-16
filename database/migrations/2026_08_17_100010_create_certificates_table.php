<?php

declare(strict_types=1);

use App\Domain\AgreementCertificate\CertificateStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `certificates` — Task 1 (Lane 1) of
 * `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`. One row
 * per CERTIFICATE VERSION; replacement supersedes the incumbent's status
 * and inserts the next version, so the earlier rows are preserved (AC5).
 *
 * AC7 — "THE SYSTEM SHALL enforce document-number uniqueness per issuer
 * and type" — is a database constraint
 * (`certificates_issuer_type_reference_unique`) rather than an
 * application convention. `Actions\IssueCertificate` generates
 * `'CERT-'.Str::upper(Str::random(8))` references; the index is the
 * backstop a concurrent race or a hostile collision lands on, and the
 * Action's narrow `QueryException` classifier translates exactly that
 * violation into `CertificateReferenceCollisionException` (the
 * `OrderAlreadyPaidException` pattern) without ever chaining the raw
 * exception — whose message interpolates the full INSERT and its
 * bindings — into the log.
 *
 * `(subject_type, subject_id, type, version_number)` mirrors
 * `agreements_subject_type_version_unique`: the version sequence is a
 * database property, never a PHP `MAX()+1` race.
 *
 * `document_id` is a nullable reference to the vault `documents` row the
 * certificate is backed by. It is deliberately NOT a foreign key: the
 * vault's retention cleanup may remove a `documents` row (after the
 * DELETED state and approved deletion) whose certificate must survive as
 * history, and no existing migration constrains to `documents` — the
 * vault's own owner references are plain strings (`owner_type` /
 * `owner_id`). Usability is enforced at the application layer:
 * `Actions\IssueCertificate` / `Actions\ReplaceCertificate` refuse any
 * document that is not `DocumentState::Accepted` — a Quarantined,
 * Scanning, or Rejected document can never be referenced by an issued
 * certificate (the plan's "honest refusal").
 *
 * `issued_by_role` records the issuer's role on the row itself — the
 * plan's pinned fillable list omits it, but `CertificateStatusView`
 * (AC6) must expose "issued_by role" per certificate, and a status view
 * cannot invent a role the row does not carry. Mirrors
 * `quotes.issued_by_ref` / `quotes.issued_by_role`.
 *
 * The Postgres CHECK on `status` pins the column to the `CertificateStatus`
 * vocabulary, guarded to `pgsql` for the same reason as the sibling
 * migrations' CHECKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('reference');

            // `App\Domain\AgreementCertificate\CertificateType`.
            $table->string('type', 64);

            // Unique per subject+type — see class doc block.
            $table->unsignedInteger('version_number');

            // `App\Domain\AgreementCertificate\CertificateStatus`.
            $table->string('status', 32);

            // Fully-qualified subject class name + key — same convention
            // as `agreements.subject_type` / `subject_id`.
            $table->string('subject_type');
            $table->string('subject_id');

            $table->string('issued_by_ref');
            $table->string('issued_by_role', 64);
            $table->timestamp('effective_at');

            // Vault document reference — see class doc block.
            $table->uuid('document_id')->nullable();

            $table->timestamps();

            $table->unique(
                ['issued_by_ref', 'type', 'reference'],
                'certificates_issuer_type_reference_unique'
            );
            $table->unique(
                ['subject_type', 'subject_id', 'type', 'version_number'],
                'certificates_subject_type_version_unique'
            );
            $table->index(['subject_type', 'subject_id', 'status'], 'certificates_subject_status_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (CertificateStatus $status): string => $status->value,
                CertificateStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE certificates ADD CONSTRAINT certificates_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
